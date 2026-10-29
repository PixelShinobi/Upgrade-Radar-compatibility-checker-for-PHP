<?php
/**
 * Runs PHP_CodeSniffer in-process, one file at a time.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Phpcs;

use ErrorException;
use PHP_CodeSniffer\Autoload;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Util\Standards;
use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Internal\Cache;
use PHPCSUtils\Internal\NoFileCache;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Throwable;
use UpgradeRadar\Scanner\Guards\FalseAlarms;
use UpgradeRadar\Scanner\Issue;
use UpgradeRadar\Scanner\ScannerUnavailable;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Loads PHP_CodeSniffer the first time a file is scanned, never before, and
 * never through exec() or another process.
 *
 * Everything PHP_CodeSniffer and PHPCompatibility keep in static state is
 * set here, so scans for different target versions can share one process.
 */
final class Runtime {

	/**
	 * Packages that hold the coding standards the ruleset uses.
	 */
	private const STANDARD_PACKAGES = array(
		'phpcompatibility/php-compatibility',
		'phpcompatibility/phpcompatibility-paragonie',
		'phpcompatibility/phpcompatibility-wp',
		'phpcsstandards/phpcsutils',
	);

	/**
	 * PHP_CodeSniffer messages about the scan itself rather than about the code.
	 * These two are harmless; any other "Internal." message means the file
	 * couldn't be scanned properly.
	 */
	private const HARMLESS_INTERNAL_MESSAGES = array(
		'Internal.LineEndings.Mixed',
		'Internal.NoCodeFound',
	);

	/**
	 * The Composer vendor directory with PHP_CodeSniffer and the rulesets.
	 *
	 * @var string
	 */
	private $vendor_dir;

	/**
	 * Path to the ruleset XML file.
	 *
	 * @var string
	 */
	private $ruleset_file;

	/**
	 * The PHP_CodeSniffer configuration, once loaded.
	 *
	 * @var IsolatedConfig|null
	 */
	private $config;

	/**
	 * The loaded ruleset, once loaded.
	 *
	 * @var Ruleset|null
	 */
	private $ruleset;

	/**
	 * Creates a runtime. Nothing is loaded until the first scan.
	 *
	 * @param string $vendor_dir   The Composer vendor directory.
	 * @param string $ruleset_file Path to the ruleset XML file.
	 */
	public function __construct( string $vendor_dir, string $ruleset_file ) {
		$this->vendor_dir   = rtrim( $vendor_dir, '/\\' );
		$this->ruleset_file = $ruleset_file;
	}

	/**
	 * Scans one file.
	 *
	 * @param string        $path   Absolute path of the file. The caller checks it's allowed.
	 * @param TargetVersion $target The PHP version to check against.
	 * @return Issue[] With possible false alarms marked.
	 * @throws ScannerUnavailable If PHP_CodeSniffer can't be loaded.
	 * @throws RuntimeException If this file can't be scanned.
	 */
	public function scan( string $path, TargetVersion $target ): array {
		$this->load();
		$this->use_test_version( $target->test_version() );

		$file = new CompatibilityFile( $path, $this->ruleset, $this->config );
		try {
			$this->quietly(
				static function () use ( $file ) {
					$file->process();
				}
			);

			// The tokens are only around until cleanUp().
			return FalseAlarms::mark( $file->getTokens(), $this->issues( $file ), $target );
		} finally {
			$file->cleanUp();

			// PHPCSUtils caches results per file name and never lets go of them
			// outside phpcbf, which adds up to about 100 KB per file scanned.
			Cache::clear();
			NoFileCache::clear();
		}
	}

	/**
	 * Loads PHP_CodeSniffer, the standards and the ruleset, once per process.
	 *
	 * @throws ScannerUnavailable If something is missing or another copy of
	 *                            PHP_CodeSniffer is already loaded.
	 */
	private function load(): void {
		if ( null !== $this->ruleset ) {
			return;
		}

		$autoload = $this->vendor_dir . '/squizlabs/php_codesniffer/autoload.php';
		$utils    = $this->vendor_dir . '/phpcsstandards/phpcsutils/phpcsutils-autoload.php';
		if ( ! is_readable( $autoload ) || ! is_readable( $utils ) ) {
			throw ScannerUnavailable::broken_install( sprintf( 'PHP_CodeSniffer is missing from %s.', $this->vendor_dir ) );
		}

		require_once $autoload;
		require_once $utils;
		$this->require_own_copy( Autoload::class, 'squizlabs/php_codesniffer' );
		$this->require_own_copy( Cache::class, 'phpcsstandards/phpcsutils' );

		// PHP_CodeSniffer expects its runner to define these.
		if ( ! defined( 'PHP_CODESNIFFER_VERBOSITY' ) ) {
			define( 'PHP_CODESNIFFER_VERBOSITY', 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by PHP_CodeSniffer.
		}
		if ( ! defined( 'PHP_CODESNIFFER_CBF' ) ) {
			define( 'PHP_CODESNIFFER_CBF', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by PHP_CodeSniffer.
		}

		try {
			$this->quietly(
				function () {
					// Defines PHP_CodeSniffer's own token constants.
					new Tokens();

					$this->config = new IsolatedConfig(
						array(
							'--standard=' . $this->ruleset_file,
							'--ignore-annotations',
							'--no-cache',
							'-q',
						)
					);

					$installed_paths = array();
					foreach ( self::STANDARD_PACKAGES as $package ) {
						$installed_paths[] = $this->vendor_dir . '/' . $package;
					}
					// Temporary, so PHP_CodeSniffer never writes its CodeSniffer.conf file.
					Config::setConfigData( 'installed_paths', implode( ',', $installed_paths ), true );

					foreach ( Standards::getInstalledStandardDetails() as $standard ) {
						Autoload::addSearchPath( $standard['path'], $standard['namespace'] );
					}

					$this->ruleset = new Ruleset( $this->config );
				}
			);
		} catch ( Throwable $e ) {
			$this->ruleset = null;
			throw ScannerUnavailable::broken_install( 'PHP_CodeSniffer could not load the ruleset: ' . trim( $e->getMessage() ), $e );
		}
	}

	/**
	 * Makes PHPCompatibility check against a testVersion.
	 *
	 * The setting is global, so it's applied before every file: another
	 * Runtime in the same process may have changed it in between.
	 *
	 * @param string $test_version Such as "7.4-8.3".
	 * @throws ScannerUnavailable If PHP_CodeSniffer refuses the setting.
	 */
	private function use_test_version( string $test_version ): void {
		Config::setConfigData( 'testVersion', $test_version, true );
		if ( Config::getConfigData( 'testVersion' ) !== $test_version ) {
			throw ScannerUnavailable::broken_install( 'PHP_CodeSniffer did not accept the testVersion setting.' );
		}

		// PHPCompatibility reads the testVersion once and keeps it for the rest of the process.
		try {
			foreach ( array( 'testVersion', 'testVersions' ) as $name ) {
				$cached = new ReflectionProperty( 'PHPCompatibility\Helpers\ScannedCode', $name );
				if ( PHP_VERSION_ID < 80100 ) {
					$cached->setAccessible( true );
				}
				$cached->setValue( null, null );
			}
		} catch ( ReflectionException $e ) {
			throw ScannerUnavailable::broken_install( 'This PHPCompatibility version is not supported: ' . $e->getMessage(), $e );
		}
	}

	/**
	 * Converts the messages PHP_CodeSniffer recorded into issues.
	 *
	 * @param CompatibilityFile $file A processed file.
	 * @return Issue[]
	 * @throws RuntimeException If PHP_CodeSniffer couldn't scan the file properly.
	 */
	private function issues( CompatibilityFile $file ): array {
		$issues  = array();
		$by_type = array(
			Issue::ERROR   => $file->getErrors(),
			Issue::WARNING => $file->getWarnings(),
		);

		foreach ( $by_type as $type => $lines ) {
			foreach ( $lines as $line => $columns ) {
				foreach ( $columns as $column => $messages ) {
					foreach ( $messages as $message ) {
						$source = (string) $message['source'];
						if ( 0 === strpos( $source, 'Internal.' ) ) {
							if ( in_array( $source, self::HARMLESS_INTERNAL_MESSAGES, true ) ) {
								continue;
							}
							throw new RuntimeException( (string) $message['message'] );
						}

						$issues[] = new Issue( (int) $line, (int) $column, $type, (string) $message['message'], $source );
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Runs a callback without letting PHP_CodeSniffer print anything, and turns
	 * PHP warnings into exceptions, the way the phpcs command does.
	 *
	 * Deprecation notices are dropped: PHP_CodeSniffer itself can trigger them
	 * on newer PHP versions, and they say nothing about the scanned code.
	 *
	 * @param callable $callback The work to do.
	 */
	private function quietly( callable $callback ): void {
		$buffer_level = ob_get_level();
		ob_start();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Restored below.
			static function ( int $severity, string $message, string $file = '', int $line = 0 ): bool {
				// Reads the level only, to respect the @ operator.
				$reported = error_reporting(); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
				if ( 0 === ( $reported & $severity ) || E_DEPRECATED === $severity || E_USER_DEPRECATED === $severity ) {
					return true;
				}
				throw new ErrorException( $message, 0, $severity, $file, $line ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output.
			}
		);

		try {
			$callback();
		} finally {
			restore_error_handler();
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Makes sure a class comes from our vendor directory and not from another
	 * plugin's copy, which could be a different and incompatible version.
	 *
	 * @param string $class_name A class to load.
	 * @param string $package    The Composer package it should come from.
	 * @throws ScannerUnavailable If the class comes from somewhere else.
	 */
	private function require_own_copy( string $class_name, string $package ): void {
		$expected = realpath( $this->vendor_dir . '/' . $package );
		$actual   = class_exists( $class_name ) ? ( new ReflectionClass( $class_name ) )->getFileName() : false;

		if ( false === $expected ) {
			throw ScannerUnavailable::broken_install( sprintf( '%s is missing from %s.', $package, $this->vendor_dir ) );
		}

		if ( false === $actual || 0 !== strpos( (string) realpath( $actual ), $expected . DIRECTORY_SEPARATOR ) ) {
			throw ScannerUnavailable::other_copy_loaded( $package, false === $actual ? '' : $actual );
		}
	}
}

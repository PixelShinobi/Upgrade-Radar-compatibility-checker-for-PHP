<?php
/**
 * The scanning engine. Free of WordPress functions.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use Throwable;
use UpgradeRadar\Scanner\Phpcs\Runtime;

/**
 * Checks PHP files against a target PHP version with PHPCompatibilityWP,
 * running PHP_CodeSniffer in-process. It only reads files, and only inside
 * the directory it's given.
 */
final class Scanner implements FileScanner {

	/**
	 * The pinned rule packages, for reports. RulesetTest checks they match composer.lock.
	 */
	public const RULES = array(
		'phpcompatibility/phpcompatibility-wp' => '3.0.0-alpha2',
		'phpcompatibility/php-compatibility'   => '10.0.0-alpha2',
	);

	/**
	 * PHP extensions PHP_CodeSniffer can't work without.
	 */
	public const REQUIRED_EXTENSIONS = array( 'simplexml', 'tokenizer' );

	/**
	 * Files larger than this are never scanned.
	 */
	public const DEFAULT_MAX_FILE_SIZE = 2097152;

	/**
	 * Files larger than this get their tokens counted before scanning, to
	 * check the server has the memory.
	 */
	private const TOKEN_CHECK_SIZE = 65536;

	/**
	 * Peak memory PHP_CodeSniffer needs per token, rounded up from measurements
	 * of 550 to 910 bytes on very different files.
	 */
	private const BYTES_PER_TOKEN = 1024;

	/**
	 * Memory needed to count tokens, per byte of the file: up to 80 measured.
	 */
	private const TOKEN_COUNT_BYTES_PER_BYTE = 100;

	/**
	 * Finds the files to scan.
	 *
	 * @var FileFinder
	 */
	private $finder;

	/**
	 * Runs PHP_CodeSniffer.
	 *
	 * @var Runtime
	 */
	private $runtime;

	/**
	 * Files larger than this, in bytes, are skipped.
	 *
	 * @var int
	 */
	private $max_file_size;

	/**
	 * Creates a scanner. PHP_CodeSniffer isn't loaded until the first scan.
	 *
	 * @param array<string, mixed> $options {
	 *     Optional settings.
	 *
	 *     @type string   $vendor_dir    Composer vendor directory with PHP_CodeSniffer
	 *                                   and the rulesets. Defaults to this plugin's.
	 *     @type int      $max_file_size Files larger than this, in bytes, are skipped.
	 *     @type string[] $extensions    File extensions to scan.
	 * }
	 */
	public function __construct( array $options = array() ) {
		$options = array_merge(
			array(
				'vendor_dir'    => dirname( __DIR__, 2 ) . '/vendor',
				'max_file_size' => self::DEFAULT_MAX_FILE_SIZE,
				'extensions'    => FileFinder::DEFAULT_EXTENSIONS,
			),
			$options
		);

		$this->finder        = new FileFinder( (array) $options['extensions'] );
		$this->runtime       = new Runtime( (string) $options['vendor_dir'], self::ruleset_file() );
		$this->max_file_size = (int) $options['max_file_size'];
	}

	/**
	 * Path to the PHP_CodeSniffer ruleset the scanner uses.
	 *
	 * @return string
	 */
	public static function ruleset_file(): string {
		return __DIR__ . '/ruleset.xml';
	}

	/**
	 * Lists PHP extensions the scanner needs but this server lacks.
	 *
	 * @return string[]
	 */
	public static function missing_extensions(): array {
		return array_values(
			array_filter(
				self::REQUIRED_EXTENSIONS,
				static function ( string $extension ): bool {
					return ! extension_loaded( $extension );
				}
			)
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path A plugin or theme directory, or a single-file plugin.
	 * @return array{root: string, files: string[]}
	 */
	public function find_files( string $path ): array {
		return $this->finder->find( $path );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string        $root          The directory returned by find_files().
	 * @param string        $relative_path A path returned by find_files().
	 * @param TargetVersion $target        The PHP version to check against.
	 * @return FileReport
	 * @throws ScannerUnavailable If the scanner can't run at all.
	 */
	public function scan_file( string $root, string $relative_path, TargetVersion $target ): FileReport {
		$missing = self::missing_extensions();
		if ( array() !== $missing ) {
			throw new ScannerUnavailable( sprintf( 'These PHP extensions are missing: %s.', implode( ', ', $missing ) ), ScannerUnavailable::MISSING_EXTENSIONS ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text, escaped where it's shown.
		}

		$real_root = realpath( $root );
		$real_path = false;
		if ( false !== $real_root && '' !== $relative_path && false === strpos( $relative_path, "\0" ) ) {
			$real_path = realpath( $real_root . DIRECTORY_SEPARATOR . $relative_path );
		}

		if ( false === $real_root || false === $real_path ) {
			return FileReport::failed( $relative_path, FileReport::REASON_UNREADABLE, 'The file no longer exists or cannot be read.' );
		}

		if ( 0 !== strpos( $real_path, rtrim( $real_root, '/\\' ) . DIRECTORY_SEPARATOR ) ) {
			return FileReport::skipped( $relative_path, FileReport::REASON_OUTSIDE_ROOT, 'The path leads outside the plugin or theme directory.' );
		}

		$size = is_file( $real_path ) && is_readable( $real_path ) ? filesize( $real_path ) : false;
		if ( false === $size ) {
			return FileReport::failed( $relative_path, FileReport::REASON_UNREADABLE, 'The file no longer exists or cannot be read.' );
		}

		$too_large = $this->too_large( $real_path, $size );
		if ( null !== $too_large ) {
			return FileReport::skipped( $relative_path, FileReport::REASON_TOO_LARGE, $too_large );
		}

		try {
			return FileReport::scanned( $relative_path, $this->runtime->scan( $real_path, $target ) );
		} catch ( ScannerUnavailable $e ) {
			throw $e;
		} catch ( Throwable $e ) {
			return FileReport::failed( $relative_path, FileReport::REASON_SCAN_ERROR, trim( $e->getMessage() ) );
		}
	}

	/**
	 * Explains why a file is too large to scan safely, if it is.
	 *
	 * Running out of memory is a fatal error nothing can catch, so this
	 * estimates the memory a scan needs first. ScanJob skips a file that
	 * kills the process anyway.
	 *
	 * @param string $path Absolute path of the file.
	 * @param int    $size File size in bytes.
	 * @return string|null A sentence explaining why, or null if the file can be scanned.
	 */
	private function too_large( string $path, int $size ): ?string {
		if ( $size > $this->max_file_size ) {
			return sprintf( 'The file is %s, more than the %s limit.', self::format_bytes( $size ), self::format_bytes( $this->max_file_size ) );
		}

		$available = self::available_memory();
		if ( $size <= self::TOKEN_CHECK_SIZE || null === $available ) {
			return null;
		}

		if ( $size * self::TOKEN_COUNT_BYTES_PER_BYTE > $available ) {
			return sprintf( 'The file is %s, too large for the memory this server allows.', self::format_bytes( $size ) );
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- No WordPress here.
		$needed   = count( token_get_all( (string) $contents ) ) * self::BYTES_PER_TOKEN;
		unset( $contents );

		// Leave a fifth of the free memory for everything else.
		if ( $needed > $available * 0.8 ) {
			return sprintf(
				'Scanning this file needs about %s of memory, and this server has %s free.',
				self::format_bytes( $needed ),
				self::format_bytes( $available )
			);
		}

		return null;
	}

	/**
	 * Memory this process may still allocate, in bytes.
	 *
	 * @return int|null Null when there is no memory limit.
	 */
	public static function available_memory(): ?int {
		$limit = strtolower( trim( (string) ini_get( 'memory_limit' ) ) );
		$bytes = (int) $limit;
		$units = array(
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		);
		$unit  = substr( $limit, -1 );
		if ( isset( $units[ $unit ] ) ) {
			$bytes *= $units[ $unit ];
		}

		if ( $bytes <= 0 ) {
			return null;
		}

		return max( 0, $bytes - memory_get_usage( true ) );
	}

	/**
	 * Formats a byte count for people, such as "1.5 MB".
	 *
	 * @param int $bytes Bytes.
	 * @return string
	 */
	private static function format_bytes( int $bytes ): string {
		if ( $bytes >= 1048576 ) {
			return round( $bytes / 1048576, 1 ) . ' MB';
		}

		return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}
}

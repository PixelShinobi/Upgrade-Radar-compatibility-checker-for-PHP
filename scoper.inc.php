<?php
/**
 * PHP-Scoper configuration for the release build, bin/build-release.
 *
 * Prefixes PHP_CodeSniffer, PHPCSUtils and PHPCompatibility with
 * UpgradeRadarVendor\, so our copy can't clash with another plugin's.
 * bin/build-release passes the prefix. Development and tests use the plain
 * vendor directory.
 *
 * Only vendor/ goes through PHP-Scoper, which reprints the files it prefixes.
 * In our own code, bin/prefix-scanner.php prefixes the few names src/Scanner
 * uses and leaves the rest as it is.
 *
 * With PHP_CodeSniffer 3.13.6 and PHPCompatibility 10.0.0-alpha2, the fixtures
 * give the same findings as the plain build, and scans still work after
 * another copy of PHP_CodeSniffer has been loaded in the same process.
 *
 * @package UpgradeRadar
 */

use Isolated\Symfony\Component\Finder\Finder;

// PHP_CodeSniffer files that only exist to run other programs.
const UPGRADE_RADAR_EXTERNAL_PROGRAMS = '#^squizlabs/php_codesniffer/src/(Reports/(Gitblame|Svnblame|Hgblame|VersionControl|Notifysend)\.php|Filters/(GitModified|GitStaged|ExactMatch)\.php|Standards/[^/]+/Sniffs/Debug/|Standards/Generic/Sniffs/PHP/SyntaxSniff\.php)#';

return array(
	// The packages' tests, docs and scripts aren't needed to scan. Nor is the
	// CodeSniffer.conf Composer writes: scans set the paths themselves.
	//
	// Nor are the PHP_CodeSniffer parts that run other programs: the blame
	// and notify-send reports, the Git file filters, and the sniffs that call
	// outside linters or `php -l`. Scans never ask for them, and leaving them
	// out keeps exec() and the like out of the release zip.
	'finders'                 => array(
		Finder::create()->files()->in( 'vendor' )->exclude( array( 'bin', 'composer', 'dealerdirect' ) )->notPath( '#/(Tests?|tests|docs|scripts)/#' )->notPath( UPGRADE_RADAR_EXTERNAL_PROGRAMS )->notName( 'CodeSniffer.conf' ),
	),
	// PHP_CodeSniffer's token constants (T_NONE and so on) must stay global.
	'expose-global-constants' => true,
	'patchers'                => array(
		// PHP_CodeSniffer builds some class names from strings, such as
		// 'PHP_CodeSniffer\Tokenizers\\' . $type, which PHP-Scoper can't see.
		static function ( string $file_path, string $prefix, string $content ): string {
			if ( false === strpos( $file_path, 'squizlabs/php_codesniffer/' ) ) {
				return $content;
			}

			return str_replace(
				array( "'PHP_CodeSniffer\\", '"PHP_CodeSniffer\\', "'\\PHP_CodeSniffer\\" ),
				array( "'" . $prefix . '\\PHP_CodeSniffer\\', '"' . $prefix . '\\PHP_CodeSniffer\\', "'\\" . $prefix . '\\PHP_CodeSniffer\\' ),
				$content
			);
		},

		// Another plugin's copy may have defined the token constants already.
		// PHP_CodeSniffer 3.13 and 4.0 give them the same values.
		static function ( string $file_path, string $prefix, string $content ): string {
			if ( 'src/Util/Tokens.php' !== substr( $file_path, -19 ) ) {
				return $content;
			}

			return (string) preg_replace( "/^define\\('(T_\\w+)',/m", "\\defined('$1') || define('$1',", $content );
		},

		// The few calls to other programs left in the files scans load, each
		// for the phpcs command only, become the fallback the code already
		// has: the default report width, no external tool, no terminal on
		// STDIN, and no diff. bin/build-release fails if any call is left.
		static function ( string $file_path, string $prefix, string $content ): string {
			$calls = array(
				'src/Config.php'      => array(
					"\$dimensions = shell_exec('stty size 2>&1');" => '$dimensions = null;',
					'$result = exec($cmd, $output, $retVal);'      => '$result = null; $retVal = 1;',
				),
				'src/Util/Common.php' => array(
					'$tty = trim(shell_exec("{$which} tty 2> {$devnull}"));' => "\$tty = '';",
					'exec("tty -s 2> {$devnull}", $output, $returnValue);'   => '$returnValue = 1;',
				),
				'src/Fixer.php'       => array(
					'$process = proc_open($cmd, $descriptorspec, $pipes, $cwd, null, $options);' => '$process = false;',
				),
			);

			foreach ( $calls as $suffix => $replacements ) {
				$target = 'squizlabs/php_codesniffer/' . $suffix;
				if ( substr( $file_path, -strlen( $target ) ) !== $target ) {
					continue;
				}

				foreach ( $replacements as $search => $replace ) {
					if ( 1 !== substr_count( $content, $search ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- A build error in a terminal, not HTML.
						throw new RuntimeException( "Can't find this call in $target: $search" );
					}
					$content = str_replace( $search, $replace, $content );
				}
			}

			return $content;
		},
	),
);

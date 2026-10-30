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

return array(
	// The packages' tests, docs and scripts aren't needed to scan. Nor is the
	// CodeSniffer.conf Composer writes: scans set the paths themselves.
	'finders'                 => array(
		Finder::create()->files()->in( 'vendor' )->exclude( array( 'bin', 'composer', 'dealerdirect' ) )->notPath( '#/(Tests?|tests|docs|scripts)/#' )->notName( 'CodeSniffer.conf' ),
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
	),
);

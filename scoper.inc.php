<?php
/**
 * PHP-Scoper configuration for the release build (milestone 3).
 *
 * Prefixes PHP_CodeSniffer, PHPCSUtils and PHPCompatibility with
 * UpgradeRadarVendor\, so our copy can't clash with another plugin's.
 * Development and tests use the plain vendor directory.
 *
 * Build steps, in a clean copy of the plugin:
 * 1. composer install --no-dev
 * 2. php-scoper add-prefix --output-dir=build (PHP-Scoper needs PHP 8.2+ to run)
 * 3. Copy vendor/composer and composer.json to build/. In build/composer.json,
 *    add the vendor directories of those packages to autoload.classmap (they
 *    don't declare an autoloader Composer can use) and set
 *    config.autoloader-suffix to UpgradeRadarScoped.
 * 4. composer dump-autoload --no-dev --classmap-authoritative --working-dir=build
 *
 * Tried Oct 29, 2026 with PHP_CodeSniffer 3.13.6 and PHPCompatibility
 * 10.0.0-alpha2: the fixtures gave the same findings as the plain build, and
 * scans still worked after another copy of PHP_CodeSniffer had been loaded
 * in the same process.
 *
 * @package UpgradeRadar
 */

use Isolated\Symfony\Component\Finder\Finder;

return array(
	'prefix'                  => 'UpgradeRadarVendor',
	// WordPress core classes must stay global: the WordPress-side code
	// (src/Jobs) type-hints WP_Theme, and prefixing it makes the scoped
	// build fatal the moment a scan starts, because no
	// UpgradeRadarVendor\WP_Theme ever exists. Only the scanner engine's
	// vendor stack may be prefixed. If src ever references another
	// WordPress core class, it has to be added here.
	'exclude-classes'         => array( 'WP_Theme' ),
	// Same for WordPress functions named in function_exists() strings:
	// prefixing 'get_plugins' would make those checks test a function
	// that never exists. (The two call sites would survive it — they
	// only guard a require_once — but the string must stay truthful.)
	'exclude-functions'       => array( 'get_plugins' ),
	'finders'                 => array(
		Finder::create()->files()->in( 'src' ),
		Finder::create()->files()->in( 'vendor' )->exclude( array( 'bin', 'composer', 'dealerdirect' ) )->notPath( '#/(Tests|tests|docs)/#' ),
	),
	'exclude-namespaces'      => array( 'UpgradeRadar' ),
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

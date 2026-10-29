<?php
/**
 * Plugin Name:       Upgrade Radar – compatibility checker for PHP
 * Description:       Checks whether your active plugins and theme are ready for a newer PHP version, with a plain-English verdict for each.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PixelShinobi
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       upgrade-radar
 *
 * @package UpgradeRadar
 */

defined( 'ABSPATH' ) || exit;

// Our own classes only. PHP_CodeSniffer and the rulesets load during a scan
// batch, never on other requests, so they can't clash with other plugins.
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( 0 !== strpos( $class_name, 'UpgradeRadar\\' ) ) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( 'UpgradeRadar\\' ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

UpgradeRadar\Plugin::boot( __FILE__ );

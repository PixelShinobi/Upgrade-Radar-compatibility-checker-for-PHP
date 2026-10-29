<?php
/**
 * Lists what a scan covers.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Jobs;

use UpgradeRadar\Plugin;
use WP_Theme;

/**
 * The active plugins, the active theme and its parent theme, and the
 * directories scans may read.
 */
final class Inventory {

	public const PLUGIN       = 'plugin';
	public const THEME        = 'theme';
	public const PARENT_THEME = 'parent_theme';

	/**
	 * The items to scan, keyed by an ID such as "plugin:akismet/akismet.php"
	 * or "theme:twentytwentyfive". Network-activated plugins count as active.
	 * Upgrade Radar leaves itself out.
	 *
	 * @return array<string, array{type: string, key: string, name: string, version: string, path: string}>
	 */
	public static function active(): array {
		$items   = array();
		$plugins = self::installed_plugins();

		foreach ( self::active_plugin_files() as $file ) {
			if ( isset( $plugins[ $file ] ) && 0 === validate_file( $file ) ) {
				$items[ 'plugin:' . $file ] = self::plugin( $file, $plugins[ $file ] );
			}
		}

		$theme = wp_get_theme();
		if ( $theme->exists() ) {
			$items[ 'theme:' . $theme->get_stylesheet() ] = self::theme( $theme, self::THEME );

			$parent = $theme->parent();
			if ( $parent instanceof WP_Theme && $parent->exists() ) {
				$items[ 'theme:' . $parent->get_stylesheet() ] = self::theme( $parent, self::PARENT_THEME );
			}
		}

		return array_filter(
			$items,
			static function ( array $item ): bool {
				return self::is_allowed( $item['path'] );
			}
		);
	}

	/**
	 * The current version of a plugin or theme, or "" if it's gone.
	 *
	 * @param string $type One of the type constants.
	 * @param string $key  Plugin file or theme directory name.
	 * @return string
	 */
	public static function current_version( string $type, string $key ): string {
		if ( self::PLUGIN === $type ) {
			$plugins = self::installed_plugins();
			return isset( $plugins[ $key ] ) ? (string) $plugins[ $key ]['Version'] : '';
		}

		$theme = wp_get_theme( $key );

		return $theme->exists() ? (string) $theme->get( 'Version' ) : '';
	}

	/**
	 * Whether a path is inside the plugins directory or a theme directory,
	 * the only places scans may read.
	 *
	 * @param string $path Absolute path.
	 * @return bool
	 */
	public static function is_allowed( string $path ): bool {
		global $wp_theme_directories;

		$path  = wp_normalize_path( $path );
		$roots = array_merge( array( WP_PLUGIN_DIR ), (array) $wp_theme_directories );

		// No way back out, such as "plugins/.." or "plugins/x/../..".
		if ( preg_match( '#(^|/)\.\.(/|$)#', $path ) ) {
			return false;
		}

		foreach ( $roots as $root ) {
			if ( 0 === strpos( $path, trailingslashit( wp_normalize_path( (string) $root ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Files of the active plugins, as stored in "active_plugins".
	 *
	 * @return string[]
	 */
	private static function active_plugin_files(): array {
		$files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$files = array_merge( $files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$files = array_unique( array_map( 'strval', $files ) );

		return array_values( array_diff( $files, array( plugin_basename( Plugin::file() ) ) ) );
	}

	/**
	 * Installed plugins, keyed by plugin file.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * An item for a plugin.
	 *
	 * @param string                $file Plugin file, such as "akismet/akismet.php".
	 * @param array<string, string> $data Header data from get_plugins().
	 * @return array{type: string, key: string, name: string, version: string, path: string}
	 */
	private static function plugin( string $file, array $data ): array {
		$main = wp_normalize_path( WP_PLUGIN_DIR . '/' . $file );
		$dir  = dirname( $main );
		$name = (string) $data['Name'];

		return array(
			'type'    => self::PLUGIN,
			'key'     => $file,
			'name'    => '' !== $name ? $name : $file,
			'version' => (string) $data['Version'],
			// Single-file plugins such as hello.php sit directly in the plugins directory.
			'path'    => wp_normalize_path( WP_PLUGIN_DIR ) === $dir ? $main : $dir,
		);
	}

	/**
	 * An item for a theme.
	 *
	 * @param WP_Theme $theme The theme.
	 * @param string   $type  THEME or PARENT_THEME.
	 * @return array{type: string, key: string, name: string, version: string, path: string}
	 */
	private static function theme( WP_Theme $theme, string $type ): array {
		$name = (string) $theme->get( 'Name' );

		return array(
			'type'    => $type,
			'key'     => $theme->get_stylesheet(),
			'name'    => '' !== $name ? $name : $theme->get_stylesheet(),
			'version' => (string) $theme->get( 'Version' ),
			'path'    => wp_normalize_path( $theme->get_stylesheet_directory() ),
		);
	}
}

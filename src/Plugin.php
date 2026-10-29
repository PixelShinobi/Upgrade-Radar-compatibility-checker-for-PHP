<?php
/**
 * Wires Upgrade Radar into WordPress.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar;

use UpgradeRadar\Admin\Actions;
use UpgradeRadar\Admin\Ajax;
use UpgradeRadar\Admin\Csv;
use UpgradeRadar\Admin\Page;
use UpgradeRadar\Jobs\Runner;

/**
 * The plugin's name, version and hooks.
 */
final class Plugin {

	/**
	 * The display name. The plugin header repeats it, as headers can't use constants.
	 */
	public const NAME = 'Upgrade Radar – compatibility checker for PHP';

	/**
	 * The short name, for the menu.
	 */
	public const MENU_TITLE = 'Upgrade Radar';

	public const VERSION = '0.1.0';

	/**
	 * Path of the main plugin file.
	 *
	 * @var string
	 */
	private static $file = '';

	/**
	 * Registers the hooks.
	 *
	 * @param string $file Path of the main plugin file.
	 */
	public static function boot( string $file ): void {
		self::$file = $file;

		add_action( 'admin_menu', array( Page::class, 'register' ) );
		add_action( 'wp_ajax_upgrade_radar_step', array( Ajax::class, 'step' ) );
		add_action( 'admin_post_upgrade_radar_start', array( Actions::class, 'start' ) );
		add_action( 'admin_post_upgrade_radar_cancel', array( Actions::class, 'cancel' ) );
		add_action( 'admin_post_upgrade_radar_resume', array( Actions::class, 'resume' ) );
		add_action( 'admin_post_upgrade_radar_csv', array( Csv::class, 'download' ) );
		add_action( Runner::CRON_HOOK, array( Runner::class, 'run_from_cron' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( $file ), array( self::class, 'action_links' ) );

		register_deactivation_hook( $file, array( Runner::class, 'unschedule' ) );
	}

	/**
	 * Path of the main plugin file.
	 *
	 * @return string
	 */
	public static function file(): string {
		return self::$file;
	}

	/**
	 * Adds a link to the scan page on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( Page::url() ), esc_html__( 'Scan', 'upgrade-radar' ) ) );
		}

		return $links;
	}
}

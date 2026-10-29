<?php
/**
 * The admin page's form actions.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use InvalidArgumentException;
use UpgradeRadar\Jobs\Runner;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Starts, cancels and resumes scans. These are plain form posts, so they
 * work without JavaScript; the scan then runs on WP-Cron.
 */
final class Actions {

	/**
	 * Starts a scan for the chosen PHP version.
	 */
	public static function start(): void {
		check_admin_referer( 'upgrade_radar_start' );
		self::require_permission();

		$version = isset( $_POST['target'] ) ? sanitize_text_field( wp_unslash( $_POST['target'] ) ) : '';
		try {
			Runner::start( TargetVersion::from_string( $version ) );
		} catch ( InvalidArgumentException $e ) {
			wp_die( esc_html__( 'Choose one of the listed PHP versions.', 'upgrade-radar' ), '', array( 'back_link' => true ) );
		}

		self::back();
	}

	/**
	 * Cancels the scan.
	 */
	public static function cancel(): void {
		check_admin_referer( 'upgrade_radar_cancel' );
		self::require_permission();
		Runner::cancel();
		self::back();
	}

	/**
	 * Resumes a paused scan.
	 */
	public static function resume(): void {
		check_admin_referer( 'upgrade_radar_resume' );
		self::require_permission();
		Runner::resume();
		self::back();
	}

	/**
	 * Stops unless the user may run scans.
	 */
	private static function require_permission(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'upgrade-radar' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Returns to the admin page.
	 */
	private static function back(): void {
		wp_safe_redirect( Page::url() );
		exit;
	}
}

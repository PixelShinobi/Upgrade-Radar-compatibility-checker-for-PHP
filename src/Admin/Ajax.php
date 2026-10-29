<?php
/**
 * The admin page's AJAX endpoint.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use UpgradeRadar\Jobs\Runner;

/**
 * Runs one scan batch per call while the admin page is open.
 */
final class Ajax {

	/**
	 * Runs a batch and reports progress.
	 */
	public static function step(): void {
		check_ajax_referer( 'upgrade_radar_step', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}

		$result = Runner::run_batch( true );
		wp_send_json_success( Progress::summary( $result['scan'], $result['busy'] ) );
	}
}

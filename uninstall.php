<?php
/**
 * Removes Upgrade Radar's data when the plugin is deleted.
 *
 * @package UpgradeRadar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'upgrade_radar_scan' );
delete_option( 'upgrade_radar_lock' );
wp_clear_scheduled_hook( 'upgrade_radar_scan_batch' );

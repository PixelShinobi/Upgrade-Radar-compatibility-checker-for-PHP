<?php
/**
 * Keeps the scan between requests.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Jobs;

/**
 * Stores the scan in an option, and makes sure only one request works on it
 * at a time: the admin page's AJAX calls and WP-Cron could otherwise run
 * batches at once and overwrite each other's progress.
 */
final class Store {

	public const OPTION = 'upgrade_radar_scan';
	public const LOCK   = 'upgrade_radar_lock';

	/**
	 * The stored scan.
	 *
	 * @param bool $fresh Read it from the database, not from this request's cache.
	 * @return array<string, mixed>|null
	 */
	public static function get( bool $fresh = false ): ?array {
		if ( $fresh ) {
			wp_cache_delete( self::OPTION, 'options' );
		}

		$scan = get_option( self::OPTION );

		return is_array( $scan ) ? $scan : null;
	}

	/**
	 * Stores the scan. It's only needed on Upgrade Radar's own requests, so
	 * it isn't autoloaded.
	 *
	 * @param array<string, mixed> $scan The scan.
	 */
	public static function save( array $scan ): void {
		update_option( self::OPTION, $scan, false );
	}

	/**
	 * Deletes the scan.
	 */
	public static function delete(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Takes the lock, unless another request holds it.
	 *
	 * Locks bypass the object cache: they only work when every request sees
	 * the same row.
	 *
	 * @param int $seconds How long the lock lasts if the request dies without releasing it.
	 * @return string A token to pass to holds_lock() and unlock(), or "" if the lock is taken.
	 */
	public static function lock( int $seconds ): string {
		global $wpdb;

		$token = wp_generate_password( 20, false );
		$value = ( time() + $seconds ) . ':' . $token;

		// The insert fails while the lock exists, as in WP_Upgrader::create_lock().
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.
		if ( $inserted ) {
			return $token;
		}

		$held = self::held();
		if ( (int) $held > time() ) {
			return '';
		}

		// The holder died without unlocking. Take over, unless another request just did.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See above.

		return 1 === $taken ? $token : '';
	}

	/**
	 * Whether the lock still belongs to the request with this token.
	 *
	 * @param string $token Token from lock().
	 * @return bool
	 */
	public static function holds_lock( string $token ): bool {
		$held = self::held();

		return '' !== $token && substr( $held, (int) strpos( $held, ':' ) + 1 ) === $token;
	}

	/**
	 * Releases the lock, if it's still ours.
	 *
	 * @param string $token Token from lock().
	 */
	public static function unlock( string $token ): void {
		global $wpdb;

		if ( '' !== $token ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, '%:' . $wpdb->esc_like( $token ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
		}
	}

	/**
	 * Takes the lock away from whichever request holds it, so that request
	 * stops saving: for when its scan is cancelled or replaced.
	 */
	public static function break_lock(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
	}

	/**
	 * The lock's value: "expiry:token", or "".
	 *
	 * @return string
	 */
	private static function held(): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See lock().
	}
}

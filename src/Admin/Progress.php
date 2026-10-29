<?php
/**
 * What the admin page says about a scan in progress.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use InvalidArgumentException;
use UpgradeRadar\Jobs\Runner;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\ScanJob;

/**
 * Progress and problem messages, for the page and its AJAX calls.
 */
final class Progress {

	/**
	 * A summary of the scan for the admin page.
	 *
	 * @param array<string, mixed>|null $scan The stored scan.
	 * @param bool                      $busy Whether another request is running a batch.
	 * @return array{status: string, busy: bool, percent: int, text: string, problem: string}
	 */
	public static function summary( ?array $scan, bool $busy ): array {
		$summary = array(
			'status'  => null === $scan ? 'idle' : (string) $scan['status'],
			'busy'    => $busy,
			'percent' => 0,
			'text'    => '',
			'problem' => '',
		);

		if ( null === $scan ) {
			return $summary;
		}

		try {
			$progress = ScanJob::from_array( (array) $scan['job'] )->progress();
		} catch ( InvalidArgumentException $e ) {
			return $summary;
		}

		$current = 0 < $progress['current_files_found'] ? $progress['current_files_done'] / $progress['current_files_found'] : 0;
		$percent = 0 < $progress['items_total'] ? 100 * ( $progress['items_done'] + $current ) / $progress['items_total'] : 100;

		$summary['percent'] = (int) min( 100, floor( $percent ) );
		$summary['problem'] = is_array( $scan['problem'] ) ? self::problem( $scan['problem'] ) : '';

		$files = sprintf(
			/* translators: %s: number of files. */
			_n( '%s file checked so far.', '%s files checked so far.', $progress['files_done'], 'upgrade-radar' ),
			number_format_i18n( $progress['files_done'] )
		);

		if ( null === $progress['current'] ) {
			$summary['text'] = $files;
			return $summary;
		}

		$summary['text'] = sprintf(
			/* translators: 1: plugin or theme name, 2: its position, 3: number of plugins and themes, 4: "N files checked so far." */
			__( 'Checking %1$s (%2$d of %3$d). %4$s', 'upgrade-radar' ),
			(string) ( $scan['items'][ $progress['current'] ]['name'] ?? $progress['current'] ),
			$progress['items_done'] + 1,
			$progress['items_total'],
			$files
		);

		return $summary;
	}

	/**
	 * Explains why the scan is waiting or paused.
	 *
	 * @param array<string, mixed> $problem The stored problem.
	 * @return string
	 */
	public static function problem( array $problem ): string {
		$detail = (string) ( $problem['detail'] ?? '' );

		switch ( $problem['code'] ?? '' ) {
			case Runner::OTHER_COPY_LOADED:
				$plugin = self::plugin_name( $detail );
				if ( '' !== $plugin ) {
					return sprintf(
						/* translators: %s: plugin name. */
						__( "Another plugin, %s, loads its own copy of PHP_CodeSniffer, the code checker Upgrade Radar uses. Two copies can't safely run in the same request, so the scan is waiting. If this message stays, deactivate that plugin while you scan, then resume.", 'upgrade-radar' ),
						$plugin
					);
				}

				return sprintf(
					/* translators: %s: file path. */
					__( "Another plugin loads its own copy of PHP_CodeSniffer, the code checker Upgrade Radar uses (from %s). Two copies can't safely run in the same request, so the scan is waiting. If this message stays, deactivate that plugin while you scan, then resume.", 'upgrade-radar' ),
					'' !== $detail ? self::relative( $detail ) : __( 'an unknown location', 'upgrade-radar' )
				);

			case Runner::MISSING_EXTENSIONS:
				return sprintf(
					/* translators: %s: list of PHP extension names. */
					__( 'The scan needs PHP extensions this server is missing: %s. Ask your host to enable them.', 'upgrade-radar' ),
					implode( ', ', Scanner::missing_extensions() )
				);

			case Runner::BAD_STATE:
				return __( "The saved scan couldn't be read. Start a new scan.", 'upgrade-radar' );

			default:
				/* translators: %s: technical details. */
				return sprintf( __( "Upgrade Radar's files are incomplete or damaged, so it can't scan. Reinstall the plugin. Details: %s", 'upgrade-radar' ), $detail );
		}
	}

	/**
	 * The name of the plugin a file belongs to, or "".
	 *
	 * @param string $path Absolute path of a file.
	 * @return string
	 */
	private static function plugin_name( string $path ): string {
		$root = trailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		$path = wp_normalize_path( $path );
		if ( '' === $path || 0 !== strpos( $path, $root ) ) {
			return '';
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$directory = (string) strtok( substr( $path, strlen( $root ) ), '/' );
		foreach ( get_plugins() as $file => $data ) {
			if ( 0 === strpos( $file, $directory . '/' ) ) {
				return (string) $data['Name'];
			}
		}

		return $directory;
	}

	/**
	 * A path relative to the WordPress directory, when it's inside it.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function relative( string $path ): string {
		$path = wp_normalize_path( $path );
		$root = wp_normalize_path( ABSPATH );

		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}
}

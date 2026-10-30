<?php
/**
 * CSV export of a finished scan.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use UpgradeRadar\Jobs\Runner;
use UpgradeRadar\Scanner\FileReport;

/**
 * Every finding, one row each, with the item's verdict.
 */
final class Csv {

	/**
	 * Sends the CSV file.
	 */
	public static function download(): void {
		check_admin_referer( 'upgrade_radar_csv' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'upgrade-radar' ), '', array( 'response' => 403 ) );
		}

		$scan = Runner::scan();
		if ( null === $scan || Runner::DONE !== $scan['status'] ) {
			wp_die( esc_html__( 'There are no finished scan results to download.', 'upgrade-radar' ), '', array( 'back_link' => true ) );
		}

		$target = (string) $scan['target'];
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="upgrade-radar-php-' . sanitize_file_name( $target ) . '-' . gmdate( 'Y-m-d', (int) $scan['finished'] ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes to the response, not a file.
		$out = fopen( 'php://output', 'w' );
		foreach ( self::rows( Results::rows( $scan ), $target ) as $row ) {
			fputcsv( $out, $row, ',', '"', '' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the php://output stream opened above; WP_Filesystem has no equivalent for it.
		fclose( $out );
		exit;
	}

	/**
	 * The CSV rows: a header, then one row per finding or unchecked file, and
	 * one row for each item with neither.
	 *
	 * @param array<int, array<string, mixed>> $results Rows from Results::rows().
	 * @param string                           $target  The target PHP version.
	 * @return array<int, string[]>
	 */
	public static function rows( array $results, string $target ): array {
		$rows = array(
			array(
				__( 'Type', 'upgrade-radar' ),
				__( 'Name', 'upgrade-radar' ),
				__( 'Version', 'upgrade-radar' ),
				/* translators: %s: PHP version, such as 8.3. */
				sprintf( __( 'Verdict for PHP %s', 'upgrade-radar' ), $target ),
				__( 'File', 'upgrade-radar' ),
				__( 'Line', 'upgrade-radar' ),
				__( 'Column', 'upgrade-radar' ),
				__( 'Severity', 'upgrade-radar' ),
				__( 'Message', 'upgrade-radar' ),
				__( 'Rule', 'upgrade-radar' ),
				__( 'Possible false alarm', 'upgrade-radar' ),
			),
		);

		foreach ( $results as $result ) {
			$item  = array(
				Results::type_label( $result['type'] ),
				$result['name'],
				$result['version'],
				Results::label( $result['verdict'] ),
			);
			$found = false;

			foreach ( $result['result']->reports() as $report ) {
				if ( FileReport::SCANNED !== $report->status() ) {
					$rows[] = array_merge( $item, array( $report->path(), '', '', __( 'Not checked', 'upgrade-radar' ), $report->detail(), '', '' ) );
					$found  = true;
				}

				foreach ( $report->issues() as $issue ) {
					$rows[] = array_merge(
						$item,
						array(
							$report->path(),
							(string) $issue->line(),
							(string) $issue->column(),
							$issue->is_error() ? __( 'Error', 'upgrade-radar' ) : __( 'Warning', 'upgrade-radar' ),
							$issue->message(),
							$issue->source(),
							$issue->is_possible_false_alarm() ? sprintf( Results::false_alarm_format( $issue ), $issue->guard(), $target ) : '',
						)
					);
					$found  = true;
				}
			}

			if ( ! $found ) {
				$rows[] = array_merge( $item, array_fill( 0, 7, '' ) );
			}
		}

		return array_map( array( self::class, 'safe_row' ), $rows );
	}

	/**
	 * Keeps spreadsheet apps from running cells as formulas. Plugin names and
	 * paths come from plugin authors, so a cell like "=HYPERLINK(...)" gets a
	 * leading apostrophe.
	 *
	 * @param string[] $row A row.
	 * @return string[]
	 */
	public static function safe_row( array $row ): array {
		return array_map(
			static function ( string $cell ): string {
				return '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ? "'" . $cell : $cell;
			},
			$row
		);
	}
}

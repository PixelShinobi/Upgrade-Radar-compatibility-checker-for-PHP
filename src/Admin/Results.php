<?php
/**
 * Turns a finished scan into verdicts.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use InvalidArgumentException;
use UpgradeRadar\Jobs\Inventory;
use UpgradeRadar\Scanner\Issue;
use UpgradeRadar\Scanner\ItemResult;
use UpgradeRadar\Scanner\ScanJob;
use UpgradeRadar\Scanner\UpdateStatus;
use UpgradeRadar\Scanner\Verdict;

/**
 * One row per scanned item, with its verdict decided from the scan and from
 * WordPress's current update data. Verdicts are worked out when shown, so
 * they follow WordPress's update checks without a new scan.
 */
final class Results {

	/** The item changed since the scan, so its results are out of date. */
	public const RESCAN = 'rescan';

	/** The item couldn't be scanned, for example because it was deleted. */
	public const NOT_SCANNED = 'not_scanned';

	/**
	 * Display order, most urgent first.
	 */
	private const ORDER = array(
		Verdict::NEEDS_DEVELOPER,
		Verdict::NO_COMPATIBLE_VERSION,
		Verdict::UPDATE_FIRST,
		self::RESCAN,
		self::NOT_SCANNED,
		Verdict::READY,
	);

	/**
	 * Rows for a scan, most urgent first.
	 *
	 * @param array<string, mixed> $scan A stored scan.
	 * @return array<int, array{id: string, type: string, key: string, name: string, version: string, current_version: string, active: bool, verdict: string, update: UpdateStatus, result: ItemResult}>
	 */
	public static function rows( array $scan ): array {
		try {
			$results = ScanJob::from_array( (array) $scan['job'] )->results();
		} catch ( InvalidArgumentException $e ) {
			return array();
		}

		$updates = array(
			Inventory::PLUGIN => get_site_transient( 'update_plugins' ),
			Inventory::THEME  => get_site_transient( 'update_themes' ),
		);
		$active  = Inventory::active();
		$rows    = array();

		foreach ( $results as $result ) {
			$item = (array) ( $scan['items'][ $result->id() ] ?? array() );
			$type = (string) ( $item['type'] ?? Inventory::PLUGIN );
			$key  = (string) ( $item['key'] ?? $result->id() );

			$version = (string) ( $item['version'] ?? '' );
			$current = Inventory::current_version( $type, $key );
			$update  = UpdateStatus::from_transient( $updates[ Inventory::PLUGIN === $type ? Inventory::PLUGIN : Inventory::THEME ], $key, '' !== $current ? $current : $version );

			if ( ScanJob::ITEM_DONE !== $result->status() ) {
				$verdict = self::NOT_SCANNED;
			} elseif ( $current !== $version || ! isset( $active[ $result->id() ] ) ) {
				$verdict = self::RESCAN;
			} else {
				$verdict = Verdict::decide( $result->error_count(), $update );
			}

			$rows[] = array(
				'id'              => $result->id(),
				'type'            => $type,
				'key'             => $key,
				'name'            => (string) ( $item['name'] ?? $key ),
				'version'         => $version,
				'current_version' => $current,
				'active'          => isset( $active[ $result->id() ] ),
				'verdict'         => $verdict,
				'update'          => $update,
				'result'          => $result,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( array_search( $a['verdict'], self::ORDER, true ), strtolower( $a['name'] ) )
					<=> array( array_search( $b['verdict'], self::ORDER, true ), strtolower( $b['name'] ) );
			}
		);

		return $rows;
	}

	/**
	 * Number of rows per verdict, in display order.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from rows().
	 * @return array<string, int>
	 */
	public static function counts( array $rows ): array {
		$counts = array_fill_keys( self::ORDER, 0 );
		foreach ( $rows as $row ) {
			++$counts[ $row['verdict'] ];
		}

		return array_filter( $counts );
	}

	/**
	 * The verdict's name.
	 *
	 * @param string $verdict A verdict, RESCAN or NOT_SCANNED.
	 * @return string
	 */
	public static function label( string $verdict ): string {
		$labels = array(
			Verdict::READY                 => __( 'Ready', 'upgrade-radar' ),
			Verdict::UPDATE_FIRST          => __( 'Update first', 'upgrade-radar' ),
			Verdict::NO_COMPATIBLE_VERSION => __( 'No compatible version yet', 'upgrade-radar' ),
			Verdict::NEEDS_DEVELOPER       => __( 'Needs a developer', 'upgrade-radar' ),
			self::RESCAN                   => __( 'Scan again', 'upgrade-radar' ),
			self::NOT_SCANNED              => __( 'Not scanned', 'upgrade-radar' ),
		);

		return $labels[ $verdict ] ?? $verdict;
	}

	/**
	 * What the verdict means for this item, in a sentence or two.
	 *
	 * @param array<string, mixed> $row    A row from rows().
	 * @param string               $target The target PHP version.
	 * @return string
	 */
	public static function explanation( array $row, string $target ): string {
		$result = $row['result'];
		$update = $row['update'];

		switch ( $row['verdict'] ) {
			case Verdict::READY:
				if ( 0 === $result->warning_count() ) {
					/* translators: %s: PHP version, such as 8.3. */
					$text = sprintf( __( 'No problems found for PHP %s.', 'upgrade-radar' ), $target );
				} else {
					$text = sprintf(
						/* translators: 1: number of warnings, 2: PHP version, such as 8.3. */
						_n(
							'Nothing breaks. %1$d deprecation warning: deprecated code still works on PHP %2$s, but should be updated eventually.',
							'Nothing breaks. %1$d deprecation warnings: deprecated code still works on PHP %2$s, but should be updated eventually.',
							$result->warning_count(),
							'upgrade-radar'
						),
						$result->warning_count(),
						$target
					);
				}

				if ( 0 === $result->false_alarm_count() ) {
					return $text;
				}

				return $text . ' ' . sprintf(
					/* translators: 1: number of findings, 2: PHP version, such as 8.3. */
					_n(
						'%1$d possible false alarm, in code that never runs on PHP %2$s.',
						'%1$d possible false alarms, in code that never runs on PHP %2$s.',
						$result->false_alarm_count(),
						'upgrade-radar'
					),
					$result->false_alarm_count(),
					$target
				);

			case Verdict::UPDATE_FIRST:
				if ( UpdateStatus::AVAILABLE === $update->status() ) {
					/* translators: %s: version number. */
					return sprintf( __( 'Version %s is available. Update, then scan again.', 'upgrade-radar' ), $update->new_version() );
				}

				return __( "WordPress hasn't checked this version for updates yet. Check for updates on the Updates screen, update if you can, then scan again.", 'upgrade-radar' );

			case Verdict::NO_COMPATIBLE_VERSION:
				/* translators: %s: PHP version, such as 8.3. */
				return sprintf( __( 'This is the latest version, and it still has problems on PHP %s. Ask its developer about a fix, or look for a replacement.', 'upgrade-radar' ), $target );

			case Verdict::NEEDS_DEVELOPER:
				return __( "WordPress can't check this for updates, so it's probably custom code or from outside WordPress.org. A developer needs to fix the problems below.", 'upgrade-radar' );

			case self::RESCAN:
				if ( ! $row['active'] ) {
					return __( "This isn't active any more, so these results may not matter.", 'upgrade-radar' );
				}

				/* translators: 1: version that was scanned, 2: version installed now. */
				return '' === $row['current_version']
					? __( 'This was changed since the scan. Scan again for an up-to-date verdict.', 'upgrade-radar' )
					: sprintf( __( 'Version %1$s was scanned, and %2$s is installed now. Scan again for an up-to-date verdict.', 'upgrade-radar' ), $row['version'], $row['current_version'] );

			default:
				return $result->detail();
		}
	}

	/**
	 * Why a finding is a possible false alarm, as a format: %1$s is the
	 * guard's code and %2$s the target PHP version.
	 *
	 * @param Issue $issue A possible false alarm.
	 * @return string
	 */
	public static function false_alarm_format( Issue $issue ): string {
		/* translators: 1: code of a condition, such as "PHP_VERSION_ID < 80000", 2: PHP version, such as 8.3. */
		return $issue->guard_runs_if()
			? __( 'Only runs if %1$s, which never happens on PHP %2$s.', 'upgrade-radar' )
			: __( 'Only runs if %1$s is false, which never happens on PHP %2$s.', 'upgrade-radar' );
	}

	/**
	 * The kind of item, such as "Plugin".
	 *
	 * @param string $type One of the Inventory type constants.
	 * @return string
	 */
	public static function type_label( string $type ): string {
		if ( Inventory::THEME === $type ) {
			return __( 'Theme', 'upgrade-radar' );
		}

		return Inventory::PARENT_THEME === $type ? __( 'Parent theme', 'upgrade-radar' ) : __( 'Plugin', 'upgrade-radar' );
	}
}

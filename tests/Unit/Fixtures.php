<?php
/**
 * Helpers for the sample code in tests/fixtures.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use UpgradeRadar\Scanner\FileReport;

/**
 * Fixtures mark each expected finding at the end of its line:
 * "// expect: error <sniff code>", several separated by ";". A possible false
 * alarm ends in " (false alarm)".
 */
final class Fixtures {

	/**
	 * Absolute path inside tests/fixtures.
	 *
	 * @param string $relative Path relative to tests/fixtures.
	 * @return string
	 */
	public static function path( string $relative = '' ): string {
		return dirname( __DIR__ ) . '/fixtures' . ( '' === $relative ? '' : '/' . $relative );
	}

	/**
	 * The findings a fixture's markers expect, as "line type code" strings.
	 *
	 * @param string $file Absolute path of the fixture.
	 * @return string[] Sorted.
	 */
	public static function expected( string $file ): array {
		$expected = array();
		foreach ( (array) file( $file ) as $index => $line ) {
			if ( ! preg_match( '/expect: (.+)$/', (string) $line, $match ) ) {
				continue;
			}

			foreach ( explode( ';', $match[1] ) as $finding ) {
				$expected[] = ( $index + 1 ) . ' ' . trim( $finding );
			}
		}

		sort( $expected );
		return $expected;
	}

	/**
	 * The findings in a report, in the same format as expected().
	 *
	 * @param FileReport $report A scanned file.
	 * @return string[] Sorted.
	 */
	public static function found( FileReport $report ): array {
		$found = array();
		foreach ( $report->issues() as $issue ) {
			$found[] = $issue->line() . ' ' . $issue->type() . ' ' . $issue->source() . ( $issue->is_possible_false_alarm() ? ' (false alarm)' : '' );
		}

		sort( $found );
		return $found;
	}
}

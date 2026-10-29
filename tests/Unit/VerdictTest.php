<?php
/**
 * Tests for the verdict rules.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use UpgradeRadar\Scanner\UpdateStatus;
use UpgradeRadar\Scanner\Verdict;

/**
 * Verdict tests.
 */
final class VerdictTest extends TestCase {

	/**
	 * An update status, built from a transient like WordPress's.
	 *
	 * @param string $status One of the UpdateStatus constants.
	 * @return UpdateStatus
	 */
	private static function update( string $status ): UpdateStatus {
		$transient            = new stdClass();
		$transient->checked   = array( 'item/item.php' => '1.0' );
		$transient->response  = array();
		$transient->no_update = array();

		if ( UpdateStatus::AVAILABLE === $status ) {
			$transient->response['item/item.php'] = (object) array( 'new_version' => '2.0' );
		} elseif ( UpdateStatus::LATEST === $status ) {
			$transient->no_update['item/item.php'] = (object) array( 'new_version' => '1.0' );
		} elseif ( UpdateStatus::UNKNOWN === $status ) {
			$transient->checked = array();
		}

		$update = UpdateStatus::from_transient( $transient, 'item/item.php', '1.0' );
		self::assertSame( $status, $update->status() );

		return $update;
	}

	/**
	 * Every combination of errors and update status.
	 *
	 * @return array<string, array{int, string, string}>
	 */
	public function cases(): array {
		return array(
			'no errors, update available'   => array( 0, UpdateStatus::AVAILABLE, Verdict::READY ),
			'no errors, newest version'     => array( 0, UpdateStatus::LATEST, Verdict::READY ),
			'no errors, custom code'        => array( 0, UpdateStatus::NOT_TRACKED, Verdict::READY ),
			'no errors, not checked yet'    => array( 0, UpdateStatus::UNKNOWN, Verdict::READY ),
			'errors, update available'      => array( 3, UpdateStatus::AVAILABLE, Verdict::UPDATE_FIRST ),
			'errors, not checked yet'       => array( 1, UpdateStatus::UNKNOWN, Verdict::UPDATE_FIRST ),
			'errors in the newest version'  => array( 2, UpdateStatus::LATEST, Verdict::NO_COMPATIBLE_VERSION ),
			'errors in code nobody updates' => array( 5, UpdateStatus::NOT_TRACKED, Verdict::NEEDS_DEVELOPER ),
		);
	}

	/**
	 * Only errors decide, and update data picks what to do about them.
	 *
	 * @dataProvider cases
	 *
	 * @param int    $errors  Errors found.
	 * @param string $status  Update status.
	 * @param string $verdict Expected verdict.
	 */
	public function test_decides_from_errors_and_update_data( int $errors, string $status, string $verdict ): void {
		$this->assertSame( $verdict, Verdict::decide( $errors, self::update( $status ) ) );
	}

	/**
	 * The urgency order the admin page sorts by covers every verdict once.
	 */
	public function test_lists_every_verdict_most_urgent_first(): void {
		$this->assertSame(
			array( Verdict::NEEDS_DEVELOPER, Verdict::NO_COMPATIBLE_VERSION, Verdict::UPDATE_FIRST, Verdict::READY ),
			Verdict::ALL
		);
	}
}

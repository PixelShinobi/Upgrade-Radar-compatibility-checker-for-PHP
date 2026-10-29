<?php
/**
 * Tests for reading WordPress's update data.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use stdClass;
use UpgradeRadar\Scanner\UpdateStatus;

/**
 * UpdateStatus tests, with transients shaped like the ones WordPress stores.
 */
final class UpdateStatusTest extends TestCase {

	/**
	 * A plugin transient after a successful check.
	 *
	 * @return stdClass
	 */
	private static function plugins_transient(): stdClass {
		$transient               = new stdClass();
		$transient->last_checked = time();
		$transient->checked      = array(
			'old-plugin/old-plugin.php' => '1.0',
			'current/current.php'       => '2.5',
			'custom/custom.php'         => '0.9',
			'stale/stale.php'           => '3.0',
		);
		$transient->response     = array(
			'old-plugin/old-plugin.php' => (object) array(
				'slug'        => 'old-plugin',
				'new_version' => '1.4.2',
			),
			// WordPress clears this after updates, but a stale entry must not count.
			'stale/stale.php'           => (object) array( 'new_version' => '3.0' ),
		);
		$transient->no_update = array(
			'current/current.php' => (object) array( 'new_version' => '2.5' ),
		);

		return $transient;
	}

	/**
	 * Items that WordPress's data covers.
	 *
	 * @return array<string, array{string, string, string, string}>
	 */
	public function plugins(): array {
		return array(
			'newer version available'       => array( 'old-plugin/old-plugin.php', '1.0', UpdateStatus::AVAILABLE, '1.4.2' ),
			'on WordPress.org, newest'      => array( 'current/current.php', '2.5', UpdateStatus::LATEST, '' ),
			'nothing provides updates'      => array( 'custom/custom.php', '0.9', UpdateStatus::NOT_TRACKED, '' ),
			'update entry for same version' => array( 'stale/stale.php', '3.0', UpdateStatus::LATEST, '' ),
			'installed after the check'     => array( 'new/new.php', '1.0', UpdateStatus::UNKNOWN, '' ),
			'updated since the check'       => array( 'old-plugin/old-plugin.php', '1.4.2', UpdateStatus::UNKNOWN, '' ),
		);
	}

	/**
	 * Plugin statuses.
	 *
	 * @dataProvider plugins
	 *
	 * @param string $key         Plugin file.
	 * @param string $version     Installed version.
	 * @param string $status      Expected status.
	 * @param string $new_version Expected new version.
	 */
	public function test_reads_plugin_updates( string $key, string $version, string $status, string $new_version ): void {
		$update = UpdateStatus::from_transient( self::plugins_transient(), $key, $version );

		$this->assertSame( array( $status, $new_version ), array( $update->status(), $update->new_version() ) );
	}

	/**
	 * Theme updates are arrays rather than objects.
	 */
	public function test_reads_theme_updates(): void {
		$transient            = new stdClass();
		$transient->checked   = array(
			'old-theme' => '1.0',
			'twentytwo' => '2.0',
			'custom'    => '1.0',
		);
		$transient->response  = array(
			'old-theme' => array(
				'theme'       => 'old-theme',
				'new_version' => '1.1',
			),
		);
		$transient->no_update = array(
			'twentytwo' => array( 'new_version' => '2.0' ),
		);

		$this->assertSame( UpdateStatus::AVAILABLE, UpdateStatus::from_transient( $transient, 'old-theme', '1.0' )->status() );
		$this->assertSame( '1.1', UpdateStatus::from_transient( $transient, 'old-theme', '1.0' )->new_version() );
		$this->assertSame( UpdateStatus::LATEST, UpdateStatus::from_transient( $transient, 'twentytwo', '2.0' )->status() );
		$this->assertSame( UpdateStatus::NOT_TRACKED, UpdateStatus::from_transient( $transient, 'custom', '1.0' )->status() );
	}

	/**
	 * Without usable update data, nothing is known.
	 */
	public function test_is_unknown_without_update_data(): void {
		$empty               = new stdClass();
		$empty->last_checked = time();

		$broken           = new stdClass();
		$broken->checked  = 'not an array';
		$broken->response = 'not an array';

		foreach ( array( false, null, array(), $empty, $broken ) as $transient ) {
			$this->assertSame( UpdateStatus::UNKNOWN, UpdateStatus::from_transient( $transient, 'custom/custom.php', '1.0' )->status() );
		}
	}
}

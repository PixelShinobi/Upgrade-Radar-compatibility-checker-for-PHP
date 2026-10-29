<?php
/**
 * Tests for target PHP versions.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * TargetVersion tests.
 */
final class TargetVersionTest extends TestCase {

	/**
	 * Supported versions scan from the 7.4 baseline up to the target.
	 */
	public function test_accepts_supported_versions(): void {
		$this->assertSame( array( '8.1', '8.2', '8.3', '8.4', '8.5' ), TargetVersion::SUPPORTED );

		$target = TargetVersion::from_string( ' 8.3 ' );
		$this->assertSame( '8.3', $target->to_string() );
		$this->assertSame( '7.4-8.3', $target->test_version() );
	}

	/**
	 * Unsupported versions.
	 *
	 * @return array<string, array{string}>
	 */
	public function unsupported(): array {
		return array(
			'too old'      => array( '8.0' ),
			'too new'      => array( '8.6' ),
			'patch level'  => array( '8.3.1' ),
			'range'        => array( '7.4-8.3' ),
			'empty'        => array( '' ),
			'not a number' => array( 'latest' ),
		);
	}

	/**
	 * Anything else is rejected before it reaches PHPCompatibility.
	 *
	 * @dataProvider unsupported
	 *
	 * @param string $version An unsupported version.
	 */
	public function test_rejects_unsupported_versions( string $version ): void {
		$this->expectException( InvalidArgumentException::class );
		TargetVersion::from_string( $version );
	}
}

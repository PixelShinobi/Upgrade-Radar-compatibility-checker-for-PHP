<?php
/**
 * The PHP version a scan checks against.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * A validated target PHP version, such as "8.3".
 */
final class TargetVersion {

	/**
	 * The oldest PHP version scanned code is assumed to run on today.
	 *
	 * It's WordPress's minimum PHP version and ours. Scans check the range from
	 * here to the target, which makes PHPCompatibility report breaks that only
	 * show up when old code moves to a newer PHP, such as PHP 4 style constructors.
	 * Keeping it fixed means a plugin version gets the same result on every site.
	 */
	public const BASELINE = '7.4';

	/**
	 * The target versions the scanner supports, oldest first.
	 */
	public const SUPPORTED = array( '8.1', '8.2', '8.3', '8.4', '8.5' );

	/**
	 * The version, such as "8.3".
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Use from_string() instead.
	 *
	 * @param string $version A supported version.
	 */
	private function __construct( string $version ) {
		$this->version = $version;
	}

	/**
	 * Validates a version string.
	 *
	 * @param string $version A version such as "8.3".
	 * @return self
	 * @throws InvalidArgumentException If the version isn't supported.
	 */
	public static function from_string( string $version ): self {
		$version = trim( $version );
		if ( ! in_array( $version, self::SUPPORTED, true ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unsupported target PHP version "%s". Choose one of: %s.',
					$version,
					implode( ', ', self::SUPPORTED )
				)
			);
		}

		return new self( $version );
	}

	/**
	 * Returns the version, such as "8.3".
	 *
	 * @return string
	 */
	public function to_string(): string {
		return $this->version;
	}

	/**
	 * Returns the PHPCompatibility testVersion to scan with, such as "7.4-8.3".
	 *
	 * @return string
	 */
	public function test_version(): string {
		return self::BASELINE . '-' . $this->version;
	}
}

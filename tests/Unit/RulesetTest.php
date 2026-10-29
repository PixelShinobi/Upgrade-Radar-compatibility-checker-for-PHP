<?php
/**
 * Guards for the ruleset and the pinned rule packages.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\Scanner;

/**
 * Catches drift when the rule packages are upgraded.
 */
final class RulesetTest extends TestCase {

	/**
	 * Sniffs directory of the installed PHPCompatibility.
	 *
	 * @var string
	 */
	private const SNIFFS = __DIR__ . '/../../vendor/phpcompatibility/php-compatibility/PHPCompatibility/Sniffs';

	/**
	 * A new-feature sniff added by an upgrade would flag modern plugins.
	 */
	public function test_excludes_every_new_feature_sniff(): void {
		$sniffs = glob( self::SNIFFS . '/*/New*Sniff.php' );
		$this->assertGreaterThan( 80, count( $sniffs ) );

		$excluded = $this->excluded();
		foreach ( $sniffs as $sniff ) {
			$code = 'PHPCompatibility.' . basename( dirname( $sniff ) ) . '.' . basename( $sniff, 'Sniff.php' );
			$this->assertContains( $code, $excluded, 'Add it to src/Scanner/ruleset.xml, or explain why not.' );
		}
	}

	/**
	 * An exclusion for a renamed or removed sniff would silently do nothing.
	 */
	public function test_every_exclusion_names_an_installed_sniff(): void {
		foreach ( $this->excluded() as $code ) {
			$parts = explode( '.', $code );
			$this->assertSame( 'PHPCompatibility', $parts[0], $code );
			$this->assertFileExists( self::SNIFFS . '/' . $parts[1] . '/' . $parts[2] . 'Sniff.php', $code );
		}
	}

	/**
	 * The versions shown in reports are the versions installed and pinned.
	 */
	public function test_reported_rule_versions_match_the_pins(): void {
		$root     = dirname( __DIR__, 2 );
		$composer = json_decode( (string) file_get_contents( $root . '/composer.json' ), true );
		$lock     = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true );
		$locked   = array_column( $lock['packages'], 'version', 'name' );

		foreach ( Scanner::RULES as $package => $version ) {
			$this->assertSame( $version, $composer['require'][ $package ], $package . ' must be pinned exactly in composer.json.' );
			$this->assertSame( $version, $locked[ $package ], $package . ' in composer.lock.' );
		}
	}

	/**
	 * Codes excluded in the ruleset.
	 *
	 * @return string[]
	 */
	private function excluded(): array {
		$ruleset = simplexml_load_file( Scanner::ruleset_file() );
		$this->assertSame( 'PHPCompatibilityWP', (string) $ruleset->rule['ref'] );

		$codes = array();
		foreach ( $ruleset->rule->exclude as $exclude ) {
			$codes[] = (string) $exclude['name'];
		}

		return $codes;
	}
}

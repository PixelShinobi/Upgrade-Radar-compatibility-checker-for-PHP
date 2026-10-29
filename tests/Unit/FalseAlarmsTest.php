<?php
/**
 * Tests for possible false alarms: findings in guarded code.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\FileReport;
use UpgradeRadar\Scanner\Issue;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Scans guarded code and checks which findings are possible false alarms.
 */
final class FalseAlarmsTest extends TestCase {

	/**
	 * Shared, because loading the ruleset takes a moment.
	 *
	 * @var Scanner|null
	 */
	private static $scanner;

	/**
	 * Every supported target version.
	 *
	 * @return array<string, array{string}>
	 */
	public function targets(): array {
		$targets = array();
		foreach ( TargetVersion::SUPPORTED as $version ) {
			$targets[ 'PHP ' . $version ] = array( $version );
		}

		return $targets;
	}

	/**
	 * The guards/ fixtures mark every finding, with " (false alarm)" on the
	 * excused ones. Their checks give the same answer on every PHP 8 release.
	 *
	 * @dataProvider targets
	 *
	 * @param string $target Target version.
	 */
	public function test_marks_guarded_findings_in_the_fixtures_on( string $target ): void {
		foreach ( glob( Fixtures::path( 'guards/*.php' ) ) as $file ) {
			$report = self::scanner()->scan_file( dirname( $file ), basename( $file ), TargetVersion::from_string( $target ) );
			$this->assertSame( Fixtures::expected( $file ), Fixtures::found( $report ), basename( $file ) . ' for PHP ' . $target );
		}
	}

	/**
	 * Version checks are evaluated for every release of the target, x.y.0 to
	 * x.y.99: a check that's true for some releases excuses nothing.
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public function version_checks(): array {
		$before_82  = "if ( PHP_VERSION_ID < 80200 ) {\n\teach( \$a );\n}\n";
		$before_815 = "if ( PHP_VERSION_ID < 80105 ) {\n\teach( \$a );\n}\n";
		$since_842  = "if ( version_compare( PHP_VERSION, '8.4.2', '>=' ) ) {\n\treturn;\n}\neach( \$a );\n";
		$on_83      = "if ( PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 3 ) {\n\teach( \$a );\n}\n";

		return array(
			'< 8.2 on PHP 8.1'           => array( $before_82, '8.1', false ),
			'< 8.2 on PHP 8.2'           => array( $before_82, '8.2', true ),
			'< 8.2 on PHP 8.5'           => array( $before_82, '8.5', true ),
			'< 8.1.5 on PHP 8.1'         => array( $before_815, '8.1', false ),
			'< 8.1.5 on PHP 8.2'         => array( $before_815, '8.2', true ),
			'return on 8.4.2 on PHP 8.4' => array( $since_842, '8.4', false ),
			'return on 8.4.2 on PHP 8.5' => array( $since_842, '8.5', true ),
			'only 8.3 on PHP 8.3'        => array( $on_83, '8.3', false ),
			'only 8.3 on PHP 8.4'        => array( $on_83, '8.4', true ),
		);
	}

	/**
	 * A version check excuses a finding only if no release of the target
	 * passes it.
	 *
	 * @dataProvider version_checks
	 *
	 * @param string $code     Code after the opening tag.
	 * @param string $target   Target version.
	 * @param bool   $excused  Whether the each() call is a possible false alarm.
	 */
	public function test_evaluates_version_checks_for_the_target( string $code, string $target, bool $excused ): void {
		$issues = $this->scan_code( $code, $target )->issues();

		$this->assertCount( 1, $issues );
		$this->assertSame( $excused, $issues[0]->is_possible_false_alarm() );
	}

	/**
	 * The guard is kept as written, and a leading "!" is turned around.
	 */
	public function test_reports_the_guard_the_code_depends_on(): void {
		$code   = "if ( ! function_exists( 'each' ) ) {\n\treturn;\n}\neach( \$a );\nif ( PHP_VERSION_ID >= 80000 ) {\n\t\$b = 1;\n} else {\n\teach( \$b );\n}\n";
		$issues = $this->scan_code( $code, '8.3' )->issues();

		$this->assertSame(
			array(
				array( "function_exists( 'each' )", true ),
				array( 'PHP_VERSION_ID >= 80000', false ),
			),
			array_map(
				static function ( Issue $issue ): array {
					return array( $issue->guard(), $issue->guard_runs_if() );
				},
				$issues
			)
		);
	}

	/**
	 * Possible false alarms are listed but don't count as errors or warnings.
	 */
	public function test_possible_false_alarms_do_not_count(): void {
		$report = $this->scan_code( "if ( PHP_VERSION_ID < 80000 ) {\n\teach( \$a );\n\tlibxml_disable_entity_loader( true );\n}\neach( \$b );\n", '8.4' );

		$this->assertCount( 3, $report->issues() );
		$this->assertSame( 1, $report->error_count() );
		$this->assertSame( 0, $report->warning_count() );
		$this->assertSame( 2, $report->false_alarm_count() );
	}

	/**
	 * The guard survives storage, and issues stored without one still load.
	 */
	public function test_stores_the_guard(): void {
		$issue = ( new Issue( 3, 2, Issue::ERROR, 'Removed.', 'PHPCompatibility.X.Y.z' ) )->with_guard( 'PHP_VERSION_ID < 80000', true );
		$copy  = Issue::from_array( $issue->to_array() );

		$this->assertSame( $issue->to_array(), $copy->to_array() );
		$this->assertTrue( $copy->is_possible_false_alarm() );

		$old = Issue::from_array(
			array(
				'line'    => 3,
				'column'  => 2,
				'type'    => Issue::ERROR,
				'message' => 'Removed.',
				'source'  => 'PHPCompatibility.X.Y.z',
			)
		);
		$this->assertFalse( $old->is_possible_false_alarm() );
		$this->assertSame( '', $old->guard() );
	}

	/**
	 * Scans a snippet of code.
	 *
	 * @param string $code   Code after the opening tag.
	 * @param string $target Target version.
	 * @return FileReport
	 */
	private function scan_code( string $code, string $target ): FileReport {
		$dir = Temp::dir();
		file_put_contents( $dir . '/snippet.php', "<?php\n" . $code ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test file.

		return self::scanner()->scan_file( $dir, 'snippet.php', TargetVersion::from_string( $target ) );
	}

	/**
	 * The shared scanner.
	 *
	 * @return Scanner
	 */
	private static function scanner(): Scanner {
		if ( null === self::$scanner ) {
			self::$scanner = new Scanner();
		}

		return self::$scanner;
	}
}

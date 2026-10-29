<?php
/**
 * Tests for the scanner engine, run against the fixtures.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\FileReport;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\ScannerUnavailable;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Scanner tests.
 */
final class ScannerTest extends TestCase {

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
	 * Each breaks/phpXY.php fixture holds changes from PHP X.Y. They must be
	 * reported exactly as marked from that target on, and not before.
	 *
	 * @dataProvider targets
	 *
	 * @param string $target Target version.
	 */
	public function test_flags_known_breaks_from_their_php_version_on( string $target ): void {
		$files = glob( Fixtures::path( 'breaks/php*.php' ) );
		$this->assertCount( 6, $files );

		foreach ( $files as $file ) {
			$since    = preg_replace( '/^php(\d)(\d)$/', '$1.$2', basename( $file, '.php' ) );
			$expected = version_compare( $target, $since, '>=' ) ? Fixtures::expected( $file ) : array();
			$report   = $this->scan( 'breaks', basename( $file ), $target );

			$this->assertSame( FileReport::SCANNED, $report->status() );
			$this->assertSame( $expected, Fixtures::found( $report ), basename( $file ) . ' for PHP ' . $target );
		}
	}

	/**
	 * The breaks fixtures must mark a good number of findings, or the test above proves little.
	 */
	public function test_break_fixtures_cover_each_version(): void {
		$counts = array();
		foreach ( glob( Fixtures::path( 'breaks/php*.php' ) ) as $file ) {
			$counts[ basename( $file ) ] = count( Fixtures::expected( $file ) );
		}

		foreach ( $counts as $file => $count ) {
			$this->assertGreaterThanOrEqual( 3, $count, $file );
		}
	}

	/**
	 * Modern PHP 8 code and classic PHP 7.4 code that still works must not be flagged.
	 *
	 * @dataProvider targets
	 *
	 * @param string $target Target version.
	 */
	public function test_stays_quiet_on_clean_code( string $target ): void {
		$found = self::scanner()->find_files( Fixtures::path( 'clean' ) );
		$this->assertGreaterThanOrEqual( 7, count( $found['files'] ) );

		foreach ( $found['files'] as $file ) {
			$report = self::scanner()->scan_file( $found['root'], $file, TargetVersion::from_string( $target ) );
			$this->assertTrue( $report->is_clean(), $file . ': ' . json_encode( $report->to_array() ) );
		}
	}

	/**
	 * Annotations naming PHPCompatibility sniffs are honored, others aren't.
	 */
	public function test_only_honors_annotations_that_name_phpcompatibility_sniffs(): void {
		foreach ( array( 'generic-annotations.php', 'compatibility-annotations.php', 'reenabled-annotations.php' ) as $file ) {
			$report = $this->scan( 'edge', $file, '8.5' );
			$this->assertSame( Fixtures::expected( Fixtures::path( 'edge/' . $file ) ), Fixtures::found( $report ), $file );
		}
	}

	/**
	 * A file of plain HTML is clean, and a syntax error doesn't stop the scan.
	 */
	public function test_handles_files_without_php_and_syntax_errors(): void {
		$this->assertTrue( $this->scan( 'edge', 'html-only.php', '8.3' )->is_clean() );

		$report = $this->scan( 'edge', 'syntax-error.php', '8.3' );
		$this->assertSame( Fixtures::expected( Fixtures::path( 'edge/syntax-error.php' ) ), Fixtures::found( $report ) );
	}

	/**
	 * Scans only read files.
	 */
	public function test_never_changes_scanned_files(): void {
		$found  = self::scanner()->find_files( Fixtures::path() );
		$before = array();
		foreach ( $found['files'] as $file ) {
			$before[ $file ] = md5_file( $found['root'] . '/' . $file ) . filemtime( $found['root'] . '/' . $file );
		}

		foreach ( $found['files'] as $file ) {
			self::scanner()->scan_file( $found['root'], $file, TargetVersion::from_string( '8.5' ) );
		}

		clearstatcache();
		foreach ( $found['files'] as $file ) {
			$this->assertSame( $before[ $file ], md5_file( $found['root'] . '/' . $file ) . filemtime( $found['root'] . '/' . $file ), $file );
		}
	}

	/**
	 * Paths that lead outside the item's directory are never read.
	 */
	public function test_refuses_paths_outside_the_root(): void {
		$report = $this->scan( 'clean/classic-plugin', '../../breaks/php80.php', '8.3' );
		$this->assertSame( FileReport::SKIPPED, $report->status() );
		$this->assertSame( FileReport::REASON_OUTSIDE_ROOT, $report->reason() );

		$dir = Temp::dir();
		if ( ! @symlink( Fixtures::path( 'breaks/php80.php' ), $dir . '/link.php' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable.
			$this->markTestIncomplete( 'Symlinks are not available here.' );
		}

		$report = self::scanner()->scan_file( $dir, 'link.php', TargetVersion::from_string( '8.3' ) );
		$this->assertSame( FileReport::REASON_OUTSIDE_ROOT, $report->reason() );
	}

	/**
	 * A file that vanished between listing and scanning is reported, not fatal.
	 */
	public function test_reports_files_that_vanished(): void {
		$report = $this->scan( 'clean', 'gone.php', '8.3' );
		$this->assertSame( FileReport::FAILED, $report->status() );
		$this->assertSame( FileReport::REASON_UNREADABLE, $report->reason() );
	}

	/**
	 * Files over the size limit are skipped.
	 */
	public function test_skips_files_over_the_size_limit(): void {
		$scanner = new Scanner( array( 'max_file_size' => 100 ) );
		$report  = $scanner->scan_file( Fixtures::path( 'breaks' ), 'php80.php', TargetVersion::from_string( '8.3' ) );

		$this->assertSame( FileReport::SKIPPED, $report->status() );
		$this->assertSame( FileReport::REASON_TOO_LARGE, $report->reason() );
	}

	/**
	 * Files that would need more memory than the server has are skipped, not fatal.
	 */
	public function test_skips_files_too_large_for_the_memory_limit(): void {
		$dir = Temp::dir();
		file_put_contents( $dir . '/data.php', "<?php\nreturn array(\n" . str_repeat( "\t'key' => 'value',\n", 20000 ) . ");\n" );

		$limit = ini_get( 'memory_limit' );
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + 16 * 1048576 ) ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restored below.
		try {
			$report = self::scanner()->scan_file( $dir, 'data.php', TargetVersion::from_string( '8.3' ) );
		} finally {
			ini_set( 'memory_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restoring.
		}

		$this->assertSame( FileReport::REASON_TOO_LARGE, $report->reason() );
		$this->assertStringContainsString( 'memory', $report->detail() );
	}

	/**
	 * PHP_CodeSniffer stays unloaded until the first scan.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_loads_php_codesniffer_only_when_scanning(): void {
		$scanner = new Scanner();
		$scanner->find_files( Fixtures::path( 'breaks' ) );
		$this->assertFalse( class_exists( 'PHP_CodeSniffer\Config', false ) );

		$scanner->scan_file( Fixtures::path( 'breaks' ), 'php80.php', TargetVersion::from_string( '8.3' ) );
		$this->assertTrue( class_exists( 'PHP_CodeSniffer\Config', false ) );
	}

	/**
	 * Another plugin's copy of PHP_CodeSniffer, loaded first, stops the scan
	 * with a clear message instead of giving unreliable results.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_refuses_to_use_another_copy_of_php_codesniffer(): void {
		$vendor = dirname( __DIR__, 2 ) . '/vendor';
		require $vendor . '/squizlabs/php_codesniffer/autoload.php';

		// Our copy, as far as the scanner can tell, lives elsewhere.
		$own = Temp::dir();
		mkdir( $own . '/squizlabs/php_codesniffer', 0777, true );
		mkdir( $own . '/phpcsstandards/phpcsutils', 0777, true );
		copy( $vendor . '/squizlabs/php_codesniffer/autoload.php', $own . '/squizlabs/php_codesniffer/autoload.php' );
		copy( $vendor . '/phpcsstandards/phpcsutils/phpcsutils-autoload.php', $own . '/phpcsstandards/phpcsutils/phpcsutils-autoload.php' );

		try {
			( new Scanner( array( 'vendor_dir' => $own ) ) )->scan_file( Fixtures::path( 'breaks' ), 'php80.php', TargetVersion::from_string( '8.3' ) );
			$this->fail( 'The scan should have refused to run.' );
		} catch ( ScannerUnavailable $e ) {
			$this->assertSame( ScannerUnavailable::OTHER_COPY_LOADED, $e->getCode() );
			$this->assertStringContainsString( 'Another copy of squizlabs/php_codesniffer is already loaded', $e->getMessage() );
			$this->assertSame( realpath( $vendor . '/squizlabs/php_codesniffer/autoload.php' ), realpath( $e->other_copy_path() ) );
		}
	}

	/**
	 * A missing vendor directory stops the scan with a clear message.
	 */
	public function test_reports_a_missing_vendor_directory(): void {
		$this->expectException( ScannerUnavailable::class );
		$this->expectExceptionCode( ScannerUnavailable::BROKEN_INSTALL );
		( new Scanner( array( 'vendor_dir' => Temp::dir() ) ) )->scan_file( Fixtures::path( 'breaks' ), 'php80.php', TargetVersion::from_string( '8.3' ) );
	}

	/**
	 * Scans one fixture file.
	 *
	 * @param string $dir    Directory inside tests/fixtures.
	 * @param string $file   File path relative to $dir.
	 * @param string $target Target version.
	 * @return FileReport
	 */
	private function scan( string $dir, string $file, string $target ): FileReport {
		return self::scanner()->scan_file( Fixtures::path( $dir ), $file, TargetVersion::from_string( $target ) );
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

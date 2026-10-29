<?php
/**
 * Tests for resumable batch scans.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UpgradeRadar\Scanner\FileReport;
use UpgradeRadar\Scanner\ItemResult;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\ScanJob;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * ScanJob tests.
 */
final class ScanJobTest extends TestCase {

	/**
	 * Shared, because loading the ruleset takes a moment.
	 *
	 * @var Scanner|null
	 */
	private static $scanner;

	/**
	 * Scanning one file per batch, saving the state as JSON in between, gives
	 * the same results as scanning everything at once.
	 */
	public function test_batch_size_does_not_change_the_results(): void {
		$items  = array(
			'missing' => Fixtures::path( 'does-not-exist' ),
			'breaks'  => Fixtures::path( 'breaks' ),
			'clean'   => Fixtures::path( 'clean' ),
			'single'  => Fixtures::path( 'breaks/php80.php' ),
		);
		$target = TargetVersion::from_string( '8.4' );

		$at_once = ScanJob::create( $target, $items );
		$at_once->run_batch( self::scanner(), 1000, INF );
		$this->assertTrue( $at_once->is_done() );

		$one_by_one = ScanJob::create( $target, $items );
		$batches    = 0;
		while ( ! $one_by_one->is_done() ) {
			$one_by_one = ScanJob::from_array( json_decode( (string) json_encode( $one_by_one->to_array() ), true ) );
			$one_by_one->run_batch( self::scanner(), 1, INF );
			++$batches;
		}

		$this->assertSame( 6 + 7 + 1, $batches, 'One batch per file.' );
		$this->assertSame( self::summary( $at_once ), self::summary( $one_by_one ) );

		$results = $at_once->results();
		$this->assertSame( ScanJob::ITEM_MISSING, $results[0]->status() );
		$this->assertSame( array( 13, 22 ), array( $results[1]->error_count(), $results[1]->warning_count() ) );
		$this->assertSame( array( 0, 0, 7 ), array( $results[2]->error_count(), $results[2]->warning_count(), $results[2]->files_found() ) );
		$this->assertSame( 12, $results[3]->error_count() );
	}

	/**
	 * A batch out of time still scans one file, so the scan always moves on.
	 */
	public function test_every_batch_scans_at_least_one_file(): void {
		$job = ScanJob::create( TargetVersion::from_string( '8.3' ), array( 'clean' => Fixtures::path( 'clean' ) ) );

		$this->assertSame( 1, $job->run_batch( self::scanner(), 50, 0.0 ) );
		$this->assertSame( 1, $job->run_batch( self::scanner(), 50, 0.0 ) );
		$this->assertSame( 2, $job->progress()['files_done'] );
		$this->assertSame( 5, $job->run_batch( self::scanner(), 50, INF ) );
		$this->assertTrue( $job->is_done() );
	}

	/**
	 * Missing items and items without PHP files don't stop the scan.
	 */
	public function test_handles_missing_and_empty_items(): void {
		$empty = Temp::dir();
		file_put_contents( $empty . '/readme.txt', 'No PHP here.' );

		$job = ScanJob::create(
			TargetVersion::from_string( '8.3' ),
			array(
				'missing' => $empty . '/nope',
				'empty'   => $empty,
				'single'  => Fixtures::path( 'clean/classic-plugin/classic-plugin.php' ),
			)
		);
		$job->run_batch( self::scanner(), 10, INF );

		$this->assertTrue( $job->is_done() );
		$results = $job->results();
		$this->assertSame( ScanJob::ITEM_MISSING, $results[0]->status() );
		$this->assertStringContainsString( 'nope', $results[0]->detail() );
		$this->assertSame( array( ScanJob::ITEM_DONE, 0 ), array( $results[1]->status(), $results[1]->files_found() ) );
		$this->assertSame( array( ScanJob::ITEM_DONE, 1, 1 ), array( $results[2]->status(), $results[2]->files_found(), $results[2]->files_processed() ) );
	}

	/**
	 * A file that kills the process is found by redoing the batch one file per
	 * request, and skipped when it kills the process again.
	 */
	public function test_skips_a_file_that_keeps_crashing_the_process(): void {
		$scanner = new CrashingScanner(
			array(
				'plugin' => array( 'a.php', 'b.php', 'c.php', 'd.php' ),
				'theme'  => array( 'e.php' ),
			),
			array( 'plugin/b.php' )
		);

		list( $outcomes, $job ) = $this->run_requests(
			$scanner,
			array(
				'plugin' => 'plugin',
				'theme'  => 'theme',
			)
		);

		// A normal batch dies at b.php. Then a.php gets a request of its own, and
		// so does b.php, which dies again. b.php is skipped, and the files after
		// it get a request each until the batch that died is covered.
		$this->assertSame( array( 'crashed', 'ok', 'crashed', 'ok', 'ok', 'ok' ), $outcomes );
		$this->assertSame( array( 'plugin/a.php', 'plugin/b.php', 'plugin/a.php', 'plugin/b.php', 'plugin/c.php', 'plugin/d.php', 'theme/e.php' ), $scanner->scanned );

		$reports = $job->results()[0]->reports();
		$this->assertCount( 1, $reports );
		$this->assertSame( array( 'b.php', FileReport::FAILED, FileReport::REASON_CRASHED ), array( $reports[0]->path(), $reports[0]->status(), $reports[0]->reason() ) );
		$this->assertSame( 4, $job->results()[0]->files_processed() );
		$this->assertSame( ScanJob::ITEM_DONE, $job->results()[1]->status() );
	}

	/**
	 * After the batch that died is covered, batches are full size again.
	 */
	public function test_goes_back_to_full_batches_after_a_crash(): void {
		$files   = array_map(
			static function ( int $n ): string {
				return 'file-' . $n . '.php';
			},
			range( 1, 30 )
		);
		$scanner = new CrashingScanner( array( 'plugin' => $files ), array( 'plugin/file-3.php' ) );

		list( $outcomes ) = $this->run_requests( $scanner, array( 'plugin' => 'plugin' ) );

		// Files 1 to 10 were in the batch that died, so each gets a request of
		// its own, and file 3 crashes again. The request for file 10 goes on
		// with a full batch, and one more request finishes the scan.
		$this->assertSame( array_merge( array( 'crashed', 'ok', 'ok', 'crashed' ), array_fill( 0, 7, 'ok' ), array( 'ok', 'ok' ) ), $outcomes );
		$this->assertCount( 30 + 3, $scanner->scanned, 'Files 1, 2 and 3 are scanned twice.' );
	}

	/**
	 * An item whose file listing kills the process is reported as missing.
	 */
	public function test_skips_an_item_whose_listing_keeps_crashing(): void {
		$scanner = new CrashingScanner( array( 'theme' => array( 'e.php' ) ), array( 'plugin' ) );

		list( $outcomes, $job ) = $this->run_requests(
			$scanner,
			array(
				'plugin' => 'plugin',
				'theme'  => 'theme',
			)
		);

		$this->assertSame( array( 'crashed', 'crashed', 'ok' ), $outcomes );
		$this->assertSame( ScanJob::ITEM_MISSING, $job->results()[0]->status() );
		$this->assertStringContainsString( 'listing the files', $job->results()[0]->detail() );
		$this->assertSame( ScanJob::ITEM_DONE, $job->results()[1]->status() );
	}

	/**
	 * Progress grows as items get listed.
	 */
	public function test_reports_progress(): void {
		$job = ScanJob::create( TargetVersion::from_string( '8.3' ), array( 'breaks' => Fixtures::path( 'breaks' ) ) );
		$this->assertSame(
			array(
				'items_total' => 1,
				'items_done'  => 0,
				'files_found' => 0,
				'files_done'  => 0,
				'current'     => 'breaks',
			),
			$job->progress()
		);

		$job->run_batch( self::scanner(), 2, INF );
		$this->assertSame( array( 6, 2 ), array( $job->progress()['files_found'], $job->progress()['files_done'] ) );
		$this->assertSame( ScanJob::ITEM_PENDING, $job->results()[0]->status() );

		$job->run_batch( self::scanner(), 10, INF );
		$this->assertSame( array( 1, null ), array( $job->progress()['items_done'], $job->progress()['current'] ) );
		$this->assertInstanceOf( ItemResult::class, $job->results()[0] );
	}

	/**
	 * Malformed saved states.
	 *
	 * @return array<string, array{callable}>
	 */
	public function malformed_states(): array {
		return array(
			'other format'               => array(
				static function ( array $state ): array {
					$state['format'] = 2;
					return $state;
				},
			),
			'unknown target'             => array(
				static function ( array $state ): array {
					$state['target'] = '9.9';
					return $state;
				},
			),
			'cursor past the end'        => array(
				static function ( array $state ): array {
					$state['current'] = 5;
					return $state;
				},
			),
			'pending item before cursor' => array(
				static function ( array $state ): array {
					$state['current'] = 1;
					return $state;
				},
			),
			'file index out of range'    => array(
				static function ( array $state ): array {
					$state['items'][0]['next'] = 99;
					return $state;
				},
			),
			'items not a list'           => array(
				static function ( array $state ): array {
					$state['items'] = array( 'a' => $state['items'][0] );
					return $state;
				},
			),
			'bad report'                 => array(
				static function ( array $state ): array {
					$state['items'][0]['reports'][] = array( 'path' => 'x.php' );
					return $state;
				},
			),
			'bad open batch'             => array(
				static function ( array $state ): array {
					$state['open_batch'] = array( 'at' => -1 );
					return $state;
				},
			),
		);
	}

	/**
	 * A malformed saved state is rejected, so the caller can start over.
	 *
	 * @dataProvider malformed_states
	 *
	 * @param callable $corrupt Turns a valid state into a malformed one.
	 */
	public function test_rejects_malformed_saved_state( callable $corrupt ): void {
		$job = ScanJob::create( TargetVersion::from_string( '8.3' ), array( 'breaks' => Fixtures::path( 'breaks' ) ) );
		$job->run_batch( self::scanner(), 1, INF );
		ScanJob::from_array( $job->to_array() );

		$this->expectException( InvalidArgumentException::class );
		ScanJob::from_array( $corrupt( $job->to_array() ) );
	}

	/**
	 * Runs batches of 10 files the way a host would, one per request, until
	 * the scan is done. When a batch crashes, only its last checkpoint
	 * survives, as when PHP dies.
	 *
	 * @param CrashingScanner       $scanner The fake scanner.
	 * @param array<string, string> $items   Items to scan.
	 * @return array{0: string[], 1: ScanJob} What happened in each request ("ok" or "crashed"), and the finished job.
	 */
	private function run_requests( CrashingScanner $scanner, array $items ): array {
		$saved = ScanJob::create( TargetVersion::from_string( '8.3' ), $items )->to_array();
		$save  = static function ( array $state ) use ( &$saved ) {
			$saved = $state;
		};

		$outcomes = array();
		do {
			$job = ScanJob::from_array( $saved );
			try {
				$job->run_batch( $scanner, 10, INF, $save );
				$saved      = $job->to_array();
				$outcomes[] = 'ok';
			} catch ( RuntimeException $e ) {
				$this->assertSame( 'Simulated crash', $e->getMessage() );
				$outcomes[] = 'crashed';
			}

			$this->assertLessThan( 50, count( $outcomes ), 'The scan must finish.' );
		} while ( ! ScanJob::from_array( $saved )->is_done() );

		return array( $outcomes, ScanJob::from_array( $saved ) );
	}

	/**
	 * Results as plain arrays, for comparison.
	 *
	 * @param ScanJob $job A finished job.
	 * @return array<int, array<string, mixed>>
	 */
	private static function summary( ScanJob $job ): array {
		$summary = array();
		foreach ( $job->results() as $result ) {
			$summary[] = array(
				'id'        => $result->id(),
				'status'    => $result->status(),
				'found'     => $result->files_found(),
				'processed' => $result->files_processed(),
				'reports'   => array_map(
					static function ( FileReport $report ): array {
						return $report->to_array();
					},
					$result->reports()
				),
			);
		}

		return $summary;
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

<?php
/**
 * A resumable scan of several plugins and themes.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * Scans items in small batches, one batch per request or cron tick, so a scan
 * never runs into max_execution_time. The whole state converts to an array of
 * scalars between batches, for storage in an option or a file.
 *
 * Running out of memory or time is fatal and can't be caught, so the job hands
 * its state to a checkpoint callback before risky work. If a batch never
 * finished, the next batches redo its files one per batch, each in a fresh
 * request, and a file that stops the process again is skipped as crashed.
 */
final class ScanJob {

	public const ITEM_PENDING = 'pending';
	public const ITEM_DONE    = 'done';
	public const ITEM_MISSING = 'missing';

	/**
	 * Version of the to_array() format.
	 */
	private const FORMAT = 1;

	/**
	 * The PHP version to check against.
	 *
	 * @var TargetVersion
	 */
	private $target;

	/**
	 * State per item, in scan order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $items;

	/**
	 * Index of the item being scanned.
	 *
	 * @var int
	 */
	private $current = 0;

	/**
	 * Files processed so far, across all items.
	 *
	 * @var int
	 */
	private $processed = 0;

	/**
	 * While fewer files than this are processed, scan one file per batch with a
	 * checkpoint before it, because a batch covering them never finished.
	 *
	 * @var int
	 */
	private $careful_until = 0;

	/**
	 * The batch in progress: where it started, how many files it may process,
	 * and whether it's a careful one. Still set in a saved state if the
	 * process died.
	 *
	 * @var array{at: int, size: int, careful: bool}|null
	 */
	private $open_batch;

	/**
	 * Use create() or from_array() instead.
	 *
	 * @param TargetVersion                    $target The PHP version to check against.
	 * @param array<int, array<string, mixed>> $items  State per item.
	 */
	private function __construct( TargetVersion $target, array $items ) {
		$this->target = $target;
		$this->items  = $items;
	}

	/**
	 * Starts a new scan.
	 *
	 * @param TargetVersion         $target The PHP version to check against.
	 * @param array<string, string> $items  Paths to scan, keyed by an identifier
	 *                                      of the caller's choice, such as a plugin
	 *                                      basename. Each path is a plugin or theme
	 *                                      directory or a single-file plugin.
	 * @return self
	 */
	public static function create( TargetVersion $target, array $items ): self {
		$state = array();
		foreach ( $items as $id => $path ) {
			$state[] = array(
				'id'      => (string) $id,
				'path'    => (string) $path,
				'status'  => self::ITEM_PENDING,
				'detail'  => '',
				'root'    => '',
				'files'   => null,
				'found'   => 0,
				'next'    => 0,
				'reports' => array(),
			);
		}

		return new self( $target, $state );
	}

	/**
	 * The PHP version this scan checks against.
	 *
	 * @return TargetVersion
	 */
	public function target(): TargetVersion {
		return $this->target;
	}

	/**
	 * Whether every item has been scanned.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return $this->current >= count( $this->items );
	}

	/**
	 * Scans the next few files.
	 *
	 * Always processes at least one file, so every batch makes progress.
	 *
	 * @param FileScanner   $scanner     The scanner.
	 * @param int           $max_files   Stop after this many files.
	 * @param float         $max_seconds Don't start another file after this many seconds.
	 * @param callable|null $checkpoint  Called with to_array() before risky work. Save
	 *                                   the state it gets, the same way as after the batch.
	 * @return int Number of files processed.
	 * @throws ScannerUnavailable If the scanner can't run at all.
	 */
	public function run_batch( FileScanner $scanner, int $max_files, float $max_seconds, ?callable $checkpoint = null ): int {
		$this->recover();

		$started   = microtime( true );
		$done      = 0;
		$max_files = max( 1, $max_files );
		$saved_as  = null; // Whether this batch's last checkpoint was a careful one.

		try {
			while ( $done < $max_files && ! $this->is_done() ) {
				// A file under suspicion gets a fresh request of its own, so if the
				// process dies again, it's that file's fault and not the clock's.
				$careful = $this->processed < $this->careful_until;
				if ( $done > 0 && ( $careful || microtime( true ) - $started >= $max_seconds ) ) {
					break;
				}

				if ( $careful || $saved_as !== $careful ) {
					$this->open_batch = array(
						'at'      => $this->processed,
						'size'    => $careful ? 1 : $max_files - $done,
						'careful' => $careful,
					);
					$saved_as         = $careful;
					if ( null !== $checkpoint ) {
						$checkpoint( $this->to_array() );
					}
				}

				if ( null === $this->items[ $this->current ]['files'] ) {
					$this->list_files( $scanner );
					continue;
				}

				$item = $this->items[ $this->current ];
				$this->record( $scanner->scan_file( $item['root'], $item['files'][ $item['next'] ], $this->target ) );
				++$done;
			}
		} finally {
			$this->open_batch = null;
		}

		return $done;
	}

	/**
	 * How far the scan has come. Files are listed one item at a time, so
	 * "files_found" grows as the scan reaches new items.
	 *
	 * @return array{items_total: int, items_done: int, files_found: int, files_done: int, current: string|null}
	 */
	public function progress(): array {
		$found = 0;
		foreach ( $this->items as $item ) {
			$found += $item['found'];
		}

		return array(
			'items_total' => count( $this->items ),
			'items_done'  => min( $this->current, count( $this->items ) ),
			'files_found' => $found,
			'files_done'  => $this->processed,
			'current'     => $this->is_done() ? null : $this->items[ $this->current ]['id'],
		);
	}

	/**
	 * Results per item, in scan order. Items not reached yet are pending and empty.
	 *
	 * @return ItemResult[]
	 */
	public function results(): array {
		$results = array();
		foreach ( $this->items as $item ) {
			$reports = array();
			foreach ( $item['reports'] as $report ) {
				$reports[] = FileReport::from_array( $report );
			}

			$results[] = new ItemResult( $item['id'], $item['path'], $item['status'], $item['detail'], $item['found'], $item['next'], $reports );
		}

		return $results;
	}

	/**
	 * Converts the state to an array of scalars, for storage between batches.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'format'        => self::FORMAT,
			'target'        => $this->target->to_string(),
			'current'       => $this->current,
			'processed'     => $this->processed,
			'careful_until' => $this->careful_until,
			'open_batch'    => $this->open_batch,
			'items'         => $this->items,
		);
	}

	/**
	 * Restores a job saved with to_array().
	 *
	 * @param array<string, mixed> $state Saved state.
	 * @return self
	 * @throws InvalidArgumentException If the state is malformed or from another format version.
	 */
	public static function from_array( array $state ): self {
		if ( ( $state['format'] ?? null ) !== self::FORMAT ) {
			throw new InvalidArgumentException( 'The saved scan is from another version of the scanner.' );
		}

		if ( ! is_string( $state['target'] ?? null )
			|| ! self::is_count( $state['current'] ?? null )
			|| ! self::is_count( $state['processed'] ?? null )
			|| ! self::is_count( $state['careful_until'] ?? null )
			|| ! is_array( $state['items'] ?? null )
			|| array_values( $state['items'] ) !== $state['items']
			|| $state['current'] > count( $state['items'] )
		) {
			throw new InvalidArgumentException( 'The saved scan is malformed.' );
		}

		$items = array();
		foreach ( $state['items'] as $index => $item ) {
			$item = self::check_item( $item );

			// Items before the current one are finished, the rest are pending.
			if ( ( self::ITEM_PENDING === $item['status'] ) !== ( $index >= $state['current'] ) ) {
				throw new InvalidArgumentException( 'The saved scan is malformed.' );
			}

			$items[] = $item;
		}

		$open_batch = $state['open_batch'] ?? null;
		if ( null !== $open_batch
			&& ( ! is_array( $open_batch )
			|| ! self::is_count( $open_batch['at'] ?? null )
			|| ! self::is_count( $open_batch['size'] ?? null )
			|| ! is_bool( $open_batch['careful'] ?? null ) )
		) {
			throw new InvalidArgumentException( 'The saved scan is malformed.' );
		}

		$job                = new self( TargetVersion::from_string( $state['target'] ), $items );
		$job->current       = $state['current'];
		$job->processed     = $state['processed'];
		$job->careful_until = $state['careful_until'];
		$job->open_batch    = $open_batch;

		return $job;
	}

	/**
	 * Deals with a batch that never finished because the process died.
	 */
	private function recover(): void {
		$batch            = $this->open_batch;
		$this->open_batch = null;
		if ( null === $batch || $this->is_done() ) {
			return;
		}

		if ( ! $batch['careful'] ) {
			// Redo the batch one file per request, to find the file that stops the process.
			$this->careful_until = max( $this->careful_until, $batch['at'] + $batch['size'] );
			return;
		}

		$item    = $this->items[ $this->current ];
		$message = 'The server stopped while scanning this file, probably because it ran out of memory or time.';
		if ( null === $item['files'] ) {
			$this->finish_item( self::ITEM_MISSING, 'The server stopped while listing the files, probably because it ran out of memory or time.' );
			return;
		}

		$this->record( FileReport::failed( $item['files'][ $item['next'] ], FileReport::REASON_CRASHED, $message ) );
	}

	/**
	 * Lists the current item's files, and moves on if there are none.
	 *
	 * @param FileScanner $scanner The scanner.
	 */
	private function list_files( FileScanner $scanner ): void {
		try {
			$found = $scanner->find_files( $this->items[ $this->current ]['path'] );
		} catch ( InvalidArgumentException $e ) {
			$this->finish_item( self::ITEM_MISSING, $e->getMessage() );
			return;
		}

		$this->items[ $this->current ]['root']  = $found['root'];
		$this->items[ $this->current ]['files'] = $found['files'];
		$this->items[ $this->current ]['found'] = count( $found['files'] );

		if ( array() === $found['files'] ) {
			$this->finish_item( self::ITEM_DONE, '' );
		}
	}

	/**
	 * Stores the report for the current file and moves to the next one.
	 *
	 * @param FileReport $report The report.
	 */
	private function record( FileReport $report ): void {
		if ( ! $report->is_clean() ) {
			$this->items[ $this->current ]['reports'][] = $report->to_array();
		}

		++$this->items[ $this->current ]['next'];
		++$this->processed;

		if ( $this->items[ $this->current ]['next'] >= $this->items[ $this->current ]['found'] ) {
			$this->finish_item( self::ITEM_DONE, '' );
		}
	}

	/**
	 * Marks the current item as finished and moves to the next one.
	 *
	 * @param string $status ScanJob::ITEM_DONE or ScanJob::ITEM_MISSING.
	 * @param string $detail Why the item is missing, or "".
	 */
	private function finish_item( string $status, string $detail ): void {
		$this->items[ $this->current ]['status'] = $status;
		$this->items[ $this->current ]['detail'] = $detail;
		// The file list is only needed while scanning, so don't keep storing it.
		$this->items[ $this->current ]['files'] = array();
		++$this->current;
	}

	/**
	 * Validates one item's saved state.
	 *
	 * @param mixed $item Saved item state.
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException If the item is malformed.
	 */
	private static function check_item( $item ): array {
		if ( ! is_array( $item )
			|| ! is_string( $item['id'] ?? null )
			|| ! is_string( $item['path'] ?? null )
			|| ! in_array( $item['status'] ?? null, array( self::ITEM_PENDING, self::ITEM_DONE, self::ITEM_MISSING ), true )
			|| ! is_string( $item['detail'] ?? null )
			|| ! is_string( $item['root'] ?? null )
			|| ! array_key_exists( 'files', $item )
			|| ! ( null === $item['files'] || is_array( $item['files'] ) )
			|| ! self::is_count( $item['found'] ?? null )
			|| ! self::is_count( $item['next'] ?? null )
			|| ! is_array( $item['reports'] ?? null )
		) {
			throw new InvalidArgumentException( 'The saved scan is malformed.' );
		}

		if ( null === $item['files'] ) {
			// Not listed yet, so nothing scanned yet.
			if ( self::ITEM_PENDING !== $item['status'] || 0 !== $item['next'] ) {
				throw new InvalidArgumentException( 'The saved scan is malformed.' );
			}
		} else {
			foreach ( $item['files'] as $file ) {
				if ( ! is_string( $file ) ) {
					throw new InvalidArgumentException( 'The saved scan is malformed.' );
				}
			}

			if ( self::ITEM_PENDING === $item['status']
				&& ( array_values( $item['files'] ) !== $item['files'] || count( $item['files'] ) !== $item['found'] || $item['next'] >= $item['found'] )
			) {
				throw new InvalidArgumentException( 'The saved scan is malformed.' );
			}
		}

		foreach ( $item['reports'] as $report ) {
			FileReport::from_array( is_array( $report ) ? $report : array() );
		}

		return $item;
	}

	/**
	 * Whether a value is a non-negative integer.
	 *
	 * @param mixed $value The value.
	 * @return bool
	 */
	private static function is_count( $value ): bool {
		return is_int( $value ) && $value >= 0;
	}
}

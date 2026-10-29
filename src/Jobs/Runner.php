<?php
/**
 * Runs the scan, one batch per request.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Jobs;

use InvalidArgumentException;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\ScannerUnavailable;
use UpgradeRadar\Scanner\ScanJob;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Drives a ScanJob from the admin page's AJAX calls while the page is open,
 * and from WP-Cron otherwise, so a scan resumes when the page is closed.
 */
final class Runner {

	public const CRON_HOOK = 'upgrade_radar_scan_batch';

	public const RUNNING = 'running';
	public const PAUSED  = 'paused';
	public const DONE    = 'done';

	/** Problems that pause the scan, as stored in the scan. */
	public const OTHER_COPY_LOADED  = 'other_copy_loaded';
	public const MISSING_EXTENSIONS = 'missing_extensions';
	public const BROKEN_INSTALL     = 'broken_install';
	public const BAD_STATE          = 'bad_state';

	/**
	 * Version of the stored scan's format.
	 */
	private const FORMAT = 1;

	/**
	 * Files per batch. The time budget usually ends a batch first.
	 */
	private const BATCH_FILES = 500;

	/**
	 * Longest a batch may run, in seconds.
	 */
	private const BATCH_SECONDS = 15;

	/**
	 * While the admin page has run a batch this recently (seconds), WP-Cron
	 * leaves the scan to it.
	 */
	private const PAGE_ACTIVE = 60;

	/**
	 * Batches in a row that may find another plugin's PHP_CodeSniffer loaded
	 * before the scan pauses. Within one WP-Cron request this can be a
	 * coincidence, but a plugin that loads it on every request won't go away.
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Starts a new scan of the active plugins and theme, replacing any other.
	 *
	 * @param TargetVersion $target The PHP version to check against.
	 * @return array<string, mixed> The new scan.
	 */
	public static function start( TargetVersion $target ): array {
		$items = Inventory::active();
		$paths = array();
		foreach ( $items as $id => $item ) {
			$paths[ $id ] = $item['path'];
		}

		$scan = array(
			'format'   => self::FORMAT,
			'status'   => self::RUNNING,
			'target'   => $target->to_string(),
			'started'  => time(),
			'finished' => 0,
			'seconds'  => 0.0,
			'batches'  => 0,
			'page_at'  => 0,
			'problem'  => null,
			'items'    => $items,
			'job'      => ScanJob::create( $target, $paths )->to_array(),
		);

		// A batch of the scan this one replaces may still be running.
		Store::break_lock();
		Store::save( $scan );
		self::unschedule();
		self::schedule( self::PAGE_ACTIVE );

		return $scan;
	}

	/**
	 * Stops and forgets the scan.
	 */
	public static function cancel(): void {
		Store::break_lock();
		Store::delete();
		self::unschedule();
	}

	/**
	 * Continues a paused scan.
	 */
	public static function resume(): void {
		$scan = self::scan();
		if ( null !== $scan && self::PAUSED === $scan['status'] ) {
			$scan['status']  = self::RUNNING;
			$scan['problem'] = null;
			Store::save( $scan );
			self::schedule( self::PAGE_ACTIVE );
		}
	}

	/**
	 * The stored scan, if it's in a format this version understands.
	 *
	 * @param bool $fresh Read it from the database, not from this request's cache.
	 * @return array<string, mixed>|null
	 */
	public static function scan( bool $fresh = false ): ?array {
		$scan = Store::get( $fresh );

		return null !== $scan && self::FORMAT === ( $scan['format'] ?? null ) ? $scan : null;
	}

	/**
	 * Runs one batch, if a scan is running and no other request is working on it.
	 *
	 * @param bool $from_page Whether the admin page asked, rather than WP-Cron.
	 * @return array{scan: array<string, mixed>|null, busy: bool} The scan afterwards, and
	 *         whether another request was working on it.
	 */
	public static function run_batch( bool $from_page ): array {
		$scan = self::scan();
		if ( null === $scan || self::RUNNING !== $scan['status'] ) {
			return array(
				'scan' => $scan,
				'busy' => false,
			);
		}

		if ( ! $from_page && time() - (int) $scan['page_at'] < self::PAGE_ACTIVE ) {
			self::schedule( self::PAGE_ACTIVE );
			return array(
				'scan' => $scan,
				'busy' => true,
			);
		}

		$token = Store::lock( self::lock_seconds() );
		if ( '' === $token ) {
			return array(
				'scan' => $scan,
				'busy' => true,
			);
		}

		// Unlock even if the batch ends in a fatal error. ScanJob deals with the batch.
		register_shutdown_function( array( Store::class, 'unlock' ), $token );

		try {
			// Another request may have changed the scan before this one got the lock.
			$scan = self::scan( true );
			if ( null !== $scan && self::RUNNING === $scan['status'] ) {
				$scan = self::batch( $scan, $token, $from_page );
			}
		} finally {
			Store::unlock( $token );
		}

		return array(
			'scan' => $scan,
			'busy' => false,
		);
	}

	/**
	 * Runs a batch for WP-Cron.
	 */
	public static function run_from_cron(): void {
		self::run_batch( false );
	}

	/**
	 * Removes the scheduled WP-Cron batch.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Runs one batch of the scan, holding the lock.
	 *
	 * @param array<string, mixed> $scan      The running scan.
	 * @param string               $token     The lock token.
	 * @param bool                 $from_page Whether the admin page asked.
	 * @return array<string, mixed>|null The scan afterwards.
	 */
	private static function batch( array $scan, string $token, bool $from_page ): ?array {
		$started = microtime( true );
		if ( $from_page ) {
			$scan['page_at'] = time();
		}

		try {
			$job = ScanJob::from_array( (array) $scan['job'] );
			self::check_paths( (array) $scan['job'] );
		} catch ( InvalidArgumentException $e ) {
			return self::stop( $scan, $token, self::BAD_STATE, $e->getMessage() );
		}

		// Scans need more memory than most requests. WordPress raises the limit
		// on admin screens, but not for AJAX or WP-Cron.
		wp_raise_memory_limit( 'admin' );

		$checkpoint = static function ( array $state ) use ( $scan, $token ): void {
			if ( Store::holds_lock( $token ) ) {
				$scan['job'] = $state;
				Store::save( $scan );
			}
		};

		try {
			$job->run_batch( new Scanner(), self::BATCH_FILES, self::time_budget(), $checkpoint );
			$scan['problem'] = null;
		} catch ( ScannerUnavailable $e ) {
			$scan['problem'] = self::problem( $e, $scan['problem'] );
			if ( self::OTHER_COPY_LOADED !== $scan['problem']['code'] || $scan['problem']['attempts'] >= self::MAX_ATTEMPTS ) {
				$scan['status'] = self::PAUSED;
			}
		}

		$scan['job']      = $job->to_array();
		$scan['batches'] += 1;
		$scan['seconds'] += microtime( true ) - $started;

		if ( $job->is_done() ) {
			$scan['status']   = self::DONE;
			$scan['finished'] = time();
		}

		return self::finish( $scan, $token, $from_page );
	}

	/**
	 * Pauses the scan because of a problem a retry won't fix.
	 *
	 * @param array<string, mixed> $scan   The scan.
	 * @param string               $token  The lock token.
	 * @param string               $code   One of the problem constants.
	 * @param string               $detail What went wrong.
	 * @return array<string, mixed>|null The scan as stored now.
	 */
	private static function stop( array $scan, string $token, string $code, string $detail ): ?array {
		$scan['status']  = self::PAUSED;
		$scan['problem'] = array(
			'code'     => $code,
			'detail'   => $detail,
			'attempts' => 1,
		);

		return self::finish( $scan, $token, false );
	}

	/**
	 * Saves the scan after a batch, and schedules the next one if needed.
	 *
	 * @param array<string, mixed> $scan      The scan.
	 * @param string               $token     The lock token.
	 * @param bool                 $from_page Whether the admin page asked.
	 * @return array<string, mixed>|null The scan as stored now.
	 */
	private static function finish( array $scan, string $token, bool $from_page ): ?array {
		// The scan was cancelled or replaced during the batch.
		if ( ! Store::holds_lock( $token ) ) {
			return self::scan( true );
		}

		if ( self::RUNNING === $scan['status'] ) {
			// While the page is open, this only matters if it closes.
			self::schedule( $from_page ? self::PAGE_ACTIVE : 0 );
		} else {
			self::unschedule();
		}
		Store::save( $scan );

		return $scan;
	}

	/**
	 * Records why the scanner couldn't run.
	 *
	 * @param ScannerUnavailable $e        The problem.
	 * @param mixed              $previous The previous batch's problem, if any.
	 * @return array{code: string, detail: string, attempts: int}
	 */
	private static function problem( ScannerUnavailable $e, $previous ): array {
		$codes = array(
			ScannerUnavailable::OTHER_COPY_LOADED  => self::OTHER_COPY_LOADED,
			ScannerUnavailable::MISSING_EXTENSIONS => self::MISSING_EXTENSIONS,
		);
		$code  = $codes[ $e->getCode() ] ?? self::BROKEN_INSTALL;

		$attempts = 1;
		if ( is_array( $previous ) && ( $previous['code'] ?? '' ) === $code ) {
			$attempts = (int) $previous['attempts'] + 1;
		}

		return array(
			'code'     => $code,
			'detail'   => self::OTHER_COPY_LOADED === $code ? $e->other_copy_path() : $e->getMessage(),
			'attempts' => $attempts,
		);
	}

	/**
	 * Makes sure the stored scan only reads where scans may read, in case the
	 * option was changed.
	 *
	 * @param array<string, mixed> $job Stored ScanJob state.
	 * @throws InvalidArgumentException If an item is somewhere else.
	 */
	private static function check_paths( array $job ): void {
		foreach ( (array) $job['items'] as $item ) {
			if ( ! Inventory::is_allowed( (string) ( $item['path'] ?? '' ) ) ) {
				throw new InvalidArgumentException( 'The saved scan points outside the plugins and themes directories.' );
			}
		}
	}

	/**
	 * Seconds this batch may scan for: half of what's left of the time limit,
	 * and never more than BATCH_SECONDS.
	 *
	 * @return float
	 */
	private static function time_budget(): float {
		$limit = (int) ini_get( 'max_execution_time' );
		// Without a PHP limit, web servers and proxies still cut requests off.
		$limit = $limit > 0 ? $limit : 60;

		return (float) max( 1, min( self::BATCH_SECONDS, ( $limit - timer_float() ) / 2 ) );
	}

	/**
	 * How long a lock lasts if its request dies: longer than any request runs.
	 *
	 * @return int Seconds.
	 */
	private static function lock_seconds(): int {
		$limit = (int) ini_get( 'max_execution_time' );

		return $limit > 0 ? $limit + 30 : 300;
	}

	/**
	 * Schedules a WP-Cron batch, unless one is scheduled already.
	 *
	 * @param int $delay Seconds from now.
	 */
	private static function schedule( int $delay ): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}
}

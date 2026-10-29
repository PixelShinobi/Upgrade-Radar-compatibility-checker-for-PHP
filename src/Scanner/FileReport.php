<?php
/**
 * The outcome of scanning one file.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * Either the issues found in a file, or why the file wasn't scanned.
 */
final class FileReport {

	public const SCANNED = 'scanned';
	public const SKIPPED = 'skipped';
	public const FAILED  = 'failed';

	/** Skipped: the file needs more memory or time than the server allows. */
	public const REASON_TOO_LARGE = 'too_large';

	/** Skipped: the path leads outside the plugin or theme directory. */
	public const REASON_OUTSIDE_ROOT = 'outside_root';

	/** Failed: the file vanished or can't be read. */
	public const REASON_UNREADABLE = 'unreadable';

	/** Failed: PHP_CodeSniffer couldn't process the file. */
	public const REASON_SCAN_ERROR = 'scan_error';

	/** Failed: the PHP process died while scanning the file, twice. */
	public const REASON_CRASHED = 'crashed';

	private const REASONS = array(
		self::SKIPPED => array( self::REASON_TOO_LARGE, self::REASON_OUTSIDE_ROOT ),
		self::FAILED  => array( self::REASON_UNREADABLE, self::REASON_SCAN_ERROR, self::REASON_CRASHED ),
	);

	/**
	 * Path relative to the plugin or theme directory, with "/" separators.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * One of the status constants.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * One of the REASON_* constants, or "" for scanned files.
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * A sentence explaining the reason, or "" for scanned files.
	 *
	 * @var string
	 */
	private $detail;

	/**
	 * Issues found, sorted by line and column.
	 *
	 * @var Issue[]
	 */
	private $issues;

	/**
	 * Use the named constructors instead.
	 *
	 * @param string  $path   Relative path.
	 * @param string  $status One of the status constants.
	 * @param string  $reason One of the REASON_* constants, or "".
	 * @param string  $detail A sentence explaining the reason, or "".
	 * @param Issue[] $issues Issues found.
	 */
	private function __construct( string $path, string $status, string $reason, string $detail, array $issues ) {
		$this->path   = $path;
		$this->status = $status;
		$this->reason = $reason;
		$this->detail = $detail;
		$this->issues = $issues;
	}

	/**
	 * A file that was scanned.
	 *
	 * @param string  $path   Relative path.
	 * @param Issue[] $issues Issues found, if any.
	 * @return self
	 */
	public static function scanned( string $path, array $issues ): self {
		usort(
			$issues,
			static function ( Issue $a, Issue $b ): int {
				return array( $a->line(), $a->column(), $a->source() ) <=> array( $b->line(), $b->column(), $b->source() );
			}
		);

		return new self( $path, self::SCANNED, '', '', $issues );
	}

	/**
	 * A file the scanner chose not to scan.
	 *
	 * @param string $path   Relative path.
	 * @param string $reason REASON_TOO_LARGE or REASON_OUTSIDE_ROOT.
	 * @param string $detail A sentence explaining why.
	 * @return self
	 */
	public static function skipped( string $path, string $reason, string $detail ): self {
		self::check_reason( self::SKIPPED, $reason );
		return new self( $path, self::SKIPPED, $reason, $detail, array() );
	}

	/**
	 * A file the scanner tried and failed to scan.
	 *
	 * @param string $path   Relative path.
	 * @param string $reason REASON_UNREADABLE, REASON_SCAN_ERROR or REASON_CRASHED.
	 * @param string $detail A sentence explaining what went wrong.
	 * @return self
	 */
	public static function failed( string $path, string $reason, string $detail ): self {
		self::check_reason( self::FAILED, $reason );
		return new self( $path, self::FAILED, $reason, $detail, array() );
	}

	/**
	 * Path relative to the plugin or theme directory, with "/" separators.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * FileReport::SCANNED, FileReport::SKIPPED or FileReport::FAILED.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Why the file was skipped or failed: one of the REASON_* constants, or "".
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * A sentence explaining the reason, or "".
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * Issues found, sorted by line and column.
	 *
	 * @return Issue[]
	 */
	public function issues(): array {
		return $this->issues;
	}

	/**
	 * Number of errors found, not counting possible false alarms.
	 *
	 * @return int
	 */
	public function error_count(): int {
		return count(
			array_filter(
				$this->issues,
				static function ( Issue $issue ): bool {
					return $issue->is_error() && ! $issue->is_possible_false_alarm();
				}
			)
		);
	}

	/**
	 * Number of warnings found, not counting possible false alarms.
	 *
	 * @return int
	 */
	public function warning_count(): int {
		return count( $this->issues ) - $this->error_count() - $this->false_alarm_count();
	}

	/**
	 * Number of findings, errors or warnings, that are possible false alarms.
	 *
	 * @return int
	 */
	public function false_alarm_count(): int {
		return count(
			array_filter(
				$this->issues,
				static function ( Issue $issue ): bool {
					return $issue->is_possible_false_alarm();
				}
			)
		);
	}

	/**
	 * Whether the file was scanned and nothing was found.
	 *
	 * @return bool
	 */
	public function is_clean(): bool {
		return self::SCANNED === $this->status && array() === $this->issues;
	}

	/**
	 * Converts the report to an array of scalars, for storage or JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'path'   => $this->path,
			'status' => $this->status,
			'reason' => $this->reason,
			'detail' => $this->detail,
			'issues' => array_map(
				static function ( Issue $issue ): array {
					return $issue->to_array();
				},
				$this->issues
			),
		);
	}

	/**
	 * Restores a report saved with to_array().
	 *
	 * @param array<string, mixed> $data Saved data.
	 * @return self
	 * @throws InvalidArgumentException If the data is malformed.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['path'], $data['status'], $data['reason'], $data['detail'], $data['issues'] )
			|| ! is_string( $data['path'] )
			|| ! is_string( $data['status'] )
			|| ! is_string( $data['reason'] )
			|| ! is_string( $data['detail'] )
			|| ! is_array( $data['issues'] )
		) {
			throw new InvalidArgumentException( 'Malformed file report data.' );
		}

		if ( self::SCANNED === $data['status'] ) {
			$issues = array();
			foreach ( $data['issues'] as $issue ) {
				if ( ! is_array( $issue ) ) {
					throw new InvalidArgumentException( 'Malformed file report data.' );
				}
				$issues[] = Issue::from_array( $issue );
			}

			return self::scanned( $data['path'], $issues );
		}

		if ( self::SKIPPED === $data['status'] ) {
			return self::skipped( $data['path'], $data['reason'], $data['detail'] );
		}

		return self::failed( $data['path'], $data['reason'], $data['detail'] );
	}

	/**
	 * Throws unless the reason belongs to the status.
	 *
	 * @param string $status FileReport::SKIPPED or FileReport::FAILED.
	 * @param string $reason The reason to check.
	 * @throws InvalidArgumentException If the reason doesn't fit the status.
	 */
	private static function check_reason( string $status, string $reason ): void {
		if ( ! in_array( $reason, self::REASONS[ $status ], true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown reason "%s" for a %s file.', $reason, $status ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text, escaped where it's shown.
		}
	}
}

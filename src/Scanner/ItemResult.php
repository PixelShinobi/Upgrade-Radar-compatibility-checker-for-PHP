<?php
/**
 * Scan results for one plugin or theme.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

/**
 * What a ScanJob found for one item, so far.
 */
final class ItemResult {

	/**
	 * The caller's identifier, such as a plugin basename.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * The plugin or theme directory, or single-file plugin, that was scanned.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * ScanJob::ITEM_PENDING, ScanJob::ITEM_DONE or ScanJob::ITEM_MISSING.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Why the item is missing, or "".
	 *
	 * @var string
	 */
	private $detail;

	/**
	 * Number of files found to scan.
	 *
	 * @var int
	 */
	private $files_found;

	/**
	 * Number of files processed so far, including skipped and failed ones.
	 *
	 * @var int
	 */
	private $files_processed;

	/**
	 * Reports for files that weren't clean: with issues, skipped or failed.
	 *
	 * @var FileReport[]
	 */
	private $reports;

	/**
	 * Creates a result.
	 *
	 * @param string       $id              The caller's identifier.
	 * @param string       $path            The scanned path.
	 * @param string       $status          One of the ScanJob::ITEM_* constants.
	 * @param string       $detail          Why the item is missing, or "".
	 * @param int          $files_found     Number of files found.
	 * @param int          $files_processed Number of files processed so far.
	 * @param FileReport[] $reports         Reports for files that weren't clean.
	 */
	public function __construct( string $id, string $path, string $status, string $detail, int $files_found, int $files_processed, array $reports ) {
		$this->id              = $id;
		$this->path            = $path;
		$this->status          = $status;
		$this->detail          = $detail;
		$this->files_found     = $files_found;
		$this->files_processed = $files_processed;
		$this->reports         = $reports;
	}

	/**
	 * The caller's identifier, such as a plugin basename.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * The scanned path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * ScanJob::ITEM_PENDING, ScanJob::ITEM_DONE or ScanJob::ITEM_MISSING.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Why the item is missing, or "".
	 *
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}

	/**
	 * Number of files found to scan.
	 *
	 * @return int
	 */
	public function files_found(): int {
		return $this->files_found;
	}

	/**
	 * Number of files processed so far, including skipped and failed ones.
	 *
	 * @return int
	 */
	public function files_processed(): int {
		return $this->files_processed;
	}

	/**
	 * Reports for files with issues, and for skipped and failed files.
	 *
	 * @return FileReport[]
	 */
	public function reports(): array {
		return $this->reports;
	}

	/**
	 * Number of errors: code that breaks on the target version.
	 *
	 * @return int
	 */
	public function error_count(): int {
		return array_sum(
			array_map(
				static function ( FileReport $report ): int {
					return $report->error_count();
				},
				$this->reports
			)
		);
	}

	/**
	 * Number of warnings: code that is deprecated on the target version.
	 *
	 * @return int
	 */
	public function warning_count(): int {
		return array_sum(
			array_map(
				static function ( FileReport $report ): int {
					return $report->warning_count();
				},
				$this->reports
			)
		);
	}

	/**
	 * Reports for files that were skipped or failed, so weren't fully checked.
	 *
	 * @return FileReport[]
	 */
	public function unchecked_files(): array {
		return array_values(
			array_filter(
				$this->reports,
				static function ( FileReport $report ): bool {
					return FileReport::SCANNED !== $report->status();
				}
			)
		);
	}
}

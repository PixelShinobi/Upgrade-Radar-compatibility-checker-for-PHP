<?php
/**
 * What ScanJob needs from a scanner.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * Finds and scans the files of one plugin or theme.
 */
interface FileScanner {

	/**
	 * Lists the files to scan for a plugin or theme.
	 *
	 * @param string $path A plugin or theme directory, or a single-file plugin.
	 * @return array{root: string, files: string[]} The real path the files are
	 *         relative to, and the relative paths with "/" separators, sorted.
	 * @throws InvalidArgumentException If the path isn't a readable file or directory.
	 */
	public function find_files( string $path ): array;

	/**
	 * Scans one file. Problems with the file end up in the report.
	 *
	 * @param string        $root          The directory returned by find_files().
	 * @param string        $relative_path A path returned by find_files().
	 * @param TargetVersion $target        The PHP version to check against.
	 * @return FileReport
	 * @throws ScannerUnavailable If the scanner can't run at all.
	 */
	public function scan_file( string $root, string $relative_path, TargetVersion $target ): FileReport;
}

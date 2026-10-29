<?php
/**
 * A fake scanner that stops the "process" on chosen files.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use RuntimeException;
use UpgradeRadar\Scanner\FileReport;
use UpgradeRadar\Scanner\FileScanner;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Throws where a real scan would hit a fatal error, such as running out of
 * memory. Like a dead process, the job then only has its last checkpoint.
 */
final class CrashingScanner implements FileScanner {

	/**
	 * Files per item path.
	 *
	 * @var array<string, string[]>
	 */
	private $files;

	/**
	 * Paths, as "item path/file" or "item path", that crash.
	 *
	 * @var string[]
	 */
	private $crashes;

	/**
	 * Files scanned, as "item path/file", in order.
	 *
	 * @var string[]
	 */
	public $scanned = array();

	/**
	 * Creates the fake.
	 *
	 * @param array<string, string[]> $files   Files per item path.
	 * @param string[]                $crashes Files ("item path/file") or items ("item path") that crash.
	 */
	public function __construct( array $files, array $crashes ) {
		$this->files   = $files;
		$this->crashes = $crashes;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $path Item path.
	 * @return array{root: string, files: string[]}
	 * @throws RuntimeException When the item crashes.
	 */
	public function find_files( string $path ): array {
		if ( in_array( $path, $this->crashes, true ) ) {
			throw new RuntimeException( 'Simulated crash' );
		}

		return array(
			'root'  => $path,
			'files' => $this->files[ $path ],
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string        $root          Item root.
	 * @param string        $relative_path File.
	 * @param TargetVersion $target        Ignored.
	 * @return FileReport
	 * @throws RuntimeException When the file crashes.
	 */
	public function scan_file( string $root, string $relative_path, TargetVersion $target ): FileReport {
		$this->scanned[] = $root . '/' . $relative_path;
		if ( in_array( $root . '/' . $relative_path, $this->crashes, true ) ) {
			throw new RuntimeException( 'Simulated crash' );
		}

		return FileReport::scanned( $relative_path, array() );
	}
}

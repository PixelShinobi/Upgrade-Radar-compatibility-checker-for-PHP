<?php
/**
 * Throwaway directories for tests.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Creates directories that are deleted when the test process ends.
 */
final class Temp {

	/**
	 * Directories to delete at shutdown.
	 *
	 * @var string[]
	 */
	private static $dirs = array();

	/**
	 * Creates an empty directory.
	 *
	 * @return string Its real path.
	 */
	public static function dir(): string {
		$dir = sys_get_temp_dir() . '/upgrade-radar-tests-' . bin2hex( random_bytes( 6 ) );
		mkdir( $dir, 0777, true );

		if ( array() === self::$dirs ) {
			register_shutdown_function( array( self::class, 'clean_up' ) );
		}
		self::$dirs[] = $dir;

		return (string) realpath( $dir );
	}

	/**
	 * Deletes the directories, without following symlinks.
	 */
	public static function clean_up(): void {
		foreach ( self::$dirs as $dir ) {
			if ( ! is_dir( $dir ) ) {
				continue;
			}

			$entries = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);
			foreach ( $entries as $entry ) {
				if ( $entry->isDir() && ! $entry->isLink() ) {
					rmdir( $entry->getPathname() );
				} else {
					unlink( $entry->getPathname() );
				}
			}
			rmdir( $dir );
		}

		self::$dirs = array();
	}
}

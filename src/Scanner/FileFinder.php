<?php
/**
 * Finds the PHP files that belong to a plugin or theme.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use FilesystemIterator;
use InvalidArgumentException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Lists scannable files without leaving the directory it was given.
 */
final class FileFinder {

	public const DEFAULT_EXTENSIONS = array( 'php', 'inc', 'phtml' );

	/**
	 * Directory names that never contain code the site runs. Hidden
	 * directories, such as .git, are skipped too.
	 */
	private const SKIPPED_DIRECTORIES = array( 'node_modules' );

	/**
	 * Lowercase file extensions to scan.
	 *
	 * @var string[]
	 */
	private $extensions;

	/**
	 * Creates a finder.
	 *
	 * @param string[] $extensions File extensions to scan, without the dot.
	 */
	public function __construct( array $extensions = self::DEFAULT_EXTENSIONS ) {
		$this->extensions = array_map( 'strtolower', $extensions );
	}

	/**
	 * Lists the files to scan for a plugin or theme.
	 *
	 * Symlinks that lead outside the directory are left out, and symlinked
	 * directories aren't followed.
	 *
	 * @param string $path A plugin or theme directory, or a single-file plugin.
	 * @return array{root: string, files: string[]} The real path the files are
	 *         relative to, and the relative paths with "/" separators, sorted.
	 * @throws InvalidArgumentException If the path isn't a readable file or directory.
	 */
	public function find( string $path ): array {
		$real_path = realpath( $path );
		if ( false === $real_path || ! is_readable( $real_path ) ) {
			throw new InvalidArgumentException( sprintf( 'Not a readable file or directory: %s', $path ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text, escaped where it's shown.
		}

		if ( is_file( $real_path ) ) {
			$name = basename( $real_path );
			return array(
				'root'  => dirname( $real_path ),
				'files' => $this->is_scannable( $name ) ? array( $name ) : array(),
			);
		}

		$prefix   = rtrim( $real_path, '/\\' ) . DIRECTORY_SEPARATOR;
		$iterator = new RecursiveIteratorIterator(
			new RecursiveCallbackFilterIterator(
				new RecursiveDirectoryIterator( $real_path, FilesystemIterator::SKIP_DOTS ),
				function ( SplFileInfo $file, string $key, RecursiveDirectoryIterator $directory ): bool {
					// Symlinked directories have no children, as links aren't followed.
					if ( $directory->hasChildren() ) {
						$name = $file->getFilename();
						return '.' !== $name[0] && ! in_array( $name, self::SKIPPED_DIRECTORIES, true );
					}

					return $file->isFile() && $this->is_scannable( $file->getFilename() );
				}
			),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		$files = array();
		foreach ( $iterator as $file ) {
			$real_file = $file->getRealPath();
			if ( false === $real_file || 0 !== strpos( $real_file, $prefix ) ) {
				continue;
			}

			// Keyed by the real path, so a file linked twice is scanned once.
			$files[ str_replace( '\\', '/', substr( $real_file, strlen( $prefix ) ) ) ] = true;
		}

		$files = array_keys( $files );
		sort( $files, SORT_STRING );

		return array(
			'root'  => $real_path,
			'files' => array_map( 'strval', $files ),
		);
	}

	/**
	 * Whether a file name has one of the extensions to scan.
	 *
	 * @param string $name File name.
	 * @return bool
	 */
	private function is_scannable( string $name ): bool {
		return in_array( strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ), $this->extensions, true );
	}
}

<?php
/**
 * Thrown when the scanner can't run at all on this server.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use RuntimeException;
use Throwable;

/**
 * For problems that affect every file, such as a missing PHP extension or
 * another plugin's copy of PHP_CodeSniffer already being loaded. Problems with
 * a single file end up in its FileReport instead. The exception code says
 * which problem it is.
 */
final class ScannerUnavailable extends RuntimeException {

	/** Another plugin loaded its own copy of PHP_CodeSniffer or PHPCSUtils in this request. */
	public const OTHER_COPY_LOADED = 1;

	/** PHP extensions the scanner needs are missing. */
	public const MISSING_EXTENSIONS = 2;

	/** The scanner's own files are missing or broken. */
	public const BROKEN_INSTALL = 3;

	/**
	 * Where the other copy was loaded from, for OTHER_COPY_LOADED.
	 *
	 * @var string
	 */
	private $other_copy = '';

	/**
	 * Another plugin's copy of a package is already loaded.
	 *
	 * @param string $package Composer package name.
	 * @param string $path    File the other copy was loaded from, or "".
	 * @return self
	 */
	public static function other_copy_loaded( string $package, string $path ): self {
		$exception             = new self(
			sprintf(
				'Another copy of %s is already loaded (%s), probably by another plugin. Scans need their own copy to give reliable results.',
				$package,
				'' === $path ? 'unknown location' : $path
			),
			self::OTHER_COPY_LOADED
		);
		$exception->other_copy = $path;

		return $exception;
	}

	/**
	 * A broken or incomplete installation.
	 *
	 * @param string         $message  What's wrong.
	 * @param Throwable|null $previous The underlying problem, if any.
	 * @return self
	 */
	public static function broken_install( string $message, ?Throwable $previous = null ): self {
		return new self( $message, self::BROKEN_INSTALL, $previous );
	}

	/**
	 * File the other copy was loaded from, when the code is OTHER_COPY_LOADED.
	 *
	 * @return string Absolute path, or "".
	 */
	public function other_copy_path(): string {
		return $this->other_copy;
	}
}

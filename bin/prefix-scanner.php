<?php
/**
 * Prefixes the dependencies' names in src/Scanner for the release build, as
 * PHP-Scoper prefixes the dependencies themselves.
 *
 * Usage: php bin/prefix-scanner.php <prefix> <copy of src/Scanner>
 *
 * PHP-Scoper would reprint every file, dropping our coding standard and
 * moving comments off their lines, phpcs:ignore comments included. Our code
 * only uses the dependencies through qualified names and a class name in a
 * string, so this changes just those tokens. It stops if it finds a namespace
 * it doesn't know.
 *
 * Needs PHP 8.0 or later, which tokenizes qualified names as one token.
 *
 * @package UpgradeRadar
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// The namespaces of the packages PHP-Scoper prefixes (scoper.inc.php).
const UPGRADE_RADAR_PREFIXED = array( 'PHP_CodeSniffer', 'PHPCSUtils', 'PHPCompatibility' );

/**
 * Prefixes the PHP files in a directory, in place.
 *
 * @param string $prefix    The namespace prefix, such as "UpgradeRadarVendor".
 * @param string $directory The directory.
 * @return int Exit status.
 */
function upgrade_radar_prefix_scanner( string $prefix, string $directory ): int {
	if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $prefix ) || ! is_dir( $directory ) ) {
		fwrite( STDERR, "Usage: php bin/prefix-scanner.php <prefix> <directory>\n" );
		return 2;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		$code = '';
		foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
			if ( ! is_array( $token ) ) {
				$code .= $token;
				continue;
			}

			$text = upgrade_radar_prefix_token( token_name( $token[0] ), $token[1], $prefix );
			if ( null === $text ) {
				fwrite( STDERR, sprintf( "%s:%d: can't prefix %s.\n", $file->getPathname(), $token[2], $token[1] ) );
				return 1;
			}
			$code .= $text;
		}
		file_put_contents( $file->getPathname(), $code );
	}

	return 0;
}

/**
 * Prefixes one token if it names something in the prefixed packages.
 *
 * @param string $type   The token's name, such as "T_NAME_QUALIFIED".
 * @param string $text   The token's text.
 * @param string $prefix The namespace prefix.
 * @return string|null The token's new text, or null if it can't be prefixed,
 *                     such as a name in a namespace that's neither ours nor
 *                     in UPGRADE_RADAR_PREFIXED.
 */
function upgrade_radar_prefix_token( string $type, string $text, string $prefix ): ?string {
	$namespaces = implode( '|', UPGRADE_RADAR_PREFIXED );

	if ( 'T_NAME_QUALIFIED' === $type || 'T_NAME_FULLY_QUALIFIED' === $type ) {
		$name      = ltrim( $text, '\\' );
		$namespace = strstr( $name, '\\', true );
		if ( false === $namespace || 'UpgradeRadar' === $namespace ) {
			// A global name, such as \InvalidArgumentException, or one of ours.
			return $text;
		}
		if ( ! in_array( $namespace, UPGRADE_RADAR_PREFIXED, true ) ) {
			return null;
		}
		return substr( $text, 0, strlen( $text ) - strlen( $name ) ) . $prefix . '\\' . $name;
	}

	// A class name in a string, such as 'PHPCompatibility\Helpers\ScannedCode'.
	if ( 'T_CONSTANT_ENCAPSED_STRING' === $type && preg_match( '/^(["\'])(\\\\{0,2})(?:' . $namespaces . ')(\\\\{1,2})/', $text, $match ) ) {
		if ( '"' === $match[1] ) {
			// Backslashes mean more in double quotes. We don't need them.
			return null;
		}
		$start = strlen( $match[1] . $match[2] );
		return substr( $text, 0, $start ) . $prefix . $match[3] . substr( $text, $start );
	}

	return $text;
}

exit( (int) upgrade_radar_prefix_scanner( $argv[1] ?? '', $argv[2] ?? '' ) );

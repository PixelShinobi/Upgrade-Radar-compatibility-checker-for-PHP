<?php
/**
 * Checks that no PHP file in a directory calls a function that runs another
 * program, such as exec() or shell_exec(), or uses backticks. The release
 * build runs it on the zip's files, since Upgrade Radar promises it never
 * needs exec().
 *
 * Usage: php bin/check-no-exec.php <directory>
 *
 * It reads tokens, so comments, strings and methods such as $filter->exec()
 * don't count. It lists each call it finds and exits with 1.
 *
 * @package UpgradeRadar
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

// Functions that run another program.
const UPGRADE_RADAR_PROGRAM_FUNCTIONS = array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' );

/**
 * Lists the calls to other programs in a directory's PHP files.
 *
 * @param string $directory The directory.
 * @return int Exit status.
 */
function upgrade_radar_check_no_exec( string $directory ): int {
	if ( ! is_dir( $directory ) ) {
		fwrite( STDERR, "Usage: php bin/check-no-exec.php <directory>\n" );
		return 2;
	}

	$status = 0;
	$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		foreach ( upgrade_radar_program_calls( (string) file_get_contents( $file->getPathname() ) ) as $call ) {
			fwrite( STDERR, sprintf( "%s:%d: %s\n", $file->getPathname(), $call[0], $call[1] ) );
			$status = 1;
		}
	}

	return $status;
}

/**
 * The calls to other programs in some PHP code.
 *
 * @param string $code PHP code.
 * @return array<int, array{0: int, 1: string}> Line and description of each call.
 */
function upgrade_radar_program_calls( string $code ): array {
	$ignored = array( 'T_WHITESPACE', 'T_COMMENT', 'T_DOC_COMMENT' );
	$tokens  = array();
	foreach ( token_get_all( $code ) as $token ) {
		if ( ! is_array( $token ) || ! in_array( token_name( $token[0] ), $ignored, true ) ) {
			$tokens[] = $token;
		}
	}

	$calls    = array();
	$line     = 1;
	$backtick = false;
	foreach ( $tokens as $i => $token ) {
		if ( is_array( $token ) ) {
			$line = $token[2];
		}

		// Count each pair of backticks once, at the opening one.
		if ( '`' === $token ) {
			$backtick = ! $backtick;
			if ( $backtick ) {
				$calls[] = array( $line, 'backtick operator' );
			}
			continue;
		}

		if ( ! is_array( $token ) || ! in_array( token_name( $token[0] ), array( 'T_STRING', 'T_NAME_FULLY_QUALIFIED' ), true ) ) {
			continue;
		}

		$next = $tokens[ $i + 1 ] ?? '';
		$prev = $tokens[ $i - 1 ] ?? '';
		$name = strtolower( ltrim( $token[1], '\\' ) );
		if ( '(' !== $next || ! in_array( $name, UPGRADE_RADAR_PROGRAM_FUNCTIONS, true ) ) {
			continue;
		}

		// Methods, and declarations of functions or methods with these names.
		if ( is_array( $prev ) && in_array( token_name( $prev[0] ), array( 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_NEW' ), true ) ) {
			continue;
		}

		$calls[] = array( $token[2], $token[1] . '()' );
	}

	return $calls;
}

exit( (int) upgrade_radar_check_no_exec( $argv[1] ?? '' ) );

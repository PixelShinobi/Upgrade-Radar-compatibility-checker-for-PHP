<?php
declare(strict_types=1);

namespace Acme\Modern;

/**
 * Newer functions behind feature checks, as modern plugins do.
 */
function first_even( array $numbers ): ?int {
	if ( function_exists( 'array_find' ) ) {
		return array_find( $numbers, static fn ( int $n ): bool => 0 === $n % 2 );
	}

	foreach ( $numbers as $n ) {
		if ( 0 === $n % 2 ) {
			return $n;
		}
	}

	return null;
}

function is_json( string $text ): bool {
	if ( function_exists( 'json_validate' ) ) {
		return json_validate( $text );
	}

	json_decode( $text );
	return JSON_ERROR_NONE === json_last_error();
}

function escape( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

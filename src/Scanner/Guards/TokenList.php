<?php
/**
 * Read-only helpers over PHP_CodeSniffer's tokens for one file.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Guards;

/**
 * Works on the token arrays PHP_CodeSniffer builds, by token type name, so
 * it needs none of PHP_CodeSniffer's classes or constants.
 */
final class TokenList {

	/**
	 * Expressions with their own body, skipped as one unit.
	 */
	private const NESTED_BODIES = array( 'T_FN', 'T_CLOSURE', 'T_MATCH', 'T_ANON_CLASS' );

	/**
	 * PHP_CodeSniffer's tokens.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $tokens;

	/**
	 * Token positions by "line:column", built on first use.
	 *
	 * @var array<string, int>|null
	 */
	private $positions;

	/**
	 * Wraps a file's tokens.
	 *
	 * @param array<int, array<string, mixed>> $tokens PHP_CodeSniffer's tokens.
	 */
	public function __construct( array $tokens ) {
		$this->tokens = $tokens;
	}

	/**
	 * Number of tokens.
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->tokens );
	}

	/**
	 * A token.
	 *
	 * @param int $ptr Position.
	 * @return array<string, mixed>
	 */
	public function get( int $ptr ): array {
		return $this->tokens[ $ptr ] ?? array();
	}

	/**
	 * A token's type, such as "T_IF", or "" past the end.
	 *
	 * @param int $ptr Position.
	 * @return string
	 */
	public function type( int $ptr ): string {
		return (string) ( $this->tokens[ $ptr ]['type'] ?? '' );
	}

	/**
	 * A token's code as written.
	 *
	 * @param int $ptr Position.
	 * @return string
	 */
	public function content( int $ptr ): string {
		return (string) ( $this->tokens[ $ptr ]['content'] ?? '' );
	}

	/**
	 * Whether a token is whitespace, a comment or an annotation.
	 *
	 * @param int $ptr Position.
	 * @return bool
	 */
	public function is_empty( int $ptr ): bool {
		$type = $this->type( $ptr );

		return 'T_WHITESPACE' === $type
			|| 'T_COMMENT' === $type
			|| 0 === strpos( $type, 'T_DOC_COMMENT' )
			|| 0 === strpos( $type, 'T_PHPCS_' );
	}

	/**
	 * The next token that isn't empty, up to $end.
	 *
	 * @param int $ptr Position to start after.
	 * @param int $end Last position to look at.
	 * @return int Position, or -1.
	 */
	public function next( int $ptr, int $end ): int {
		$end = min( $end, $this->count() - 1 );
		for ( $i = $ptr + 1; $i <= $end; $i++ ) {
			if ( ! $this->is_empty( $i ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * The previous token that isn't empty, down to $start.
	 *
	 * @param int $ptr   Position to start before.
	 * @param int $start First position to look at.
	 * @return int Position, or -1.
	 */
	public function prev( int $ptr, int $start = 0 ): int {
		for ( $i = $ptr - 1; $i >= $start && $i >= 0; $i-- ) {
			if ( ! $this->is_empty( $i ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * Where a nested group that opens at $ptr ends: parentheses, brackets,
	 * braces, closures, arrow functions and match expressions. Returns $ptr
	 * for any other token.
	 *
	 * @param int $ptr Position.
	 * @return int
	 */
	public function skip( int $ptr ): int {
		$token = $this->get( $ptr );

		if ( isset( $token['parenthesis_opener'], $token['parenthesis_closer'] ) && $ptr === $token['parenthesis_opener'] ) {
			return (int) $token['parenthesis_closer'];
		}

		if ( isset( $token['bracket_opener'], $token['bracket_closer'] ) && $ptr === $token['bracket_opener'] ) {
			return (int) $token['bracket_closer'];
		}

		if ( isset( $token['scope_closer'] ) && in_array( $this->type( $ptr ), self::NESTED_BODIES, true ) ) {
			// An arrow function ends at the token after it, such as a ";" or ",".
			$end = (int) $token['scope_closer'] - ( 'T_FN' === $this->type( $ptr ) ? 1 : 0 );
			return max( $ptr, $end );
		}

		return $ptr;
	}

	/**
	 * Positions of tokens of the given types that aren't nested in a group
	 * within the range.
	 *
	 * @param int      $start First position.
	 * @param int      $end   Last position.
	 * @param string[] $types Token types.
	 * @return int[]
	 */
	public function find_top_level( int $start, int $end, array $types ): array {
		$found = array();
		for ( $i = $start; $i <= $end; $i++ ) {
			if ( in_array( $this->type( $i ), $types, true ) ) {
				$found[] = $i;
			}
			$i = $this->skip( $i );
		}

		return $found;
	}

	/**
	 * Splits a range at the given positions, leaving the positions out.
	 *
	 * @param int   $start First position.
	 * @param int   $end   Last position.
	 * @param int[] $at    Positions to split at, in order.
	 * @return array<int, array{0: int, 1: int}> Ranges, possibly empty (start > end).
	 */
	public static function split( int $start, int $end, array $at ): array {
		$parts = array();
		foreach ( $at as $position ) {
			$parts[] = array( $start, $position - 1 );
			$start   = $position + 1;
		}
		$parts[] = array( $start, $end );

		return $parts;
	}

	/**
	 * A range without the empty tokens at either end.
	 *
	 * @param int $start First position.
	 * @param int $end   Last position.
	 * @return array{0: int, 1: int} Empty when start > end.
	 */
	public function trim( int $start, int $end ): array {
		while ( $start <= $end && $this->is_empty( $start ) ) {
			++$start;
		}
		while ( $end >= $start && $this->is_empty( $end ) ) {
			--$end;
		}

		return array( $start, $end );
	}

	/**
	 * The token that starts at a line and column, if any.
	 *
	 * @param int $line   Line, starting at 1.
	 * @param int $column Column, starting at 1.
	 * @return int Position, or -1.
	 */
	public function find( int $line, int $column ): int {
		if ( null === $this->positions ) {
			$this->positions = array();
			foreach ( $this->tokens as $ptr => $token ) {
				$key = ( $token['line'] ?? 0 ) . ':' . ( $token['column'] ?? 0 );
				if ( ! isset( $this->positions[ $key ] ) || $this->is_empty( $this->positions[ $key ] ) ) {
					$this->positions[ $key ] = (int) $ptr;
				}
			}
		}

		return $this->positions[ $line . ':' . $column ] ?? -1;
	}

	/**
	 * The code in a range on one line, with whitespace and comments
	 * collapsed and long code shortened.
	 *
	 * @param int $start First position.
	 * @param int $end   Last position.
	 * @return string
	 */
	public function text( int $start, int $end ): string {
		$text = '';
		for ( $i = $start; $i <= $end; $i++ ) {
			$text .= $this->is_empty( $i ) ? ' ' : $this->content( $i );
		}

		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );
		if ( strlen( $text ) > 100 ) {
			// Cut on a character boundary.
			$text = (string) preg_replace( '/[\xC0-\xFF][\x80-\xBF]*$/', '', substr( $text, 0, 97 ) ) . '...';
		}

		return $text;
	}
}

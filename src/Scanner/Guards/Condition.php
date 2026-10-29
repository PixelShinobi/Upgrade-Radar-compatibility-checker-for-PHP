<?php
/**
 * Works out what a guard condition is on a PHP version.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Guards;

/**
 * Parses a condition into a small tree and evaluates it with three-valued
 * logic: true, false or unknown. Only these are understood: PHP version
 * checks (PHP_VERSION_ID and friends, phpversion(), version_compare()),
 * existence checks (function_exists(), is_callable(), class_exists(),
 * interface_exists(), defined()), literals, comparisons, !, &&, || and their
 * word forms. Anything else, such as a variable or another function call, is
 * unknown, and an unknown part can only make the whole condition unknown or
 * leave it as it is.
 */
final class Condition {

	/**
	 * Operators, loosest first. A range is split at the loosest one it holds.
	 * "unknown" marks operators whose result is never worked out.
	 */
	private const LADDER = array(
		array( 'or', array( 'T_LOGICAL_OR' ) ),
		array( 'xor', array( 'T_LOGICAL_XOR' ) ),
		array( 'and', array( 'T_LOGICAL_AND' ) ),
		array( 'assign', array( 'T_EQUAL' ) ),
		array( 'unknown', array( 'T_PLUS_EQUAL', 'T_MINUS_EQUAL', 'T_MUL_EQUAL', 'T_DIV_EQUAL', 'T_CONCAT_EQUAL', 'T_MOD_EQUAL', 'T_AND_EQUAL', 'T_OR_EQUAL', 'T_XOR_EQUAL', 'T_SL_EQUAL', 'T_SR_EQUAL', 'T_POW_EQUAL', 'T_COALESCE_EQUAL', 'T_DOUBLE_ARROW', 'T_MATCH_ARROW' ) ),
		array( 'unknown', array( 'T_INLINE_THEN', 'T_INLINE_ELSE', 'T_COALESCE' ) ),
		array( 'or', array( 'T_BOOLEAN_OR' ) ),
		array( 'and', array( 'T_BOOLEAN_AND' ) ),
		array( 'unknown', array( 'T_BITWISE_OR', 'T_BITWISE_XOR', 'T_BITWISE_AND' ) ),
		array( 'compare', array( 'T_IS_EQUAL', 'T_IS_NOT_EQUAL', 'T_IS_IDENTICAL', 'T_IS_NOT_IDENTICAL', 'T_SPACESHIP' ) ),
		array( 'compare', array( 'T_LESS_THAN', 'T_GREATER_THAN', 'T_IS_SMALLER_OR_EQUAL', 'T_IS_GREATER_OR_EQUAL' ) ),
		array( 'unknown', array( 'T_SL', 'T_SR', 'T_STRING_CONCAT', 'T_PLUS', 'T_MINUS', 'T_MULTIPLY', 'T_DIVIDE', 'T_MODULUS', 'T_POW', 'T_INSTANCEOF' ) ),
	);

	/**
	 * Comparison operators by token type.
	 */
	private const OPERATORS = array(
		'T_IS_EQUAL'            => '==',
		'T_IS_NOT_EQUAL'        => '!=',
		'T_IS_IDENTICAL'        => '===',
		'T_IS_NOT_IDENTICAL'    => '!==',
		'T_SPACESHIP'           => '<=>',
		'T_LESS_THAN'           => '<',
		'T_GREATER_THAN'        => '>',
		'T_IS_SMALLER_OR_EQUAL' => '<=',
		'T_IS_GREATER_OR_EQUAL' => '>=',
	);

	/**
	 * Functions that are understood, by lowercase name.
	 */
	private const FUNCTIONS = array( 'function_exists', 'is_callable', 'class_exists', 'interface_exists', 'defined', 'version_compare', 'phpversion' );

	/**
	 * Constants that are defined on every PHP version the plugin supports.
	 */
	private const ALWAYS_DEFINED = array( 'PHP_VERSION', 'PHP_VERSION_ID', 'PHP_MAJOR_VERSION', 'PHP_MINOR_VERSION', 'PHP_RELEASE_VERSION' );

	/**
	 * Release numbers checked for each target version: x.y.0 to x.y.99.
	 */
	private const LAST_RELEASE = 99;

	/**
	 * The file's tokens.
	 *
	 * @var TokenList
	 */
	private $code;

	/**
	 * Parses conditions in one file.
	 *
	 * @param TokenList $code The file's tokens.
	 */
	public function __construct( TokenList $code ) {
		$this->code = $code;
	}

	/**
	 * Parses the expression in a range of tokens.
	 *
	 * @param int $start First position.
	 * @param int $end   Last position.
	 * @return array<int, mixed> The tree: a node is a list whose first item is its kind.
	 */
	public function parse( int $start, int $end ): array {
		list( $start, $end ) = $this->code->trim( $start, $end );
		if ( $start > $end ) {
			return array( 'unknown' );
		}

		foreach ( self::LADDER as list( $kind, $types ) ) {
			$at = $this->code->find_top_level( $start, $end, $types );
			if ( array() === $at ) {
				continue;
			}

			if ( 'compare' === $kind ) {
				if ( 1 !== count( $at ) ) {
					return array( 'unknown' );
				}

				return array( 'compare', self::OPERATORS[ $this->code->type( $at[0] ) ], $this->parse( $start, $at[0] - 1 ), $this->parse( $at[0] + 1, $end ) );
			}

			// "$x = value" has the value assigned, the last one when chained.
			if ( 'assign' === $kind ) {
				return $this->parse( end( $at ) + 1, $end );
			}

			if ( 'unknown' === $kind ) {
				// A leading minus is a negative number, not a subtraction.
				if ( array( $start ) === $at && 'T_MINUS' === $this->code->type( $start ) ) {
					break;
				}

				return array( 'unknown' );
			}

			$parts = array();
			foreach ( TokenList::split( $start, $end, $at ) as list( $from, $to ) ) {
				$parts[] = $this->parse( $from, $to );
			}

			return array( $kind, $parts );
		}

		return $this->parse_unary( $start, $end );
	}

	/**
	 * Whether the parsed expression always has the given truth value on every
	 * release of a PHP version, such as 8.4.0 to 8.4.99.
	 *
	 * @param array<int, mixed> $node    A tree from parse().
	 * @param bool              $value   The truth value.
	 * @param int               $major   Major version of the target, such as 8.
	 * @param int               $minor   Minor version of the target, such as 4.
	 * @param Facts             $facts   What's known to be missing on the target.
	 * @return bool
	 */
	public static function always( array $node, bool $value, int $major, int $minor, Facts $facts ): bool {
		for ( $release = 0; $release <= self::LAST_RELEASE; $release++ ) {
			$result = self::evaluate(
				$node,
				array(
					'major'   => $major,
					'minor'   => $minor,
					'release' => $release,
					'facts'   => $facts,
				)
			);
			if ( null === $result || (bool) $result[0] !== $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Parses an expression without binary operators.
	 *
	 * @param int $start First position, not empty.
	 * @param int $end   Last position, not empty.
	 * @return array<int, mixed>
	 */
	private function parse_unary( int $start, int $end ): array {
		$type = $this->code->type( $start );

		// The whole range in parentheses.
		$token = $this->code->get( $start );
		if ( 'T_OPEN_PARENTHESIS' === $type && ( $token['parenthesis_closer'] ?? -1 ) === $end ) {
			return $this->parse( $start + 1, $end - 1 );
		}

		if ( 'T_BOOLEAN_NOT' === $type ) {
			return array( 'not', $this->parse( $start + 1, $end ) );
		}

		// Silencing errors doesn't change the value.
		if ( 'T_ASPERAND' === $type ) {
			return $this->parse( $start + 1, $end );
		}

		if ( 'T_MINUS' === $type ) {
			$number = $this->parse( $start + 1, $end );
			return 'value' === $number[0] && is_int( $number[1] ) ? array( 'value', -$number[1] ) : array( 'unknown' );
		}

		if ( $start === $end ) {
			return $this->parse_single( $start );
		}

		return $this->parse_call( $start, $end );
	}

	/**
	 * Parses a single token: a literal or a constant.
	 *
	 * @param int $ptr Position.
	 * @return array<int, mixed>
	 */
	private function parse_single( int $ptr ): array {
		$content = $this->code->content( $ptr );

		switch ( $this->code->type( $ptr ) ) {
			case 'T_TRUE':
				return array( 'value', true );
			case 'T_FALSE':
				return array( 'value', false );
			case 'T_NULL':
				return array( 'value', null );
			case 'T_LNUMBER':
				$number = self::integer( $content );
				return null === $number ? array( 'unknown' ) : array( 'value', $number );
			case 'T_CONSTANT_ENCAPSED_STRING':
				$string = self::string( $content );
				return null === $string ? array( 'unknown' ) : array( 'value', $string );
			case 'T_STRING':
				return in_array( $content, self::ALWAYS_DEFINED, true ) ? array( 'constant', $content ) : array( 'unknown' );
			default:
				return array( 'unknown' );
		}
	}

	/**
	 * Parses a constant with a namespace separator, or a call to one of the
	 * functions that are understood.
	 *
	 * @param int $start First position.
	 * @param int $end   Last position.
	 * @return array<int, mixed>
	 */
	private function parse_call( int $start, int $end ): array {
		// A leading "\" means the global name, which is the one understood.
		if ( 'T_NS_SEPARATOR' === $this->code->type( $start ) ) {
			$start = $this->code->next( $start, $end );
			if ( -1 === $start ) {
				return array( 'unknown' );
			}
			if ( $start === $end ) {
				return $this->parse_single( $start );
			}
		}

		$name  = strtolower( $this->code->content( $start ) );
		$open  = $this->code->next( $start, $end );
		$token = $this->code->get( $open );
		if ( 'T_STRING' !== $this->code->type( $start )
			|| ! in_array( $name, self::FUNCTIONS, true )
			|| 'T_OPEN_PARENTHESIS' !== $this->code->type( $open )
			|| ( $token['parenthesis_closer'] ?? -1 ) !== $end
		) {
			return array( 'unknown' );
		}

		$arguments         = array();
		list( $from, $to ) = $this->code->trim( $open + 1, $end - 1 );
		if ( $from <= $to ) {
			foreach ( TokenList::split( $from, $to, $this->code->find_top_level( $from, $to, array( 'T_COMMA' ) ) ) as list( $first, $last ) ) {
				$arguments[] = $this->parse( $first, $last );
			}
		}

		return array( 'call', $name, $arguments );
	}

	/**
	 * Evaluates a tree in one world: one PHP release, with what's known to
	 * be missing there.
	 *
	 * @param array<int, mixed>    $node  A tree from parse().
	 * @param array<string, mixed> $world Keys major, minor, release and facts.
	 * @return array{0: mixed}|null The value, wrapped, or null when it's unknown.
	 */
	private static function evaluate( array $node, array $world ): ?array {
		switch ( $node[0] ) {
			case 'value':
				return array( $node[1] );

			case 'constant':
				return array( self::constant( $node[1], $world ) );

			case 'not':
				$truth = self::truth( self::evaluate( $node[1], $world ) );
				return null === $truth ? null : array( ! $truth );

			case 'and':
			case 'or':
				return self::combine( $node[0], $node[1], $world );

			case 'xor':
				$result = false;
				foreach ( $node[1] as $part ) {
					$truth = self::truth( self::evaluate( $part, $world ) );
					if ( null === $truth ) {
						return null;
					}
					$result = ( $result xor $truth );
				}
				return array( $result );

			case 'compare':
				return self::compare( $node[1], self::evaluate( $node[2], $world ), self::evaluate( $node[3], $world ) );

			case 'call':
				return self::call( $node[1], $node[2], $world );

			default:
				return null;
		}
	}

	/**
	 * Evaluates && or ||: one false part makes && false and one true part
	 * makes || true, even when other parts are unknown.
	 *
	 * @param string                   $kind  "and" or "or".
	 * @param array<int, array<mixed>> $parts Trees.
	 * @param array<string, mixed>     $world The world.
	 * @return array{0: bool}|null
	 */
	private static function combine( string $kind, array $parts, array $world ): ?array {
		$decisive = 'or' === $kind;
		$unknown  = false;
		foreach ( $parts as $part ) {
			$truth = self::truth( self::evaluate( $part, $world ) );
			if ( $decisive === $truth ) {
				return array( $decisive );
			}
			if ( null === $truth ) {
				$unknown = true;
			}
		}

		return $unknown ? null : array( ! $decisive );
	}

	/**
	 * Truth value of an evaluated value.
	 *
	 * @param array{0: mixed}|null $value Wrapped value, or null when unknown.
	 * @return bool|null
	 */
	private static function truth( ?array $value ): ?bool {
		return null === $value ? null : (bool) $value[0];
	}

	/**
	 * Value of a version constant in a world.
	 *
	 * @param string               $name  Constant name.
	 * @param array<string, mixed> $world The world.
	 * @return int|string
	 */
	private static function constant( string $name, array $world ) {
		switch ( $name ) {
			case 'PHP_VERSION_ID':
				return $world['major'] * 10000 + $world['minor'] * 100 + $world['release'];
			case 'PHP_MAJOR_VERSION':
				return $world['major'];
			case 'PHP_MINOR_VERSION':
				return $world['minor'];
			case 'PHP_RELEASE_VERSION':
				return $world['release'];
			default:
				return $world['major'] . '.' . $world['minor'] . '.' . $world['release'];
		}
	}

	/**
	 * Compares two values the way PHP does, for the types that come up in
	 * version and existence checks. Other combinations are unknown, so the
	 * result never depends on the PHP version running the scan.
	 *
	 * @param string               $operator Comparison operator.
	 * @param array{0: mixed}|null $left     Wrapped value, or null.
	 * @param array{0: mixed}|null $right    Wrapped value, or null.
	 * @return array{0: bool|int}|null
	 */
	private static function compare( string $operator, ?array $left, ?array $right ): ?array {
		if ( null === $left || null === $right ) {
			return null;
		}
		$a = $left[0];
		$b = $right[0];

		if ( '===' === $operator || '!==' === $operator ) {
			$same = $a === $b;
			return array( '===' === $operator ? $same : ! $same );
		}

		if ( is_bool( $a ) || is_bool( $b ) ) {
			// Loose comparisons with a boolean compare truth values.
			if ( '==' !== $operator && '!=' !== $operator ) {
				return null;
			}
			$a = (bool) $a;
			$b = (bool) $b;
		} elseif ( ! ( is_int( $a ) && is_int( $b ) ) && ! ( is_string( $a ) && is_string( $b ) && ( '==' === $operator || '!=' === $operator ) && ! is_numeric( $a ) && ! is_numeric( $b ) ) ) {
			// Integers with integers, or equality of non-numeric strings.
			return null;
		}

		switch ( $operator ) {
			case '==':
				return array( $a === $b );
			case '!=':
				return array( $a !== $b );
			case '<=>':
				return array( $a <=> $b );
			case '<':
				return array( $a < $b );
			case '>':
				return array( $a > $b );
			case '<=':
				return array( $a <= $b );
			default:
				return array( $a >= $b );
		}
	}

	/**
	 * Evaluates a call to one of the understood functions.
	 *
	 * @param string                   $name      Lowercase function name.
	 * @param array<int, array<mixed>> $arguments Trees of the arguments.
	 * @param array<string, mixed>     $world     The world.
	 * @return array{0: mixed}|null
	 */
	private static function call( string $name, array $arguments, array $world ): ?array {
		$values = array();
		foreach ( $arguments as $argument ) {
			$values[] = self::evaluate( $argument, $world );
		}

		/**
		 * What's known to be missing on the target.
		 *
		 * @var Facts $facts
		 */
		$facts = $world['facts'];
		$first = isset( $values[0] ) && is_string( $values[0][0] ) ? $values[0][0] : null;

		switch ( $name ) {
			case 'phpversion':
				return array() === $values ? array( self::constant( 'PHP_VERSION', $world ) ) : null;

			case 'version_compare':
				return self::version_compare( $values );

			case 'function_exists':
			case 'is_callable':
				// Only a plain function name: is_callable() also takes methods.
				return null !== $first && 1 === count( $values ) && $facts->is_missing_function( $first ) ? array( false ) : null;

			case 'class_exists':
			case 'interface_exists':
				return null !== $first && count( $values ) <= 2 && $facts->is_missing_class( $first ) ? array( false ) : null;

			default:
				if ( null === $first || 1 !== count( $values ) ) {
					return null;
				}
				if ( in_array( ltrim( $first, '\\' ), self::ALWAYS_DEFINED, true ) ) {
					return array( true );
				}
				return $facts->is_missing_constant( $first ) ? array( false ) : null;
		}
	}

	/**
	 * Evaluates version_compare() with known version strings.
	 *
	 * @param array<int, array{0: mixed}|null> $values Wrapped argument values.
	 * @return array{0: bool|int}|null
	 */
	private static function version_compare( array $values ): ?array {
		$count = count( $values );
		if ( $count < 2 || $count > 3 ) {
			return null;
		}

		foreach ( $values as $value ) {
			if ( null === $value || ! is_string( $value[0] ) ) {
				return null;
			}
		}

		if ( 2 === $count ) {
			return array( version_compare( $values[0][0], $values[1][0] ) );
		}

		// Operators are case-sensitive: version_compare() rejects "GE".
		$operator = $values[2][0];
		if ( ! in_array( $operator, array( '<', 'lt', '<=', 'le', '>', 'gt', '>=', 'ge', '==', '=', 'eq', '!=', '<>', 'ne' ), true ) ) {
			return null;
		}

		return array( version_compare( $values[0][0], $values[1][0], $operator ) );
	}

	/**
	 * Value of an integer literal, such as 80000, 0x1F, 0b11, 017 or 1_000.
	 *
	 * @param string $literal As written.
	 * @return int|null
	 */
	private static function integer( string $literal ): ?int {
		$digits = strtolower( str_replace( '_', '', $literal ) );

		if ( preg_match( '/^0x[0-9a-f]+$/', $digits ) ) {
			return (int) hexdec( substr( $digits, 2 ) );
		}
		if ( preg_match( '/^0b[01]+$/', $digits ) ) {
			return (int) bindec( substr( $digits, 2 ) );
		}
		if ( preg_match( '/^0o?[0-7]+$/', $digits ) ) {
			return (int) octdec( ltrim( $digits, '0o' ) );
		}
		if ( preg_match( '/^(0|[1-9][0-9]*)$/', $digits ) && strlen( $digits ) < 18 ) {
			return (int) $digits;
		}

		return null;
	}

	/**
	 * Value of a string literal. Double-quoted strings with escape sequences
	 * or variables are left unknown.
	 *
	 * @param string $literal As written, with its quotes.
	 * @return string|null
	 */
	private static function string( string $literal ): ?string {
		$quote = substr( $literal, 0, 1 );
		$inner = substr( $literal, 1, -1 );
		if ( strlen( $literal ) < 2 || ( "'" !== $quote && '"' !== $quote ) || substr( $literal, -1 ) !== $quote ) {
			return null;
		}

		// In single quotes, only \\ and \' are escapes: '\each' is "\each".
		if ( "'" === $quote ) {
			return (string) preg_replace( "/\\\\([\\\\'])/", '$1', $inner );
		}

		if ( false !== strpos( $inner, '\\' ) || false !== strpos( $inner, '$' ) ) {
			return null;
		}

		return $inner;
	}
}

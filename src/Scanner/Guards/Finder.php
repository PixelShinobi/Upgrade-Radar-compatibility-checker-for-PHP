<?php
/**
 * Finds the conditions a piece of code needs before it runs.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Guards;

/**
 * Lists conditions that must hold whenever the code at a token runs: the
 * if, elseif and else branches and while loops around it, braces or not;
 * the operands before it in &&, ||, and, or and ternary expressions; and
 * earlier "if ( ... ) { return; }" exits from the same block.
 *
 * Every condition listed is necessary, never just likely. A condition left
 * out only means fewer findings are excused, so unusual code is left alone
 * rather than guessed at.
 */
final class Finder {

	/**
	 * Operators that decide whether the operands after them run, loosest
	 * first. With "and", the operands before must be true; with "or", false.
	 * "none" operators run all their operands.
	 */
	private const LADDER = array(
		array( 'or', array( 'T_LOGICAL_OR' ) ),
		array( 'none', array( 'T_LOGICAL_XOR' ) ),
		array( 'and', array( 'T_LOGICAL_AND' ) ),
		array( 'none', array( 'T_EQUAL', 'T_PLUS_EQUAL', 'T_MINUS_EQUAL', 'T_MUL_EQUAL', 'T_DIV_EQUAL', 'T_CONCAT_EQUAL', 'T_MOD_EQUAL', 'T_AND_EQUAL', 'T_OR_EQUAL', 'T_XOR_EQUAL', 'T_SL_EQUAL', 'T_SR_EQUAL', 'T_POW_EQUAL', 'T_COALESCE_EQUAL', 'T_DOUBLE_ARROW', 'T_MATCH_ARROW' ) ),
		array( 'ternary', array( 'T_INLINE_THEN', 'T_INLINE_ELSE' ) ),
		array( 'none', array( 'T_COALESCE' ) ),
		array( 'or', array( 'T_BOOLEAN_OR' ) ),
		array( 'and', array( 'T_BOOLEAN_AND' ) ),
	);

	/**
	 * Owners of parentheses that hold expressions which always run along
	 * with the code around them, such as array( ... ) or isset( ... ).
	 */
	private const LIST_OWNERS = array( 'T_ARRAY', 'T_LIST', 'T_ISSET', 'T_UNSET', 'T_EMPTY', 'T_EXIT', 'T_EVAL', 'T_MATCH', 'T_SELF', 'T_PARENT', 'T_STATIC', 'T_ANON_CLASS' );

	/**
	 * Control structures whose parentheses end a statement before them.
	 */
	private const CONTROL_STRUCTURES = array( 'T_IF', 'T_ELSEIF', 'T_WHILE', 'T_FOR', 'T_FOREACH', 'T_SWITCH', 'T_DECLARE' );

	/**
	 * Expressions whose closing brace doesn't end a statement.
	 */
	private const EXPRESSION_BODIES = array( 'T_CLOSURE', 'T_FN', 'T_MATCH', 'T_ANON_CLASS' );

	/**
	 * Keywords that start a statement and take an expression.
	 */
	private const LEADING_KEYWORDS = array( 'T_RETURN', 'T_ECHO', 'T_PRINT', 'T_THROW', 'T_YIELD', 'T_YIELD_FROM', 'T_INCLUDE', 'T_INCLUDE_ONCE', 'T_REQUIRE', 'T_REQUIRE_ONCE' );

	/**
	 * Statements that always leave the block they're in.
	 */
	private const EXITS = array( 'T_RETURN', 'T_EXIT', 'T_THROW', 'T_CONTINUE', 'T_BREAK' );

	/**
	 * Tokens a statement can follow directly.
	 */
	private const STATEMENT_BOUNDARIES = array( 'T_SEMICOLON', 'T_OPEN_CURLY_BRACKET', 'T_CLOSE_CURLY_BRACKET', 'T_COLON', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML' );

	/**
	 * The file's tokens.
	 *
	 * @var TokenList
	 */
	private $code;

	/**
	 * Positions of if statements by the block they're in (the innermost
	 * scope's position, or -1 outside any), built on first use.
	 *
	 * @var array<int, int[]>|null
	 */
	private $ifs;

	/**
	 * Finds guards in one file.
	 *
	 * @param TokenList $code The file's tokens.
	 */
	public function __construct( TokenList $code ) {
		$this->code = $code;
	}

	/**
	 * Conditions that must hold for the code at a token to run.
	 *
	 * @param int $ptr Position of the token.
	 * @return array<int, array{0: int, 1: int, 2: bool}> Each condition's first
	 *         and last token, and the value it must have.
	 */
	public function guards( int $ptr ): array {
		$guards    = array();
		$statement = $this->expression_guards( $ptr, $guards );
		if ( -1 !== $statement ) {
			$statement = $this->braceless_guards( $statement, $guards );
			$this->early_exit_guards( $statement, $guards );
		}
		$this->scope_guards( $ptr, $guards );

		return $guards;
	}

	/**
	 * Adds the conditions from the expressions around a token, inside out.
	 *
	 * @param int                                        $ptr    Position of the token.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 * @return int Where the statement around the token starts, or -1 when
	 *             the token is inside a declaration or a loop's parentheses.
	 */
	private function expression_guards( int $ptr, array &$guards ): int {
		$parens = (array) ( $this->code->get( $ptr )['nested_parenthesis'] ?? array() );
		krsort( $parens );
		$outer = $ptr;

		foreach ( $parens as $open => $close ) {
			$owner = $this->code->get( (int) $open )['parenthesis_owner'] ?? null;
			$type  = null === $owner ? '' : $this->code->type( (int) $owner );

			if ( in_array( $type, array( 'T_IF', 'T_ELSEIF', 'T_WHILE', 'T_SWITCH' ), true ) ) {
				$this->operand_guards( (int) $open + 1, (int) $close - 1, $ptr, $guards );
				if ( 'T_IF' !== $type && 'T_ELSEIF' !== $type ) {
					return (int) $owner;
				}

				// An elseif condition only runs when the branches before were false.
				$this->chain_guards( (int) $owner, $guards );
				return $this->chain_head( (int) $owner );
			}

			if ( '' !== $type && ! in_array( $type, self::LIST_OWNERS, true ) ) {
				return -1;
			}

			// Arguments, array items or a plain group.
			$this->list_guards( (int) $open + 1, (int) $close - 1, $ptr, $guards );
			$outer = (int) $open;
		}

		list( $start, $end ) = $this->statement( $outer );
		$from                = $start;
		if ( in_array( $this->code->type( $from ), self::LEADING_KEYWORDS, true ) ) {
			$from = $this->code->next( $from, $end );
		}
		if ( -1 !== $from ) {
			$this->list_guards( $from, $end, $ptr, $guards );
		}

		return $start;
	}

	/**
	 * Adds the conditions for a token within a comma-separated list, such as
	 * function arguments.
	 *
	 * @param int                                        $start  First position of the list.
	 * @param int                                        $end    Last position of the list.
	 * @param int                                        $ptr    Position of the token.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 */
	private function list_guards( int $start, int $end, int $ptr, array &$guards ): void {
		foreach ( TokenList::split( $start, $end, $this->code->find_top_level( $start, $end, array( 'T_COMMA' ) ) ) as list( $from, $to ) ) {
			if ( $ptr >= $from && $ptr <= $to ) {
				$this->operand_guards( $from, $to, $ptr, $guards );
				return;
			}
		}
	}

	/**
	 * Adds the conditions for a token within an expression: the operands
	 * before it in && and || chains and the condition of a ternary.
	 *
	 * @param int                                        $start  First position of the expression.
	 * @param int                                        $end    Last position of the expression.
	 * @param int                                        $ptr    Position of the token.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 */
	private function operand_guards( int $start, int $end, int $ptr, array &$guards ): void {
		list( $start, $end ) = $this->code->trim( $start, $end );
		if ( $ptr < $start || $ptr > $end ) {
			return;
		}

		foreach ( self::LADDER as list( $kind, $types ) ) {
			$at = $this->code->find_top_level( $start, $end, $types );
			if ( array() === $at ) {
				continue;
			}
			if ( in_array( $ptr, $at, true ) ) {
				return;
			}

			if ( 'ternary' === $kind ) {
				$this->ternary_guards( $start, $end, $at, $ptr, $guards );
				return;
			}

			foreach ( TokenList::split( $start, $end, $at ) as list( $from, $to ) ) {
				if ( $ptr <= $to ) {
					$this->operand_guards( $from, $to, $ptr, $guards );
					return;
				}
				if ( 'none' !== $kind ) {
					$guards[] = array( $from, $to, 'and' === $kind );
				}
			}
			return;
		}
	}

	/**
	 * Adds the condition of a ternary for a token in one of its branches.
	 *
	 * @param int                                        $start  First position of the ternary.
	 * @param int                                        $end    Last position of the ternary.
	 * @param int[]                                      $at     Positions of its "?" and ":".
	 * @param int                                        $ptr    Position of the token.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 */
	private function ternary_guards( int $start, int $end, array $at, int $ptr, array &$guards ): void {
		// Nested ternaries without parentheses are too ambiguous to read.
		if ( 2 !== count( $at ) || 'T_INLINE_THEN' !== $this->code->type( $at[0] ) || 'T_INLINE_ELSE' !== $this->code->type( $at[1] ) ) {
			return;
		}

		list( $then, $else ) = $at;
		if ( $ptr < $then ) {
			$this->operand_guards( $start, $then - 1, $ptr, $guards );
		} elseif ( $ptr < $else ) {
			$guards[] = array( $start, $then - 1, true );
			$this->operand_guards( $then + 1, $else - 1, $ptr, $guards );
		} else {
			$guards[] = array( $start, $then - 1, false );
			$this->operand_guards( $else + 1, $end, $ptr, $guards );
		}
	}

	/**
	 * The statement around a token that isn't inside parentheses.
	 *
	 * @param int $from Position inside the statement.
	 * @return array{0: int, 1: int} First and last token of the statement,
	 *         without the semicolon.
	 */
	private function statement( int $from ): array {
		$start = $from;
		for ( $i = $from - 1; $i >= 0; $i-- ) {
			$token = $this->code->get( $i );
			$type  = $this->code->type( $i );

			if ( isset( $token['parenthesis_opener'] ) && ( $token['parenthesis_closer'] ?? -1 ) === $i ) {
				$owner = $token['parenthesis_owner'] ?? null;
				// "if ( ... ) statement;" without braces: the statement starts after the parentheses.
				if ( null !== $owner && in_array( $this->code->type( (int) $owner ), self::CONTROL_STRUCTURES, true ) ) {
					break;
				}
				$i     = (int) $token['parenthesis_opener'];
				$start = $i;
				continue;
			}

			if ( isset( $token['bracket_opener'] ) && ( $token['bracket_closer'] ?? -1 ) === $i ) {
				$condition = $token['scope_condition'] ?? null;
				if ( null === $condition ) {
					$i = (int) $token['bracket_opener'];
				} elseif ( in_array( $this->code->type( (int) $condition ), self::EXPRESSION_BODIES, true ) ) {
					$i = (int) $condition;
				} else {
					// The end of a block before the statement.
					break;
				}
				$start = $i;
				continue;
			}

			if ( in_array( $type, array( 'T_SEMICOLON', 'T_OPEN_TAG', 'T_OPEN_TAG_WITH_ECHO', 'T_CLOSE_TAG', 'T_INLINE_HTML', 'T_ELSE', 'T_DO' ), true )
				|| ( isset( $token['scope_opener'] ) && $token['scope_opener'] === $i )
			) {
				break;
			}

			if ( ! $this->code->is_empty( $i ) ) {
				$start = $i;
			}
		}

		$end   = $from;
		$count = $this->code->count();
		for ( $i = $from; $i < $count; $i++ ) {
			$token = $this->code->get( $i );
			$type  = $this->code->type( $i );
			if ( 'T_SEMICOLON' === $type || 'T_CLOSE_TAG' === $type ) {
				break;
			}

			// The end of a group the statement is inside of.
			if ( ( isset( $token['parenthesis_opener'] ) && ( $token['parenthesis_closer'] ?? -1 ) === $i && $token['parenthesis_opener'] < $start )
				|| ( isset( $token['bracket_opener'] ) && ( $token['bracket_closer'] ?? -1 ) === $i && $token['bracket_opener'] < $start )
			) {
				break;
			}

			$i = $this->code->skip( $i );
			if ( ! $this->code->is_empty( $i ) ) {
				$end = $i;
			}
		}

		return array( $start, $end );
	}

	/**
	 * Adds the conditions of if statements without braces that a statement
	 * is the body of, such as "if ( ... ) statement;".
	 *
	 * @param int                                        $statement Where the statement starts.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards    Conditions found so far.
	 * @return int Where the outermost of those statements starts.
	 */
	private function braceless_guards( int $statement, array &$guards ): int {
		while ( true ) {
			$before = $this->code->prev( $statement );
			$owner  = $this->code->get( $before )['parenthesis_owner'] ?? null;
			if ( 'T_CLOSE_PARENTHESIS' !== $this->code->type( $before ) || null === $owner || isset( $this->code->get( (int) $owner )['scope_opener'] ) ) {
				return $statement;
			}

			$owner = (int) $owner;
			$type  = $this->code->type( $owner );
			if ( 'T_IF' === $type || 'T_ELSEIF' === $type ) {
				$guards[] = $this->condition( $owner, true );
				// Chains without braces aren't followed further.
				if ( 'T_ELSEIF' === $type || 'T_ELSE' === $this->code->type( $this->code->prev( $owner ) ) ) {
					return $owner;
				}
			} elseif ( ! in_array( $type, array( 'T_WHILE', 'T_FOR', 'T_FOREACH' ), true ) ) {
				return $statement;
			}

			$statement = $owner;
		}
	}

	/**
	 * Adds the conditions of if statements earlier in the same block that
	 * always leave it, such as "if ( ... ) { return; }".
	 *
	 * @param int                                        $statement Where the statement starts.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards    Conditions found so far.
	 */
	private function early_exit_guards( int $statement, array &$guards ): void {
		foreach ( $this->ifs_in_block( $statement ) as $if ) {
			if ( $if >= $statement ) {
				break;
			}

			// Not the body of another control structure, and not an "else if".
			if ( ! in_array( $this->code->type( $this->code->prev( $if ) ), self::STATEMENT_BOUNDARIES, true ) ) {
				continue;
			}

			$end = $this->exiting_body_end( $if );
			if ( -1 !== $end && $end < $statement ) {
				$guards[] = $this->condition( $if, false );
			}
		}
	}

	/**
	 * The if statements in the same block as a token.
	 *
	 * @param int $ptr Position of the token.
	 * @return int[] Positions, in order.
	 */
	private function ifs_in_block( int $ptr ): array {
		if ( null === $this->ifs ) {
			$this->ifs = array();
			$count     = $this->code->count();
			for ( $i = 0; $i < $count; $i++ ) {
				if ( 'T_IF' === $this->code->type( $i ) ) {
					$this->ifs[ $this->block( $i ) ][] = $i;
				}
			}
		}

		return $this->ifs[ $this->block( $ptr ) ] ?? array();
	}

	/**
	 * The innermost scope around a token, or -1 outside any.
	 *
	 * @param int $ptr Position of the token.
	 * @return int
	 */
	private function block( int $ptr ): int {
		$conditions = (array) ( $this->code->get( $ptr )['conditions'] ?? array() );
		if ( array() === $conditions ) {
			return -1;
		}
		end( $conditions );

		return (int) key( $conditions );
	}

	/**
	 * Where an if statement's body ends, if its body always leaves the
	 * block: its last statement is return, exit, die, throw, continue or
	 * break.
	 *
	 * @param int $owner Position of the if.
	 * @return int Position of the body's last token, or -1.
	 */
	private function exiting_body_end( int $owner ): int {
		$token = $this->code->get( $owner );

		if ( isset( $token['scope_opener'], $token['scope_closer'] ) ) {
			$closer = (int) $token['scope_closer'];
			$last   = $this->code->prev( $closer, (int) $token['scope_opener'] + 1 );
			if ( 'T_SEMICOLON' !== $this->code->type( $last ) ) {
				return -1;
			}
			list( $first ) = $this->statement( $this->code->prev( $last ) );

			return in_array( $this->code->type( $first ), self::EXITS, true ) ? $closer : -1;
		}

		if ( ! isset( $token['parenthesis_closer'] ) ) {
			return -1;
		}
		$first = $this->code->next( (int) $token['parenthesis_closer'], $this->code->count() - 1 );
		if ( ! in_array( $this->code->type( $first ), self::EXITS, true ) ) {
			return -1;
		}

		list( , $end ) = $this->statement( $first );
		return $end;
	}

	/**
	 * Adds the conditions of the if, elseif and else branches around a token.
	 *
	 * @param int                                        $ptr    Position of the token.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 */
	private function scope_guards( int $ptr, array &$guards ): void {
		foreach ( array_keys( (array) ( $this->code->get( $ptr )['conditions'] ?? array() ) ) as $scope ) {
			$type = $this->code->type( (int) $scope );
			if ( 'T_IF' === $type || 'T_ELSEIF' === $type ) {
				$guards[] = $this->condition( (int) $scope, true );
				$this->chain_guards( (int) $scope, $guards );
			} elseif ( 'T_ELSE' === $type ) {
				$this->chain_guards( (int) $scope, $guards );
			} elseif ( 'T_WHILE' === $type ) {
				// A while loop's body only runs while its condition is true.
				$guards[] = $this->condition( (int) $scope, true );
			}
		}
	}

	/**
	 * Adds the conditions of the branches before an elseif, an else or an
	 * "else if": each of them was false.
	 *
	 * @param int                                        $branch Position of the branch.
	 * @param array<int, array{0: int, 1: int, 2: bool}> $guards Conditions found so far.
	 */
	private function chain_guards( int $branch, array &$guards ): void {
		for ( $previous = $this->previous_branch( $branch ); - 1 !== $previous; $previous = $this->previous_branch( $previous ) ) {
			$guards[] = $this->condition( $previous, false );
		}
	}

	/**
	 * The first if of the chain a branch belongs to.
	 *
	 * @param int $branch Position of the branch.
	 * @return int
	 */
	private function chain_head( int $branch ): int {
		for ( $previous = $this->previous_branch( $branch ); - 1 !== $previous; $previous = $this->previous_branch( $previous ) ) {
			$branch = $previous;
		}

		return $branch;
	}

	/**
	 * The branch before an elseif, an else or an "else if" in the same
	 * chain.
	 *
	 * @param int $branch Position of the branch.
	 * @return int Position of the if or elseif, or -1.
	 */
	private function previous_branch( int $branch ): int {
		$type = $this->code->type( $branch );
		if ( 'T_IF' === $type ) {
			$branch = $this->code->prev( $branch );
			if ( 'T_ELSE' !== $this->code->type( $branch ) ) {
				return -1;
			}
		} elseif ( 'T_ELSEIF' !== $type && 'T_ELSE' !== $type ) {
			return -1;
		}

		// With braces: the closing brace of the branch before.
		$before = $this->code->prev( $branch );
		if ( 'T_CLOSE_CURLY_BRACKET' === $this->code->type( $before ) ) {
			$previous = (int) ( $this->code->get( $before )['scope_condition'] ?? -1 );
			return in_array( $this->code->type( $previous ), array( 'T_IF', 'T_ELSEIF' ), true ) ? $previous : -1;
		}

		// Alternative syntax: the branch before ends where this one starts.
		for ( $i = $before; $i >= 0; $i-- ) {
			if ( ( $this->code->get( $i )['scope_closer'] ?? -1 ) === $branch && in_array( $this->code->type( $i ), array( 'T_IF', 'T_ELSEIF' ), true ) ) {
				return $i;
			}
		}

		return -1;
	}

	/**
	 * The condition of an if or elseif.
	 *
	 * @param int  $owner Position of the if or elseif.
	 * @param bool $value The value it must have.
	 * @return array{0: int, 1: int, 2: bool}
	 */
	private function condition( int $owner, bool $value ): array {
		$token = $this->code->get( $owner );

		return array( (int) ( $token['parenthesis_opener'] ?? 0 ) + 1, (int) ( $token['parenthesis_closer'] ?? 0 ) - 1, $value );
	}
}

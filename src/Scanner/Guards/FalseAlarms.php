<?php
/**
 * Marks findings in code that can't run on the target PHP version.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner\Guards;

use UpgradeRadar\Scanner\Issue;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * A finding is a possible false alarm when the code it's in only runs if a
 * guard has a value it never has on the target version:
 *
 * - a version check, such as "if ( PHP_VERSION_ID < 80000 )", evaluated for
 *   every release of the target, x.y.0 to x.y.99;
 * - an existence check of the very function, class or constant the finding
 *   says was removed, such as "if ( function_exists( 'each' ) )" around a
 *   call to each(). A check of anything else proves nothing, which is also
 *   why declaration guards like "if ( ! function_exists( 'foo' ) ) {
 *   function foo() { ... } }" never excuse the code inside them.
 *
 * Only findings about code as it runs can be excused. Syntax that no longer
 * compiles breaks the whole file, guarded or not.
 */
final class FalseAlarms {

	/**
	 * Sniffs whose findings only matter when the code runs: calls, values,
	 * constants and settings. Other sniffs find code PHP rejects or warns
	 * about when it compiles the file, which no guard prevents.
	 */
	private const RUNTIME_SNIFFS = array(
		'PHPCompatibility.Classes.RemovedClasses',
		'PHPCompatibility.Constants.RemovedConstants',
		'PHPCompatibility.Extensions.RemovedExtensions',
		'PHPCompatibility.FunctionDeclarations.RemovedCallingDestructAfterConstructorExit',
		'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors',
		'PHPCompatibility.FunctionUse.ArgumentFunctionsUsage',
		'PHPCompatibility.FunctionUse.OptionalToRequiredFunctionParameters',
		'PHPCompatibility.FunctionUse.RemovedFunctionParameters',
		'PHPCompatibility.FunctionUse.RemovedFunctions',
		'PHPCompatibility.IniDirectives.RemovedIniDirectives',
		'PHPCompatibility.MethodUse.ForbiddenToStringParameters',
		'PHPCompatibility.Numbers.RemovedHexadecimalNumericStrings',
		'PHPCompatibility.Operators.ChangedConcatOperatorPrecedence',
		'PHPCompatibility.ParameterValues',
		'PHPCompatibility.Variables.RemovedPredefinedGlobalVariables',
	);

	/**
	 * Marks the possible false alarms among a file's findings.
	 *
	 * @param array<int, array<string, mixed>> $tokens PHP_CodeSniffer's tokens for the file.
	 * @param Issue[]                          $issues The file's findings.
	 * @param TargetVersion                    $target The PHP version checked against.
	 * @return Issue[] The same findings, with possible false alarms marked.
	 */
	public static function mark( array $tokens, array $issues, TargetVersion $target ): array {
		$code   = new TokenList( $tokens );
		$finder = new Finder( $code );
		$parser = new Condition( $code );
		$trees  = array();

		list( $major, $minor ) = array_map( 'intval', explode( '.', $target->to_string() ) );

		$marked = array();
		foreach ( $issues as $issue ) {
			$ptr = $code->find( $issue->line(), $issue->column() );
			if ( -1 === $ptr || ! self::is_runtime( $issue->source() ) ) {
				$marked[] = $issue;
				continue;
			}

			$facts = self::facts( $issue, $code, $ptr );
			foreach ( $finder->guards( $ptr ) as list( $start, $end, $value ) ) {
				$key = $start . ':' . $end;
				if ( ! isset( $trees[ $key ] ) ) {
					$trees[ $key ] = $parser->parse( $start, $end );
				}

				// The code needs the condition to be $value, and it never is.
				if ( Condition::always( $trees[ $key ], ! $value, $major, $minor, $facts ) ) {
					list( $guard, $runs_if ) = self::describe( $code, $trees[ $key ], $start, $end, $value );
					$issue                   = $issue->with_guard( $guard, $runs_if );
					break;
				}
			}

			$marked[] = $issue;
		}

		return $marked;
	}

	/**
	 * A guard as shown to people, without a leading "!": code that needs
	 * "! function_exists( 'each' )" to be false needs "function_exists( 'each' )".
	 *
	 * @param TokenList         $code  The file's tokens.
	 * @param array<int, mixed> $tree  The guard, parsed.
	 * @param int               $start First position of the guard.
	 * @param int               $end   Last position of the guard.
	 * @param bool              $value The value the code needs it to have.
	 * @return array{0: string, 1: bool} The guard's code and the value.
	 */
	private static function describe( TokenList $code, array $tree, int $start, int $end, bool $value ): array {
		list( $start, $end ) = $code->trim( $start, $end );
		if ( 'not' === $tree[0] && 'T_BOOLEAN_NOT' === $code->type( $start ) ) {
			return array( $code->text( $code->next( $start, $end ), $end ), ! $value );
		}

		return array( $code->text( $start, $end ), $value );
	}

	/**
	 * Whether a finding's sniff is about code as it runs.
	 *
	 * @param string $source The full sniff code.
	 * @return bool
	 */
	private static function is_runtime( string $source ): bool {
		foreach ( self::RUNTIME_SNIFFS as $sniff ) {
			if ( 0 === strpos( $source, $sniff . '.' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * What a finding says is missing on the target version: the function,
	 * class or constant it found, when the finding is an error about its
	 * removal. Deprecations are warnings, and deprecated things still exist.
	 *
	 * @param Issue     $issue The finding.
	 * @param TokenList $code  The file's tokens.
	 * @param int       $ptr   Position of the token it's about.
	 * @return Facts
	 */
	private static function facts( Issue $issue, TokenList $code, int $ptr ): Facts {
		if ( ! $issue->is_error() ) {
			return Facts::none();
		}

		// "new Foo" is reported on "new".
		if ( 'T_NEW' === $code->type( $ptr ) && self::from_sniff( $issue, 'PHPCompatibility.Classes.RemovedClasses' ) ) {
			$ptr = $code->next( $ptr, $code->count() - 1 );
			if ( 'T_NS_SEPARATOR' === $code->type( $ptr ) ) {
				$ptr = $code->next( $ptr, $code->count() - 1 );
			}
		}

		if ( 'T_STRING' !== $code->type( $ptr ) ) {
			return Facts::none();
		}

		$name = $code->content( $ptr );
		if ( self::from_sniff( $issue, 'PHPCompatibility.FunctionUse.RemovedFunctions' ) || self::from_sniff( $issue, 'PHPCompatibility.Extensions.RemovedExtensions' ) ) {
			return self::is_function_call( $code, $ptr ) ? Facts::missing_function( $name ) : Facts::none();
		}
		if ( self::from_sniff( $issue, 'PHPCompatibility.Classes.RemovedClasses' ) ) {
			return Facts::missing_class( $name );
		}
		if ( self::from_sniff( $issue, 'PHPCompatibility.Constants.RemovedConstants' ) ) {
			return Facts::missing_constant( $name );
		}

		return Facts::none();
	}

	/**
	 * Whether a finding comes from a sniff.
	 *
	 * @param Issue  $issue The finding.
	 * @param string $sniff Sniff name, such as "PHPCompatibility.FunctionUse.RemovedFunctions".
	 * @return bool
	 */
	private static function from_sniff( Issue $issue, string $sniff ): bool {
		return 0 === strpos( $issue->source(), $sniff . '.' );
	}

	/**
	 * Whether a name is a call to a global function, such as "each(" but
	 * not "$this->each(", "Foo::each(" or "function each(".
	 *
	 * @param TokenList $code The file's tokens.
	 * @param int       $ptr  Position of the name.
	 * @return bool
	 */
	private static function is_function_call( TokenList $code, int $ptr ): bool {
		$before = $code->type( $code->prev( $ptr ) );
		$after  = $code->type( $code->next( $ptr, $code->count() - 1 ) );

		return 'T_OPEN_PARENTHESIS' === $after
			&& ! in_array( $before, array( 'T_OBJECT_OPERATOR', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_DOUBLE_COLON', 'T_FUNCTION', 'T_NEW' ), true );
	}
}

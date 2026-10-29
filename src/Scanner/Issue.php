<?php
/**
 * One compatibility problem found in a file.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * An error (breaks on the target version) or a warning (deprecated there).
 * Either can be a possible false alarm: in code that only runs when a guard
 * has a value it never has on the target version.
 */
final class Issue {

	public const ERROR   = 'error';
	public const WARNING = 'warning';

	/**
	 * Line number, starting at 1.
	 *
	 * @var int
	 */
	private $line;

	/**
	 * Column number, starting at 1.
	 *
	 * @var int
	 */
	private $column;

	/**
	 * Issue::ERROR or Issue::WARNING.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * What PHPCompatibility reports, in English.
	 *
	 * @var string
	 */
	private $message;

	/**
	 * The full sniff code, such as "PHPCompatibility.FunctionUse.RemovedFunctions.eachDeprecatedRemoved".
	 *
	 * @var string
	 */
	private $source;

	/**
	 * For a possible false alarm, the condition the code depends on, such as
	 * "PHP_VERSION_ID < 80000". Otherwise "".
	 *
	 * @var string
	 */
	private $guard = '';

	/**
	 * The value the code needs the guard to have: true inside "if ( guard )",
	 * false in its else branch.
	 *
	 * @var bool
	 */
	private $guard_runs_if = true;

	/**
	 * Creates an issue.
	 *
	 * @param int    $line    Line number, starting at 1.
	 * @param int    $column  Column number, starting at 1.
	 * @param string $type    Issue::ERROR or Issue::WARNING.
	 * @param string $message What PHPCompatibility reports.
	 * @param string $source  The full sniff code.
	 * @throws InvalidArgumentException If the type is unknown.
	 */
	public function __construct( int $line, int $column, string $type, string $message, string $source ) {
		if ( self::ERROR !== $type && self::WARNING !== $type ) {
			throw new InvalidArgumentException( sprintf( 'Unknown issue type "%s".', $type ) );
		}

		$this->line    = $line;
		$this->column  = $column;
		$this->type    = $type;
		$this->message = $message;
		$this->source  = $source;
	}

	/**
	 * Line number, starting at 1.
	 *
	 * @return int
	 */
	public function line(): int {
		return $this->line;
	}

	/**
	 * Column number, starting at 1.
	 *
	 * @return int
	 */
	public function column(): int {
		return $this->column;
	}

	/**
	 * Issue::ERROR or Issue::WARNING.
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Whether the code breaks on the target version, rather than being deprecated there.
	 *
	 * @return bool
	 */
	public function is_error(): bool {
		return self::ERROR === $this->type;
	}

	/**
	 * What PHPCompatibility reports, in English.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * The full sniff code.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Whether the code only runs when a guard has a value it never has on
	 * the target version. Such findings don't decide the verdict.
	 *
	 * @return bool
	 */
	public function is_possible_false_alarm(): bool {
		return '' !== $this->guard;
	}

	/**
	 * For a possible false alarm, the condition the code depends on, as
	 * written. Otherwise "".
	 *
	 * @return string
	 */
	public function guard(): string {
		return $this->guard;
	}

	/**
	 * The value the code needs the guard to have.
	 *
	 * @return bool
	 */
	public function guard_runs_if(): bool {
		return $this->guard_runs_if;
	}

	/**
	 * A copy marked as a possible false alarm.
	 *
	 * @param string $guard   The condition the code depends on, as written.
	 * @param bool   $runs_if The value the code needs it to have.
	 * @return self
	 */
	public function with_guard( string $guard, bool $runs_if ): self {
		$issue                = clone $this;
		$issue->guard         = $guard;
		$issue->guard_runs_if = $runs_if;
		return $issue;
	}

	/**
	 * Converts the issue to an array of scalars, for storage or JSON.
	 *
	 * @return array{line: int, column: int, type: string, message: string, source: string, guard: string, guard_runs_if: bool}
	 */
	public function to_array(): array {
		return array(
			'line'          => $this->line,
			'column'        => $this->column,
			'type'          => $this->type,
			'message'       => $this->message,
			'source'        => $this->source,
			'guard'         => $this->guard,
			'guard_runs_if' => $this->guard_runs_if,
		);
	}

	/**
	 * Restores an issue saved with to_array().
	 *
	 * @param array<string, mixed> $data Saved data.
	 * @return self
	 * @throws InvalidArgumentException If the data is malformed.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['line'], $data['column'], $data['type'], $data['message'], $data['source'] )
			|| ! is_int( $data['line'] )
			|| ! is_int( $data['column'] )
			|| ! is_string( $data['type'] )
			|| ! is_string( $data['message'] )
			|| ! is_string( $data['source'] )
		) {
			throw new InvalidArgumentException( 'Malformed issue data.' );
		}

		$issue = new self( $data['line'], $data['column'], $data['type'], $data['message'], $data['source'] );

		// Issues saved before possible false alarms existed have no guard.
		if ( isset( $data['guard'] ) && is_string( $data['guard'] ) && '' !== $data['guard'] ) {
			$issue = $issue->with_guard( $data['guard'], ! isset( $data['guard_runs_if'] ) || (bool) $data['guard_runs_if'] );
		}

		return $issue;
	}
}

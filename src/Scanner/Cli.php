<?php
/**
 * The standalone command line tool, bin/upgrade-radar.
 *
 * Runs without WordPress, for checking plugin code directly. The WP-CLI
 * command for whole sites belongs in src/Cli.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Scanner;

use InvalidArgumentException;

/**
 * Parses arguments, runs a ScanJob and prints the results.
 */
final class Cli {

	public const EXIT_OK          = 0;
	public const EXIT_ERRORS      = 1;
	public const EXIT_USAGE       = 2;
	public const EXIT_UNAVAILABLE = 3;

	/**
	 * Files per batch. Nothing limits the time here, so this only sets how
	 * often progress is shown.
	 */
	private const BATCH_SIZE = 20;

	private const USAGE = <<<'USAGE'
Usage: upgrade-radar scan --php=<version> [options] <path>...

Checks plugin and theme directories, or single PHP files, for code that
breaks or is deprecated on a newer PHP version. The code is assumed to
run on PHP 7.4 or later today.

Options:
  --php=<version>    Target PHP version: 8.1, 8.2, 8.3, 8.4 or 8.5.
  --format=<format>  "text" (default) or "json".
  --summary          Print totals per path, without details.
  -h, --help         Show this help.

A finding in code that only runs when a version or existence check is false
on the target, such as "if ( PHP_VERSION_ID < 80000 )", is a possible false
alarm: it's listed, but not counted as an error or a warning.

Exit status: 0 when no errors are found, 1 when errors are found (warnings
and possible false alarms don't count), 2 for usage errors, 3 when the
scanner can't run here.

USAGE;

	/**
	 * Stream for results.
	 *
	 * @var resource
	 */
	private $stdout;

	/**
	 * Stream for progress and problems.
	 *
	 * @var resource
	 */
	private $stderr;

	/**
	 * The scanner to use.
	 *
	 * @var FileScanner
	 */
	private $scanner;

	/**
	 * Creates the command.
	 *
	 * @param resource         $stdout  Stream for results.
	 * @param resource         $stderr  Stream for progress and problems.
	 * @param FileScanner|null $scanner The scanner. Defaults to a new Scanner.
	 */
	public function __construct( $stdout, $stderr, ?FileScanner $scanner = null ) {
		$this->stdout  = $stdout;
		$this->stderr  = $stderr;
		$this->scanner = $scanner ?? new Scanner();
	}

	/**
	 * Runs the command.
	 *
	 * @param string[] $args Command-line arguments, without the script name.
	 * @return int Exit status.
	 */
	public function run( array $args ): int {
		try {
			$options = $this->parse( $args );
		} catch ( InvalidArgumentException $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
			fwrite( $this->stderr, 'upgrade-radar: ' . $e->getMessage() . PHP_EOL . 'Run "upgrade-radar --help" for usage.' . PHP_EOL );
			return self::EXIT_USAGE;
		}

		if ( $options['help'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
			fwrite( $this->stdout, self::USAGE );
			return self::EXIT_OK;
		}

		$job = ScanJob::create( $options['target'], $options['paths'] );
		try {
			while ( ! $job->is_done() ) {
				$job->run_batch( $this->scanner, self::BATCH_SIZE, INF );
				$this->show_progress( $job );
			}
		} catch ( ScannerUnavailable $e ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
			fwrite( $this->stderr, 'upgrade-radar: ' . $e->getMessage() . PHP_EOL );
			return self::EXIT_UNAVAILABLE;
		}

		$results = $job->results();
		if ( 'json' === $options['format'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
			fwrite( $this->stdout, $this->json( $job->target(), $results ) . PHP_EOL );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
			fwrite( $this->stdout, $this->text( $job->target(), $results, $options['summary'] ) );
		}

		foreach ( $results as $result ) {
			if ( $result->error_count() > 0 ) {
				return self::EXIT_ERRORS;
			}
		}

		return self::EXIT_OK;
	}

	/**
	 * Parses the arguments.
	 *
	 * @param string[] $args Command-line arguments.
	 * @return array{help: bool, target: TargetVersion|null, format: string, summary: bool, paths: array<string, string>}
	 * @throws InvalidArgumentException For usage errors.
	 */
	private function parse( array $args ): array {
		$options = array(
			'help'    => array() === $args,
			'target'  => null,
			'format'  => 'text',
			'summary' => false,
			'paths'   => array(),
		);
		$version = null;
		$command = null;

		$positional = false;
		while ( array() !== $args ) {
			$arg = (string) array_shift( $args );

			if ( ! $positional && ( '--help' === $arg || '-h' === $arg ) ) {
				$options['help'] = true;
			} elseif ( ! $positional && '--' === $arg ) {
				$positional = true;
			} elseif ( ! $positional && '--summary' === $arg ) {
				$options['summary'] = true;
			} elseif ( ! $positional && preg_match( '/^--(php|format)(?:=(.*))?$/', $arg, $match ) ) {
				$value = $match[2] ?? array_shift( $args );
				if ( null === $value || '' === $value ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output; anything shown in wp-admin is escaped there.
					throw new InvalidArgumentException( sprintf( 'Option --%s needs a value.', $match[1] ) );
				}

				if ( 'php' === $match[1] ) {
					$version = $value;
				} elseif ( in_array( $value, array( 'text', 'json' ), true ) ) {
					$options['format'] = $value;
				} else {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output; anything shown in wp-admin is escaped there.
					throw new InvalidArgumentException( sprintf( 'Unknown format "%s". Use "text" or "json".', $value ) );
				}
			} elseif ( ! $positional && '-' === substr( $arg, 0, 1 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output; anything shown in wp-admin is escaped there.
				throw new InvalidArgumentException( sprintf( 'Unknown option "%s".', $arg ) );
			} elseif ( null === $command ) {
				$command = $arg;
			} else {
				$options['paths'][ rtrim( $arg, '/\\' ) ] = $arg;
			}
		}

		if ( $options['help'] ) {
			return $options;
		}

		if ( 'scan' !== $command ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output; anything shown in wp-admin is escaped there.
			throw new InvalidArgumentException( null === $command ? 'Missing the "scan" command.' : sprintf( 'Unknown command "%s".', $command ) );
		}

		if ( null === $version ) {
			throw new InvalidArgumentException( 'Choose a target PHP version with --php, for example --php=8.3.' );
		}
		$options['target'] = TargetVersion::from_string( $version );

		if ( array() === $options['paths'] ) {
			throw new InvalidArgumentException( 'Give at least one plugin or theme directory, or PHP file, to scan.' );
		}

		foreach ( $options['paths'] as $path ) {
			if ( ! is_readable( $path ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message, not output; anything shown in wp-admin is escaped there.
				throw new InvalidArgumentException( sprintf( 'Not a readable file or directory: %s', $path ) );
			}
		}

		return $options;
	}

	/**
	 * Shows progress on a terminal.
	 *
	 * @param ScanJob $job The running job.
	 */
	private function show_progress( ScanJob $job ): void {
		if ( ! function_exists( 'stream_isatty' ) || ! stream_isatty( $this->stderr ) ) {
			return;
		}

		$progress = $job->progress();
		$line     = $job->is_done() ? '' : sprintf( 'Scanning %s, %d files checked so far', $progress['current'], $progress['files_done'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Command-line runner writing to its own stdout/stderr; WP_Filesystem is for site files, not process streams.
		fwrite( $this->stderr, "\r\033[K" . $line );
	}

	/**
	 * Formats the results for people.
	 *
	 * @param TargetVersion $target  The target version.
	 * @param ItemResult[]  $results Results per path.
	 * @param bool          $summary Whether to leave out details.
	 * @return string
	 */
	private function text( TargetVersion $target, array $results, bool $summary ): string {
		$out  = sprintf( 'PHP %s compatibility, for code that runs on PHP %s or later today.', $target->to_string(), TargetVersion::BASELINE ) . PHP_EOL;
		$out .= sprintf(
			'Rules: PHPCompatibilityWP %s with PHPCompatibility %s. These are alpha releases, so some findings may be false alarms.',
			Scanner::RULES['phpcompatibility/phpcompatibility-wp'],
			Scanner::RULES['phpcompatibility/php-compatibility']
		) . PHP_EOL;

		$totals = array( 0, 0, 0, 0 );
		foreach ( $results as $result ) {
			$unchecked  = count( $result->unchecked_files() );
			$totals[0] += $result->error_count();
			$totals[1] += $result->warning_count();
			$totals[2] += $unchecked;
			$totals[3] += $result->false_alarm_count();

			$out .= PHP_EOL . $result->id() . ': ';
			if ( ScanJob::ITEM_MISSING === $result->status() ) {
				$out .= 'not scanned. ' . $result->detail() . PHP_EOL;
				continue;
			}

			$out .= sprintf(
				'%s, %s%s in %s',
				self::count( $result->error_count(), 'error' ),
				self::count( $result->warning_count(), 'warning' ),
				$result->false_alarm_count() > 0 ? ', ' . self::count( $result->false_alarm_count(), 'possible false alarm' ) : '',
				self::count( $result->files_found(), 'file' )
			);
			$out .= ( $unchecked > 0 ? sprintf( ' (%s not checked)', self::count( $unchecked, 'file' ) ) : '' ) . PHP_EOL;

			if ( $summary ) {
				continue;
			}

			foreach ( $result->reports() as $report ) {
				$out .= '  ' . $report->path() . PHP_EOL;
				if ( FileReport::SCANNED !== $report->status() ) {
					$out .= sprintf( '    %s: %s', $report->status(), $report->detail() ) . PHP_EOL;
				}

				foreach ( $report->issues() as $issue ) {
					$out .= sprintf( '  %6s  %-7s  %s', $issue->line() . ':' . $issue->column(), $issue->type(), $issue->message() ) . PHP_EOL;
					$out .= str_repeat( ' ', 19 ) . $issue->source() . PHP_EOL;
					if ( $issue->is_possible_false_alarm() ) {
						$out .= str_repeat( ' ', 19 ) . sprintf(
							'Possible false alarm: only runs if %s%s, which never happens on PHP %s.',
							$issue->guard(),
							$issue->guard_runs_if() ? '' : ' is false',
							$target->to_string()
						) . PHP_EOL;
					}
				}
			}
		}

		$out .= PHP_EOL . sprintf( 'Total: %s, %s', self::count( $totals[0], 'error' ), self::count( $totals[1], 'warning' ) );
		$out .= $totals[3] > 0 ? ', ' . self::count( $totals[3], 'possible false alarm' ) : '';
		$out .= ( $totals[2] > 0 ? sprintf( ', %s not checked', self::count( $totals[2], 'file' ) ) : '' ) . '.' . PHP_EOL;

		return $out;
	}

	/**
	 * Formats the results as JSON.
	 *
	 * @param TargetVersion $target  The target version.
	 * @param ItemResult[]  $results Results per path.
	 * @return string
	 */
	private function json( TargetVersion $target, array $results ): string {
		$items  = array();
		$totals = array(
			'errors'          => 0,
			'warnings'        => 0,
			'false_alarms'    => 0,
			'unchecked_files' => 0,
		);

		foreach ( $results as $result ) {
			$totals['errors']          += $result->error_count();
			$totals['warnings']        += $result->warning_count();
			$totals['false_alarms']    += $result->false_alarm_count();
			$totals['unchecked_files'] += count( $result->unchecked_files() );

			$items[] = array(
				'id'           => $result->id(),
				'path'         => $result->path(),
				'status'       => $result->status(),
				'detail'       => $result->detail(),
				'errors'       => $result->error_count(),
				'warnings'     => $result->warning_count(),
				'false_alarms' => $result->false_alarm_count(),
				'files_found'  => $result->files_found(),
				'files'        => array_map(
					static function ( FileReport $report ): array {
						return $report->to_array();
					},
					$result->reports()
				),
			);
		}

		return (string) json_encode(
			array(
				'target'   => $target->to_string(),
				'baseline' => TargetVersion::BASELINE,
				'rules'    => Scanner::RULES,
				'totals'   => $totals,
				'items'    => $items,
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		);
	}

	/**
	 * Formats a count with a noun, such as "1 error" or "2 errors".
	 *
	 * @param int    $count The count.
	 * @param string $noun  Singular noun.
	 * @return string
	 */
	private static function count( int $count, string $noun ): string {
		return $count . ' ' . $noun . ( 1 === $count ? '' : 's' );
	}
}

<?php
/**
 * Tests for the standalone command line tool.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\Cli;
use UpgradeRadar\Scanner\Scanner;

/**
 * Cli tests.
 */
final class CliTest extends TestCase {

	/**
	 * Shared, because loading the ruleset takes a moment.
	 *
	 * @var Scanner|null
	 */
	private static $scanner;

	/**
	 * Errors make the exit status 1 and are listed with their sniff codes.
	 */
	public function test_lists_errors_and_exits_with_1(): void {
		list( $status, $out, $err ) = $this->run_cli( array( 'scan', '--php=8.3', Fixtures::path( 'breaks' ), Fixtures::path( 'clean' ) ) );

		$this->assertSame( Cli::EXIT_ERRORS, $status, $err );
		$this->assertStringContainsString( 'PHP 8.3 compatibility', $out );
		$this->assertStringContainsString( 'alpha releases', $out );
		$this->assertStringContainsString( Fixtures::path( 'breaks' ) . ': 13 errors, 16 warnings in 6 files', $out );
		$this->assertStringContainsString( Fixtures::path( 'clean' ) . ': 0 errors, 0 warnings in 7 files', $out );
		$this->assertStringContainsString( 'PHPCompatibility.FunctionNameRestrictions.RemovedPHP4StyleConstructors.Removed', $out );
		$this->assertStringContainsString( 'Total: 13 errors, 16 warnings.', $out );
	}

	/**
	 * Warnings alone don't fail the run, and --summary leaves out details.
	 */
	public function test_warnings_alone_exit_with_0(): void {
		list( $status, $out ) = $this->run_cli( array( 'scan', '--summary', '--php', '8.4', Fixtures::path( 'breaks/php84.php' ) ) );

		$this->assertSame( Cli::EXIT_OK, $status );
		$this->assertStringContainsString( ': 0 errors, 6 warnings in 1 file', $out );
		$this->assertStringNotContainsString( 'PHPCompatibility.', $out );
	}

	/**
	 * JSON output has everything needed to rebuild the report.
	 */
	public function test_prints_json(): void {
		list( $status, $out ) = $this->run_cli( array( 'scan', '--format=json', '--php=8.1', Fixtures::path( 'breaks/php81.php' ), Fixtures::path( 'clean/modern-plugin' ) ) );
		$json                 = json_decode( $out, true );

		$this->assertSame( Cli::EXIT_ERRORS, $status );
		$this->assertSame( '8.1', $json['target'] );
		$this->assertSame( '7.4', $json['baseline'] );
		$this->assertSame( Scanner::RULES, $json['rules'] );
		$this->assertSame(
			array(
				'errors'          => 1,
				'warnings'        => 6,
				'unchecked_files' => 0,
			),
			$json['totals']
		);
		$this->assertSame( 'php81.php', $json['items'][0]['files'][0]['path'] );
		$this->assertCount( 7, $json['items'][0]['files'][0]['issues'] );
		$this->assertSame( array( 3, array() ), array( $json['items'][1]['files_found'], $json['items'][1]['files'] ) );
	}

	/**
	 * Bad arguments.
	 *
	 * @return array<string, array{string[], string}>
	 */
	public function usage_errors(): array {
		return array(
			'no command'      => array( array( '--php=8.3', 'x' ), 'Unknown command' ),
			'unknown command' => array( array( 'fix', '--php=8.3', 'x' ), 'Unknown command "fix"' ),
			'no target'       => array( array( 'scan', __DIR__ ), 'Choose a target PHP version' ),
			'bad target'      => array( array( 'scan', '--php=7.4', __DIR__ ), 'Unsupported target PHP version "7.4"' ),
			'empty target'    => array( array( 'scan', '--php=', __DIR__ ), 'needs a value' ),
			'bad format'      => array( array( 'scan', '--php=8.3', '--format=xml', __DIR__ ), 'Unknown format "xml"' ),
			'unknown option'  => array( array( 'scan', '--php=8.3', '--fix', __DIR__ ), 'Unknown option "--fix"' ),
			'no paths'        => array( array( 'scan', '--php=8.3' ), 'at least one' ),
			'missing path'    => array( array( 'scan', '--php=8.3', __DIR__ . '/nope' ), 'Not a readable file or directory' ),
		);
	}

	/**
	 * Usage errors exit with 2 and explain themselves.
	 *
	 * @dataProvider usage_errors
	 *
	 * @param string[] $args    Arguments.
	 * @param string   $message Expected part of the message.
	 */
	public function test_rejects_bad_arguments( array $args, string $message ): void {
		list( $status, $out, $err ) = $this->run_cli( $args );

		$this->assertSame( Cli::EXIT_USAGE, $status );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( $message, $err );
	}

	/**
	 * Help goes to stdout, with or without a command.
	 */
	public function test_shows_help(): void {
		foreach ( array( array(), array( '--help' ), array( 'scan', '-h' ) ) as $args ) {
			list( $status, $out ) = $this->run_cli( $args );
			$this->assertSame( Cli::EXIT_OK, $status );
			$this->assertStringStartsWith( 'Usage: upgrade-radar scan --php=<version>', $out );
		}
	}

	/**
	 * A path after "--" may start with a dash.
	 */
	public function test_accepts_paths_after_a_double_dash(): void {
		$dir = Temp::dir();
		mkdir( $dir . '/-odd' );
		file_put_contents( $dir . '/-odd/plugin.php', "<?php\necho 'hi';\n" );

		$cwd = (string) getcwd();
		chdir( $dir );
		try {
			list( $status, $out ) = $this->run_cli( array( 'scan', '--php=8.3', '--', '-odd' ) );
		} finally {
			chdir( $cwd );
		}

		$this->assertSame( Cli::EXIT_OK, $status );
		$this->assertStringContainsString( '-odd: 0 errors, 0 warnings in 1 file', $out );
	}

	/**
	 * A scanner that can't run exits with 3.
	 */
	public function test_exits_with_3_when_the_scanner_cannot_run(): void {
		$out = fopen( 'php://memory', 'w+' );
		$err = fopen( 'php://memory', 'w+' );
		$cli = new Cli( $out, $err, new Scanner( array( 'vendor_dir' => Temp::dir() ) ) );

		$this->assertSame( Cli::EXIT_UNAVAILABLE, $cli->run( array( 'scan', '--php=8.3', Fixtures::path( 'clean' ) ) ) );
		rewind( $err );
		$this->assertStringContainsString( 'PHP_CodeSniffer is missing', (string) stream_get_contents( $err ) );
	}

	/**
	 * Runs the tool with in-memory streams.
	 *
	 * @param string[] $args Arguments.
	 * @return array{int, string, string} Exit status, stdout and stderr.
	 */
	private function run_cli( array $args ): array {
		if ( null === self::$scanner ) {
			self::$scanner = new Scanner();
		}

		$out    = fopen( 'php://memory', 'w+' );
		$err    = fopen( 'php://memory', 'w+' );
		$status = ( new Cli( $out, $err, self::$scanner ) )->run( $args );
		rewind( $out );
		rewind( $err );

		return array( $status, (string) stream_get_contents( $out ), (string) stream_get_contents( $err ) );
	}
}

<?php
/**
 * Tests for finding the files of a plugin or theme.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UpgradeRadar\Scanner\FileFinder;

/**
 * FileFinder tests.
 */
final class FileFinderTest extends TestCase {

	/**
	 * Only PHP files are listed, sorted, and only inside the directory.
	 */
	public function test_lists_php_files_inside_the_directory(): void {
		$outside = Temp::dir();
		file_put_contents( $outside . '/secret.php', '<?php' );
		mkdir( $outside . '/lib' );
		file_put_contents( $outside . '/lib/lib.php', '<?php' );

		$root = Temp::dir();
		foreach ( array( 'plugin.php', 'includes/Helper.PHP', 'includes/view.phtml', 'includes/old.inc', 'vendor/acme/lib.php', 'assets/app.js', 'readme.txt', 'node_modules/pkg/index.php', '.git/hooks/hook.php', '.github/check.php' ) as $file ) {
			if ( ! is_dir( dirname( $root . '/' . $file ) ) ) {
				mkdir( dirname( $root . '/' . $file ), 0777, true );
			}
			file_put_contents( $root . '/' . $file, '<?php' );
		}

		$links = @symlink( $outside . '/secret.php', $root . '/secret.php' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Symlinks may be unavailable.
			&& @symlink( $outside . '/lib', $root . '/lib' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			&& @symlink( $root . '/plugin.php', $root . '/alias.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$found = ( new FileFinder() )->find( $root );

		$this->assertSame( $root, $found['root'] );
		$this->assertSame( array( 'includes/Helper.PHP', 'includes/old.inc', 'includes/view.phtml', 'plugin.php', 'vendor/acme/lib.php' ), $found['files'] );
		if ( ! $links ) {
			$this->markTestIncomplete( 'Symlinks are not available here, so they were not tested.' );
		}
	}

	/**
	 * A single-file plugin, such as hello.php, is listed on its own.
	 */
	public function test_lists_a_single_file(): void {
		$found = ( new FileFinder() )->find( Fixtures::path( 'breaks/php80.php' ) );

		$this->assertSame( realpath( Fixtures::path( 'breaks' ) ), $found['root'] );
		$this->assertSame( array( 'php80.php' ), $found['files'] );
		$this->assertSame( array(), ( new FileFinder( array( 'inc' ) ) )->find( Fixtures::path( 'breaks/php80.php' ) )['files'] );
	}

	/**
	 * A path that doesn't exist is an error the caller handles.
	 */
	public function test_rejects_a_missing_path(): void {
		$this->expectException( InvalidArgumentException::class );
		( new FileFinder() )->find( Fixtures::path( 'does-not-exist' ) );
	}
}

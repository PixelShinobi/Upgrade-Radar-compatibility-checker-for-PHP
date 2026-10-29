<?php
/**
 * Sets up the Docker WordPress for the end-to-end test.
 *
 * WP-CLI runs it for tests/e2e/run.sh, with wp eval-file. Creates and
 * activates 35 plugins and a child theme, and stores update data that leads to
 * each verdict. The plugins' main files only hold a header, so none of the
 * scanned code ever runs. A rival scanner plugin that loads its own
 * PHP_CodeSniffer on every request is created too, but left inactive.
 *
 * @package UpgradeRadar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Copies a file or a directory tree.
 *
 * @param string $from Source.
 * @param string $to   Destination.
 */
function upgrade_radar_e2e_copy( string $from, string $to ): void {
	if ( is_file( $from ) ) {
		wp_mkdir_p( dirname( $to ) );
		copy( $from, $to );
		return;
	}

	$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		upgrade_radar_e2e_copy( $file->getPathname(), $to . substr( $file->getPathname(), strlen( $from ) ) );
	}
}

/**
 * Creates a plugin whose main file only holds a header.
 *
 * @param string                $slug    Directory name.
 * @param string                $name    Plugin name.
 * @param string                $version Version.
 * @param array<string, string> $copies  Files or directories to copy in, keyed by destination.
 * @return string The plugin file.
 */
function upgrade_radar_e2e_plugin( string $slug, string $name, string $version, array $copies ): string {
	$dir = WP_PLUGIN_DIR . '/' . $slug;
	wp_mkdir_p( $dir );
	file_put_contents( "$dir/$slug.php", "<?php\n/**\n * Plugin Name: $name\n * Version: $version\n */\n\ndefined( 'ABSPATH' ) || exit;\n" );

	foreach ( $copies as $to => $from ) {
		upgrade_radar_e2e_copy( $from, "$dir/$to" );
	}

	return "$slug/$slug.php";
}

$upgrade_radar_e2e_fixtures = WP_PLUGIN_DIR . '/upgrade-radar/tests/fixtures';

// Left active if an earlier run stopped halfway.
deactivate_plugins( 'ur-e2e-rival-scanner/ur-e2e-rival-scanner.php' );

// Plugins with known breaks, each set up for a different verdict.
$upgrade_radar_e2e_plugins = array(
	'update_first'    => upgrade_radar_e2e_plugin( 'ur-e2e-legacy-forms', 'UR E2E Legacy Forms', '1.2', array( 'lib' => "$upgrade_radar_e2e_fixtures/breaks" ) ),
	'no_compatible'   => upgrade_radar_e2e_plugin( 'ur-e2e-legacy-gallery', 'UR E2E Legacy Gallery', '3.1', array( 'lib/legacy.php' => "$upgrade_radar_e2e_fixtures/breaks/php80.php" ) ),
	'needs_developer' => upgrade_radar_e2e_plugin( 'ur-e2e-custom-tools', 'UR E2E Custom Tools', '0.9', array( 'lib/legacy.php' => "$upgrade_radar_e2e_fixtures/breaks/php81.php" ) ),
	'not_checked'     => upgrade_radar_e2e_plugin( 'ur-e2e-new-sliders', 'UR E2E New Sliders', '2.0', array( 'lib/legacy.php' => "$upgrade_radar_e2e_fixtures/breaks/php80.php" ) ),
	'warnings_only'   => upgrade_radar_e2e_plugin( 'ur-e2e-deprecations', 'UR E2E Deprecations', '1.0', array( 'lib/deprecated.php' => "$upgrade_radar_e2e_fixtures/breaks/php84.php" ) ),
	'guarded'         => upgrade_radar_e2e_plugin( 'ur-e2e-media-tools', 'UR E2E Media Tools', '4.0', array( 'lib/ID3' => ABSPATH . 'wp-includes/ID3' ) ),
	'modern'          => upgrade_radar_e2e_plugin( 'ur-e2e-modern', 'UR E2E Modern', '5.0', array( 'lib' => "$upgrade_radar_e2e_fixtures/clean" ) ),
	'big_data'        => upgrade_radar_e2e_plugin( 'ur-e2e-big-data', 'UR E2E Big Data', '1.0', array() ),
);

// More than the 2 MB scan limit, so it's skipped as too large.
file_put_contents( WP_PLUGIN_DIR . '/ur-e2e-big-data/data.php', "<?php\nreturn array(\n" . str_repeat( "\t'some-key' => 'some value',\n", 100000 ) . ");\n" );

// A single-file plugin, with a function that would break on PHP 8 if it ran.
file_put_contents( WP_PLUGIN_DIR . '/ur-e2e-single.php', "<?php\n/**\n * Plugin Name: UR E2E Single File\n * Version: 1.0\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nfunction ur_e2e_single_next( \$items ) {\n\treturn each( \$items );\n}\n" );
$upgrade_radar_e2e_plugins['single'] = 'ur-e2e-single.php';

// 26 plugins of real WordPress code, overlapping slices of wp-includes and
// wp-admin, for volume. Bundled libraries with guarded legacy calls are left out.
$upgrade_radar_e2e_core = array();
foreach ( array( 'wp-includes', 'wp-admin' ) as $upgrade_radar_e2e_dir ) {
	$upgrade_radar_e2e_files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH . $upgrade_radar_e2e_dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $upgrade_radar_e2e_files as $upgrade_radar_e2e_file ) {
		$upgrade_radar_e2e_relative = substr( $upgrade_radar_e2e_file->getPathname(), strlen( ABSPATH ) );
		if ( 'php' === $upgrade_radar_e2e_file->getExtension() && ! preg_match( '#/(ID3|SimplePie|PHPMailer)/#', $upgrade_radar_e2e_relative ) ) {
			$upgrade_radar_e2e_core[] = $upgrade_radar_e2e_relative;
		}
	}
}
sort( $upgrade_radar_e2e_core );

for ( $upgrade_radar_e2e_i = 1; $upgrade_radar_e2e_i <= 26; $upgrade_radar_e2e_i++ ) {
	$upgrade_radar_e2e_slice = array_slice( $upgrade_radar_e2e_core, ( $upgrade_radar_e2e_i - 1 ) * 55, 165 );
	$upgrade_radar_e2e_copy  = array();
	foreach ( $upgrade_radar_e2e_slice as $upgrade_radar_e2e_relative ) {
		$upgrade_radar_e2e_copy[ 'lib/' . $upgrade_radar_e2e_relative ] = ABSPATH . $upgrade_radar_e2e_relative;
	}
	$upgrade_radar_e2e_plugins[ "pack_$upgrade_radar_e2e_i" ] = upgrade_radar_e2e_plugin( sprintf( 'ur-e2e-pack-%02d', $upgrade_radar_e2e_i ), sprintf( 'UR E2E Pack %02d', $upgrade_radar_e2e_i ), '1.0', $upgrade_radar_e2e_copy );
}

activate_plugins( array_values( $upgrade_radar_e2e_plugins ) );

// The rival: loads its own copy of PHP_CodeSniffer on every request, like a
// scanner plugin with a sloppy autoloader would. Left inactive.
$upgrade_radar_e2e_rival = WP_PLUGIN_DIR . '/ur-e2e-rival-scanner';
upgrade_radar_e2e_copy( WP_PLUGIN_DIR . '/upgrade-radar/vendor/squizlabs/php_codesniffer/autoload.php', "$upgrade_radar_e2e_rival/vendor/squizlabs/php_codesniffer/autoload.php" );
upgrade_radar_e2e_copy( WP_PLUGIN_DIR . '/upgrade-radar/vendor/squizlabs/php_codesniffer/src', "$upgrade_radar_e2e_rival/vendor/squizlabs/php_codesniffer/src" );
file_put_contents( "$upgrade_radar_e2e_rival/ur-e2e-rival-scanner.php", "<?php\n/**\n * Plugin Name: UR E2E Rival Scanner\n * Version: 1.0\n */\n\ndefined( 'ABSPATH' ) || exit;\n\nrequire_once __DIR__ . '/vendor/squizlabs/php_codesniffer/autoload.php';\nclass_exists( 'PHP_CodeSniffer\\\\Autoload' );\n" );

// A child theme of Twenty Twenty-Five, so the parent theme is scanned too.
$upgrade_radar_e2e_child = get_theme_root() . '/ur-e2e-child';
wp_mkdir_p( "$upgrade_radar_e2e_child/inc" );
file_put_contents( "$upgrade_radar_e2e_child/style.css", "/*\nTheme Name: UR E2E Child\nTemplate: twentytwentyfive\nVersion: 1.0\n*/\n" );
file_put_contents( "$upgrade_radar_e2e_child/functions.php", "<?php\nadd_action( 'wp_enqueue_scripts', static function () {\n\twp_enqueue_style( 'ur-e2e-child', get_stylesheet_uri() );\n} );\n" );
upgrade_radar_e2e_copy( "$upgrade_radar_e2e_fixtures/breaks/php82.php", "$upgrade_radar_e2e_child/inc/legacy.php" );
switch_theme( 'ur-e2e-child' );

// Update data, as WordPress stores it after checking for updates. One plugin
// was installed after the last check, so WordPress hasn't checked it.
$upgrade_radar_e2e_updates               = new stdClass();
$upgrade_radar_e2e_updates->last_checked = time();
$upgrade_radar_e2e_updates->checked      = wp_list_pluck( get_plugins(), 'Version' );
$upgrade_radar_e2e_updates->response     = array(
	$upgrade_radar_e2e_plugins['update_first'] => (object) array(
		'slug'        => 'ur-e2e-legacy-forms',
		'plugin'      => $upgrade_radar_e2e_plugins['update_first'],
		'new_version' => '2.0',
	),
);
$upgrade_radar_e2e_updates->no_update    = array();
foreach ( $upgrade_radar_e2e_plugins as $upgrade_radar_e2e_key => $upgrade_radar_e2e_file ) {
	if ( in_array( $upgrade_radar_e2e_key, array( 'no_compatible', 'guarded', 'modern', 'warnings_only' ), true ) || 0 === strpos( $upgrade_radar_e2e_key, 'pack_' ) ) {
		$upgrade_radar_e2e_updates->no_update[ $upgrade_radar_e2e_file ] = (object) array( 'new_version' => $upgrade_radar_e2e_updates->checked[ $upgrade_radar_e2e_file ] );
	}
}
unset( $upgrade_radar_e2e_updates->checked[ $upgrade_radar_e2e_plugins['not_checked'] ] );
set_site_transient( 'update_plugins', $upgrade_radar_e2e_updates );

$upgrade_radar_e2e_themes               = new stdClass();
$upgrade_radar_e2e_themes->last_checked = time();
$upgrade_radar_e2e_themes->checked      = array();
foreach ( wp_get_themes() as $upgrade_radar_e2e_slug => $upgrade_radar_e2e_theme ) {
	$upgrade_radar_e2e_themes->checked[ $upgrade_radar_e2e_slug ] = $upgrade_radar_e2e_theme->get( 'Version' );
}
$upgrade_radar_e2e_themes->response  = array();
$upgrade_radar_e2e_themes->no_update = array( 'twentytwentyfive' => array( 'new_version' => $upgrade_radar_e2e_themes->checked['twentytwentyfive'] ) );
set_site_transient( 'update_themes', $upgrade_radar_e2e_themes );

WP_CLI::success( sprintf( '%d plugins active, theme %s.', count( (array) get_option( 'active_plugins' ) ), get_stylesheet() ) );

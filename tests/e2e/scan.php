<?php
/**
 * End-to-end test driver: runs scans through the admin page's own endpoints,
 * the way the page's script does, and checks the results.
 *
 * Runs on the host, against the Docker WordPress, so it doesn't load WordPress.
 * After setting up the site, tests/e2e/run.sh runs each scenario in turn:
 * php tests/e2e/scan.php full|rival|resume|cancel|background
 *
 * @package UpgradeRadar
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain PHP, outside WordPress.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Terminal output.

$upgrade_radar_e2e = array(
	'url'      => false !== getenv( 'UPGRADE_RADAR_E2E_URL' ) ? (string) getenv( 'UPGRADE_RADAR_E2E_URL' ) : 'http://localhost:8080',
	'cookies'  => (string) tempnam( sys_get_temp_dir(), 'upgrade-radar-e2e' ),
	'failures' => 0,
);

/**
 * Sends a request as the logged-in admin.
 *
 * @param string               $path Path below the site URL.
 * @param array<string, mixed> $post Form fields, for a POST request.
 * @return array{status: int, body: string, seconds: float}
 */
function upgrade_radar_e2e_request( string $path, array $post = array() ): array {
	global $upgrade_radar_e2e;

	$curl = curl_init( $upgrade_radar_e2e['url'] . $path );
	curl_setopt_array(
		$curl,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_COOKIEJAR      => $upgrade_radar_e2e['cookies'],
			CURLOPT_COOKIEFILE     => $upgrade_radar_e2e['cookies'],
			CURLOPT_TIMEOUT        => 120,
		)
	);
	if ( array() !== $post ) {
		curl_setopt( $curl, CURLOPT_POSTFIELDS, http_build_query( $post ) );
	}

	$started = microtime( true );
	$body    = (string) curl_exec( $curl );

	return array(
		'status'  => (int) curl_getinfo( $curl, CURLINFO_HTTP_CODE ),
		'body'    => $body,
		'seconds' => microtime( true ) - $started,
	);
}

/**
 * Records a check and prints it.
 *
 * @param bool   $passed  Whether the check passed.
 * @param string $message What was checked.
 */
function upgrade_radar_e2e_check( bool $passed, string $message ): void {
	global $upgrade_radar_e2e;

	echo ( $passed ? 'PASS ' : 'FAIL ' ) . $message . PHP_EOL;
	if ( ! $passed ) {
		++$upgrade_radar_e2e['failures'];
	}
}

/**
 * Logs in as admin and returns the scan page.
 *
 * @return string The page's HTML.
 */
function upgrade_radar_e2e_login(): string {
	upgrade_radar_e2e_request( '/wp-login.php' );
	upgrade_radar_e2e_request(
		'/wp-login.php',
		array(
			'log'        => 'admin',
			'pwd'        => 'admin',
			'testcookie' => '1',
		)
	);

	$page = upgrade_radar_e2e_request( '/wp-admin/tools.php?page=upgrade-radar' );
	upgrade_radar_e2e_check( 200 === $page['status'] && false !== strpos( $page['body'], 'upgrade-radar' ), 'The Tools → Upgrade Radar page loads' );

	return $page['body'];
}

/**
 * Submits one of the page's one-button forms, such as starting a scan.
 *
 * @param string               $page   The page's HTML.
 * @param string               $action The form's action.
 * @param array<string, mixed> $fields Extra fields.
 * @return string The page after the redirect.
 */
function upgrade_radar_e2e_submit( string $page, string $action, array $fields = array() ): string {
	$found = preg_match( '/name="action" value="' . preg_quote( $action, '/' ) . '".*?name="_wpnonce" value="([^"]+)"/s', $page, $match );
	upgrade_radar_e2e_check( 1 === $found, "The page has a form for $action" );

	$response = upgrade_radar_e2e_request(
		'/wp-admin/admin-post.php',
		array_merge(
			array(
				'action'   => $action,
				'_wpnonce' => $match[1] ?? '',
			),
			$fields
		)
	);

	return $response['body'];
}

/**
 * Runs batches through AJAX, as the page's script does, until the scan
 * stops running or the limit is reached.
 *
 * @param string $page  The page's HTML, with the script's settings.
 * @param int    $limit Most requests to send.
 * @return array{summary: array<string, mixed>, requests: int, slowest: float, total: float}
 */
function upgrade_radar_e2e_run( string $page, int $limit ): array {
	preg_match( '/window\.upgradeRadar = (\{.*?\});/', $page, $match );
	$settings = (array) json_decode( $match[1] ?? '{}', true );
	$run      = array(
		'summary'  => array(),
		'requests' => 0,
		'slowest'  => 0.0,
		'total'    => 0.0,
	);

	while ( $run['requests'] < $limit ) {
		$response = upgrade_radar_e2e_request(
			'/wp-admin/admin-ajax.php',
			array(
				'action' => 'upgrade_radar_step',
				'nonce'  => $settings['nonce'] ?? '',
			)
		);
		$json     = json_decode( $response['body'], true );

		++$run['requests'];
		$run['slowest'] = max( $run['slowest'], $response['seconds'] );
		$run['total']  += $response['seconds'];

		if ( 200 !== $response['status'] || ! is_array( $json ) || empty( $json['success'] ) ) {
			upgrade_radar_e2e_check( false, sprintf( 'Batch request %d answered HTTP %d: %s', $run['requests'], $response['status'], substr( $response['body'], 0, 300 ) ) );
			break;
		}

		$run['summary'] = $json['data'];
		printf( "     batch %2d: %5.1fs  %3d%%  %s %s\n", $run['requests'], $response['seconds'], $json['data']['percent'], $json['data']['text'], $json['data']['problem'] );
		if ( 'running' !== $json['data']['status'] ) {
			break;
		}

		if ( '' !== $json['data']['problem'] || $json['data']['busy'] ) {
			sleep( 2 );
		}
	}

	return $run;
}

/**
 * Reads the verdict of each row in the results table.
 *
 * @param string $page The page's HTML.
 * @return array<string, array{verdict: string, text: string}> Keyed by item name.
 */
function upgrade_radar_e2e_verdicts( string $page ): array {
	preg_match_all( '#<tr>\s*<td>\s*<strong>(.*?)</strong>.*?upgrade-radar-verdict--([a-z_]+)">.*?<p>(.*?)</p>#s', $page, $matches, PREG_SET_ORDER );

	$verdicts = array();
	foreach ( $matches as $match ) {
		$verdicts[ html_entity_decode( $match[1], ENT_QUOTES ) ] = array(
			'verdict' => $match[2],
			'text'    => html_entity_decode( $match[3], ENT_QUOTES ),
		);
	}

	return $verdicts;
}

/**
 * Full scan of the site: 30+ plugins and a child theme, for PHP 8.4.
 */
function upgrade_radar_e2e_full(): void {
	$limits = upgrade_radar_e2e_request( '/ur-e2e-limits.php' )['body'];
	upgrade_radar_e2e_check( 'exec=disabled max_execution_time=30 memory_limit=128M' === $limits, "Web requests run like on a locked-down host ($limits)" );

	$page = upgrade_radar_e2e_submit( upgrade_radar_e2e_login(), 'upgrade_radar_start', array( 'target' => '8.4' ) );
	$run  = upgrade_radar_e2e_run( $page, 100 );

	upgrade_radar_e2e_check( 'done' === ( $run['summary']['status'] ?? '' ), sprintf( 'The scan finishes, in %d requests and %.0f seconds', $run['requests'], $run['total'] ) );
	upgrade_radar_e2e_check( $run['slowest'] < 30, sprintf( 'Every request stays under the 30-second limit (slowest: %.1fs)', $run['slowest'] ) );

	$page     = upgrade_radar_e2e_request( '/wp-admin/tools.php?page=upgrade-radar' )['body'];
	$verdicts = upgrade_radar_e2e_verdicts( $page );
	upgrade_radar_e2e_check( count( $verdicts ) >= 37, sprintf( 'The results list every active plugin, the theme and its parent theme (%d rows)', count( $verdicts ) ) );

	$expected = array(
		'Example Legacy Forms'   => array( 'update_first', 'Version 2.0 is available' ),
		'Example New Sliders'    => array( 'update_first', "hasn't checked this version" ),
		'Example Legacy Gallery' => array( 'no_compatible_version', 'latest version' ),
		'Example Media Tools'    => array( 'ready', '4 possible false alarms' ),
		'Example Custom Tools'   => array( 'needs_developer', "can't check this for updates" ),
		'Example Single File'    => array( 'needs_developer', "can't check this for updates" ),
		'Example Deprecations'   => array( 'ready', 'deprecation warnings' ),
		'Example Big Data'       => array( 'ready', 'No problems found' ),
		'Example Modern'         => array( 'ready', 'No problems found' ),
		'Example Pack 13'        => array( 'ready', '' ),
		'Example Child'          => array( 'ready', 'deprecation warning' ),
		'Twenty Twenty-Five'     => array( 'ready', '' ),
	);
	foreach ( $expected as $name => list( $verdict, $text ) ) {
		$row = $verdicts[ $name ] ?? array(
			'verdict' => 'missing',
			'text'    => '',
		);
		upgrade_radar_e2e_check( $verdict === $row['verdict'] && false !== strpos( $row['text'], $text ), "$name: $verdict (got {$row['verdict']}: {$row['text']})" );
	}

	$ready = count( array_keys( array_column( $verdicts, 'verdict' ), 'ready', true ) );
	upgrade_radar_e2e_check( 32 === $ready, "32 items are ready (got $ready)" );
	upgrade_radar_e2e_check( false !== strpos( $page, '1 file not checked' ), 'The 2.9 MB data file is reported as not checked' );
	upgrade_radar_e2e_check( false !== strpos( $page, 'may include false alarms' ), 'The page says results may include false alarms' );

	preg_match( '#href="[^"]*(admin-post\.php\?[^"]*action=upgrade_radar_csv[^"]*)"#', $page, $match );
	$csv    = upgrade_radar_e2e_request( '/wp-admin/' . html_entity_decode( $match[1] ?? '', ENT_QUOTES ) );
	$stream = fopen( 'php://memory', 'r+' );
	fwrite( $stream, $csv['body'] );
	rewind( $stream );
	$rows   = array();
	$errors = 0;
	for ( $row = fgetcsv( $stream, 0, ',', '"', '' ); false !== $row; $row = fgetcsv( $stream, 0, ',', '"', '' ) ) {
		$rows[] = $row;
		if ( 'Example Legacy Forms' === ( $row[1] ?? '' ) && 'Error' === ( $row[7] ?? '' ) && '' !== $row[4] && 0 < (int) $row[5] ) {
			++$errors;
		}
	}
	upgrade_radar_e2e_check( 200 === $csv['status'] && 'Type' === ( $rows[0][0] ?? '' ), sprintf( 'The CSV downloads, with %d rows', count( $rows ) ) );
	upgrade_radar_e2e_check( 0 < $errors, sprintf( 'The CSV lists errors with file and line (%d for Legacy Forms)', $errors ) );
}

/**
 * Another plugin loads its own PHP_CodeSniffer on every request: the scan
 * explains, then pauses.
 */
function upgrade_radar_e2e_rival(): void {
	$page = upgrade_radar_e2e_submit( upgrade_radar_e2e_login(), 'upgrade_radar_start', array( 'target' => '8.3' ) );
	$run  = upgrade_radar_e2e_run( $page, 10 );

	upgrade_radar_e2e_check( 'paused' === ( $run['summary']['status'] ?? '' ), sprintf( 'The scan pauses after %d requests', $run['requests'] ) );
	upgrade_radar_e2e_check( false !== strpos( (string) ( $run['summary']['problem'] ?? '' ), 'Example Rival Scanner' ), 'The message names the plugin that loads PHP_CodeSniffer' );

	$page = upgrade_radar_e2e_request( '/wp-admin/tools.php?page=upgrade-radar' )['body'];
	upgrade_radar_e2e_check( false !== strpos( $page, 'deactivate that plugin while you scan' ) && false !== strpos( $page, 'upgrade_radar_resume' ), 'The paused page explains what to do and offers Resume' );
}

/**
 * Resumes the paused scan once the rival plugin is deactivated.
 */
function upgrade_radar_e2e_resume(): void {
	$page = upgrade_radar_e2e_submit( upgrade_radar_e2e_login(), 'upgrade_radar_resume' );
	$run  = upgrade_radar_e2e_run( $page, 100 );

	upgrade_radar_e2e_check( 'done' === ( $run['summary']['status'] ?? '' ), sprintf( 'After resuming, the scan finishes in %d requests', $run['requests'] ) );
}

/**
 * Cancelling while a batch runs, then starting a new scan: when the batch
 * ends, it mustn't save over the new scan.
 */
function upgrade_radar_e2e_cancel(): void {
	global $upgrade_radar_e2e;

	$page = upgrade_radar_e2e_submit( upgrade_radar_e2e_login(), 'upgrade_radar_start', array( 'target' => '8.2' ) );
	preg_match( '/window\.upgradeRadar = (\{.*?\});/', $page, $match );
	$settings = (array) json_decode( $match[1] ?? '{}', true );

	// A batch, as the page's script would send it, left running in the background.
	$multi = curl_multi_init();
	$batch = curl_init( $upgrade_radar_e2e['url'] . '/wp-admin/admin-ajax.php' );
	curl_setopt_array(
		$batch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIEFILE     => $upgrade_radar_e2e['cookies'],
			CURLOPT_POSTFIELDS     => http_build_query(
				array(
					'action' => 'upgrade_radar_step',
					'nonce'  => $settings['nonce'] ?? '',
				)
			),
		)
	);
	curl_multi_add_handle( $multi, $batch );
	$started = microtime( true );
	do {
		curl_multi_exec( $multi, $running );
		curl_multi_select( $multi, 0.1 );
	} while ( $running && microtime( true ) - $started < 3 );
	upgrade_radar_e2e_check( 0 < $running, 'A batch is running' );

	$page = upgrade_radar_e2e_submit( $page, 'upgrade_radar_cancel' );
	upgrade_radar_e2e_check( false === strpos( $page, 'id="upgrade-radar-progress"' ), 'The scan is cancelled' );
	$page = upgrade_radar_e2e_submit( $page, 'upgrade_radar_start', array( 'target' => '8.3' ) );
	do {
		curl_multi_exec( $multi, $running );
		curl_multi_select( $multi, 0.1 );
	} while ( $running );
	printf( "     The batch of the cancelled scan ended after %.1fs.\n", microtime( true ) - $started );

	$page = upgrade_radar_e2e_request( '/wp-admin/tools.php?page=upgrade-radar' )['body'];
	upgrade_radar_e2e_check( false !== strpos( $page, 'Scanning for PHP 8.3' ), 'The new scan, for PHP 8.3, is still the one running' );
	upgrade_radar_e2e_submit( $page, 'upgrade_radar_cancel' );
}

/**
 * The page closes after one batch. Visitors keep coming, and WP-Cron, which
 * their page loads start, finishes the scan.
 */
function upgrade_radar_e2e_background(): void {
	global $upgrade_radar_e2e;

	$page = upgrade_radar_e2e_submit( upgrade_radar_e2e_login(), 'upgrade_radar_start', array( 'target' => '8.5' ) );
	upgrade_radar_e2e_run( $page, 1 );

	echo "     The page closes. For a minute, WP-Cron leaves the scan to the page, in case it's still open.\n";
	sleep( 61 );

	$started = microtime( true );
	$text    = '';
	do {
		// A logged-out visitor.
		$curl = curl_init( $upgrade_radar_e2e['url'] . '/' );
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, true );
		curl_exec( $curl );
		sleep( 5 );

		$page = upgrade_radar_e2e_request( '/wp-admin/tools.php?page=upgrade-radar' )['body'];
		$done = false !== strpos( $page, 'Results for PHP 8.5' );
		if ( preg_match( '#upgrade-radar-progress-text[^>]*>(.*?)</p>#s', $page, $match ) && $match[1] !== $text ) {
			$text = $match[1];
			printf( "     %3ds: %s\n", microtime( true ) - $started, html_entity_decode( $text, ENT_QUOTES ) );
		}
	} while ( ! $done && microtime( true ) - $started < 600 );

	upgrade_radar_e2e_check( $done, sprintf( 'WP-Cron finishes the scan without the page, in %d seconds', microtime( true ) - $started ) );
}

$upgrade_radar_e2e_scenario = $argv[1] ?? 'full';
$upgrade_radar_e2e_function = 'upgrade_radar_e2e_' . $upgrade_radar_e2e_scenario;
if ( ! in_array( $upgrade_radar_e2e_scenario, array( 'full', 'rival', 'resume', 'cancel', 'background' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/e2e/scan.php full|rival|resume|cancel|background\n" );
	exit( 2 );
}

echo "== $upgrade_radar_e2e_scenario\n";
$upgrade_radar_e2e_function();
exit( 0 === $upgrade_radar_e2e['failures'] ? 0 : 1 );

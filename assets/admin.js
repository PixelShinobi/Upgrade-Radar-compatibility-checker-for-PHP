/**
 * Upgrade Radar: runs the scan one batch per request while this page is open.
 * When the page closes, WP-Cron carries on, and reopening it picks up here.
 */
( function () {
	'use strict';

	var config = window.upgradeRadar;
	var panel = document.getElementById( 'upgrade-radar-progress' );

	if ( ! config || ! panel || 'running' !== panel.getAttribute( 'data-status' ) ) {
		return;
	}

	var bar = panel.querySelector( 'progress' );
	var text = panel.querySelector( '.upgrade-radar-progress-text' );
	var problem = panel.querySelector( '.upgrade-radar-problem' );
	var problemText = problem.querySelector( 'p' );
	var failures = 0;

	function wait( milliseconds ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, milliseconds );
		} );
	}

	function showProblem( message ) {
		problemText.textContent = message;
		problem.hidden = '' === message;
	}

	function show( summary ) {
		bar.value = summary.percent;
		bar.textContent = summary.percent + '%';
		text.textContent = summary.text;
		showProblem( summary.problem );
	}

	function step() {
		var body = new FormData();
		body.append( 'action', 'upgrade_radar_step' );
		body.append( 'nonce', config.nonce );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( 'Unexpected response' );
				}
				return json.data;
			} );
	}

	function run() {
		return step().then(
			function ( summary ) {
				failures = 0;
				show( summary );

				// Finished, paused or cancelled: the reloaded page shows what's next.
				if ( 'running' !== summary.status ) {
					window.location.reload();
					return null;
				}

				// WP-Cron may hold the scan, or the scan may be waiting for another
				// plugin: check back later. Otherwise run the next batch right away.
				var delay = 250;
				if ( summary.problem ) {
					delay = 10000;
				} else if ( summary.busy ) {
					delay = 3000;
				}
				return wait( delay ).then( run );
			},
			function () {
				failures++;
				showProblem( config.connectionFailed );
				return wait( Math.min( 60000, 1000 * Math.pow( 2, failures ) ) ).then( run );
			}
		);
	}

	run();
}() );

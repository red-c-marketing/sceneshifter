/**
 * Sister Sync report page: while a scan runs, ask the server to process the
 * next batch every couple of seconds and update the progress bar. This keeps
 * the scan moving even where background jobs don't run. Reloads the page
 * when the scan ends so the new results show.
 */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const INTERVAL = 2000;

	document.addEventListener( 'DOMContentLoaded', () => {
		const box = document.getElementById( 'sister-sync-scan' );
		if ( ! box || box.dataset.status !== 'running' ) {
			return;
		}
		const bar = document.getElementById( 'sister-sync-scan-progress' );
		const text = document.getElementById( 'sister-sync-scan-text' );

		function update( status ) {
			if ( status.status !== 'running' ) {
				window.location.reload();
				return false;
			}
			if ( bar ) {
				bar.value = status.percent;
			}
			if ( text ) {
				text.textContent =
					status.phase === 'extras'
						? __(
								'Posts done. Scanning menus, widgets, ACF options and theme settings…',
								'sister-sync'
						  )
						: sprintf(
								/* translators: 1: posts scanned so far, 2: total posts. */
								__( 'Scanning posts: %1$d of %2$d', 'sister-sync' ),
								status.processed,
								status.total
						  );
			}
			return true;
		}

		function tick() {
			wp.apiFetch( { path: '/sister-sync/v1/scan/tick', method: 'POST' } )
				.then( ( status ) => {
					if ( update( status ) ) {
						window.setTimeout( tick, INTERVAL );
					}
				} )
				.catch( () => {
					// A failed request shouldn't stop the page; try again later.
					window.setTimeout( tick, INTERVAL * 3 );
				} );
		}

		window.setTimeout( tick, 500 );
	} );
} )();

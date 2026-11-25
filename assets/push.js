/**
 * Sceneshifter push: sends the previewed posts one request at a time and
 * shows what happened to each, so a long batch never hits a time limit and
 * one failure doesn't stop the rest.
 */
( function () {
	'use strict';

	function parse( value, fallback ) {
		try {
			return JSON.parse( value || '' ) || fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	function fill( text, a, b ) {
		return String( text || '' ).replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) );
	}

	function init( form ) {
		var queue = parse( form.getAttribute( 'data-queue' ), [] );
		var text = parse( form.getAttribute( 'data-text' ), {} );
		var url = form.getAttribute( 'data-ajax' );
		var list = form.querySelector( '[data-sceneshifter-push-progress]' );
		var summary = form.querySelector( '[data-sceneshifter-push-summary]' );
		var button = form.querySelector( '[data-sceneshifter-push-start]' );
		var running = false;

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( running ) {
				event.preventDefault();
				event.returnValue = text.leave || '';
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( running ) {
				return;
			}
			// Read the choices before disabling them: disabled fields aren't sent.
			var fields = new FormData( form );
			running = true;
			button.disabled = true;
			form.querySelectorAll( 'select, input[type="checkbox"]' ).forEach( function ( field ) {
				field.disabled = true;
			} );
			var rows = queue.map( function ( item ) {
				var row = document.createElement( 'li' );
				var title = document.createElement( 'strong' );
				title.textContent = item.title;
				var state = document.createElement( 'span' );
				state.className = 'sceneshifter-push-state';
				state.textContent = text.waiting || '';
				row.appendChild( title );
				row.appendChild( document.createTextNode( ' — ' ) );
				row.appendChild( state );
				list.appendChild( row );
				return { row: row, state: state };
			} );
			list.hidden = false;

			var pushed = 0;
			var failed = 0;

			function next( index ) {
				if ( index >= queue.length ) {
					running = false;
					summary.textContent = fill( text.done, pushed, failed );
					return;
				}
				var item = queue[ index ];
				var row = rows[ index ];
				row.row.className = 'is-running';
				row.state.textContent = text.pushing || '';

				var body = new URLSearchParams();
				fields.forEach( function ( value, key ) {
					body.append( key, value );
				} );
				body.set( 'post_id', String( item.id ) );
				body.set( 'expected', String( item.expected ) );

				window.fetch( url, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json().catch( function () {
							return { success: false, data: { html: ( text.failed || '' ) + ' (HTTP ' + response.status + ')' } };
						} );
					} )
					.catch( function () {
						return { success: false, data: { html: text.failed || '' } };
					} )
					.then( function ( reply ) {
						var ok = !! ( reply && reply.success && reply.data && reply.data.ok );
						if ( ok ) {
							pushed++;
						} else {
							failed++;
						}
						row.row.className = ok ? 'is-done' : 'is-failed';
						// Built and escaped by PushPage::resultHtml().
						row.state.innerHTML = reply && reply.data && reply.data.html ? reply.data.html : ( text.failed || '' );
						next( index + 1 );
					} );
			}

			next( 0 );
		} );
	}

	document.querySelectorAll( '[data-sceneshifter-push]' ).forEach( init );
}() );

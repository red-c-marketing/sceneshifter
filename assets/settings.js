/**
 * Sceneshifter settings: add and remove site rows, keep badge colours in step
 * with roles, stop sending while there are unsaved changes, hand a received
 * site list to the review screen, and the Pairing tab's small helpers.
 */
( function () {
	'use strict';

	function initSites() {
		var form = document.getElementById( 'sceneshifter-settings-form' );
		var rows = document.getElementById( 'sceneshifter-site-rows' );
		var template = document.getElementById( 'sceneshifter-site-template' );
		if ( ! form || ! rows || ! template ) {
			return;
		}

		var table = rows.closest( 'table' );
		var defaults = {};
		try {
			defaults = JSON.parse( table.getAttribute( 'data-default-colours' ) || '{}' );
		} catch ( e ) {}

		var next = rows.children.length;

		function addRow( values ) {
			var html = template.innerHTML.replace( /__INDEX__/g, String( next++ ) );
			var holder = document.createElement( 'tbody' );
			holder.innerHTML = html.trim();
			var row = holder.firstElementChild;
			if ( values && values.url ) {
				row.querySelector( 'input[name$="[url]"]' ).value = values.url;
			}
			if ( values && values.role ) {
				row.querySelector( '[data-sceneshifter-role]' ).value = values.role;
				row.querySelector( '[data-sceneshifter-colour]' ).value = defaults[ values.role ] || '#787c82';
			}
			rows.appendChild( row );
			row.querySelector( 'input[name$="[label]"]' ).focus();
			markDirty();
			return row;
		}

		document.getElementById( 'sceneshifter-add-row' ).addEventListener( 'click', function () {
			addRow();
		} );

		document.querySelectorAll( '[data-sceneshifter-add-site]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				addRow( { url: button.getAttribute( 'data-sceneshifter-add-site' ) } );
				button.disabled = true;
			} );
		} );

		rows.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-sceneshifter-remove]' );
			if ( ! remove ) {
				return;
			}
			remove.closest( 'tr' ).remove();
			if ( ! rows.children.length ) {
				addRow();
			}
			markDirty();
		} );

		// Follow the role's default colour unless someone picked their own.
		rows.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( '[data-sceneshifter-role]' ) ) {
				return;
			}
			var colour = event.target.closest( 'tr' ).querySelector( '[data-sceneshifter-colour]' );
			var previous = event.target.getAttribute( 'data-previous' ) || '';
			if ( colour.value === defaults[ previous ] && defaults[ event.target.value ] ) {
				colour.value = defaults[ event.target.value ];
			}
			event.target.setAttribute( 'data-previous', event.target.value );
		} );
		rows.addEventListener( 'focusin', function ( event ) {
			if ( event.target.matches( '[data-sceneshifter-role]' ) ) {
				event.target.setAttribute( 'data-previous', event.target.value );
			}
		} );

		var dirty = false;
		function markDirty() {
			if ( dirty ) {
				return;
			}
			dirty = true;
			var notice = document.querySelector( '.sceneshifter-unsaved' );
			if ( notice ) {
				notice.hidden = false;
			}
			document.querySelectorAll( '[data-sceneshifter-send]' ).forEach( function ( link ) {
				link.classList.add( 'disabled' );
				link.setAttribute( 'aria-disabled', 'true' );
			} );
		}
		form.addEventListener( 'input', markDirty );
		form.addEventListener( 'change', markDirty );
		form.addEventListener( 'submit', function () {
			dirty = false;
		} );

		document.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( '[data-sceneshifter-send]' );
			if ( link && dirty ) {
				event.preventDefault();
			}
		} );
		window.addEventListener( 'beforeunload', function ( event ) {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );
	}

	function initReceive() {
		var form = document.getElementById( 'sceneshifter-receive-form' );
		if ( ! form ) {
			return;
		}
		var bundle = window.location.hash.replace( /^#/, '' );
		if ( ! bundle ) {
			document.querySelector( '.sceneshifter-receiving' ).hidden = true;
			document.querySelector( '.sceneshifter-nothing' ).hidden = false;
			return;
		}
		// Drop the list from the address bar and history before posting it.
		window.history.replaceState( null, '', window.location.pathname + window.location.search );
		form.elements.bundle.value = bundle;
		form.submit();
	}

	// Pairing tab: confirm destructive buttons, copy the code, jump to the
	// paste box, and say when the other site has used the code.
	function initPairing() {
		// Delegated, so rows redrawn by watchRows() keep working.
		document.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			if ( form.matches && form.matches( 'form[data-sceneshifter-confirm]' ) && ! window.confirm( form.getAttribute( 'data-sceneshifter-confirm' ) ) ) {
				event.preventDefault();
			}
		} );

		document.querySelectorAll( '[data-sceneshifter-copy]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var field = document.getElementById( button.getAttribute( 'data-sceneshifter-copy' ) );
				if ( ! field ) {
					return;
				}
				var label = button.textContent;
				function done() {
					button.textContent = button.getAttribute( 'data-copied' ) || label;
					window.setTimeout( function () {
						button.textContent = label;
					}, 2000 );
				}
				field.select();
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( field.value ).then( done, function () {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} );

		document.addEventListener( 'click', function ( event ) {
			var field = document.getElementById( 'sceneshifter-code' );
			if ( field && event.target.closest && event.target.closest( '[data-sceneshifter-paste]' ) ) {
				window.setTimeout( function () {
					field.focus();
				}, 0 );
			}
		} );

		watchRows();

		var wait = document.querySelector( '[data-sceneshifter-pairing-wait]' );
		if ( ! wait || ! window.fetch ) {
			return;
		}
		var until = parseInt( wait.getAttribute( 'data-until' ), 10 ) * 1000;
		var query = new URLSearchParams( {
			action: wait.getAttribute( 'data-action' ),
			_ajax_nonce: wait.getAttribute( 'data-nonce' ),
			site: wait.getAttribute( 'data-site' ),
			since: wait.getAttribute( 'data-since' ),
		} );
		var timer = window.setInterval( function () {
			if ( Date.now() > until ) {
				window.clearInterval( timer );
				wait.textContent = wait.getAttribute( 'data-expired' );
				return;
			}
			window.fetch( wait.getAttribute( 'data-url' ) + '?' + query.toString(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success && json.data.paired ) {
						window.clearInterval( timer );
						wait.textContent = json.data.message;
						wait.classList.add( 'is-paired' );
					}
				} )
				.catch( function () {} );
		}, 3000 );
	}

	// Redraws the pairing rows when the other site pairs or unpairs, without
	// touching the paste form. Checks every 10 seconds while the tab is visible.
	function watchRows() {
		var body = document.querySelector( '[data-sceneshifter-pairing-rows]' );
		if ( ! body || ! window.fetch ) {
			return;
		}
		var busy = false;
		window.setInterval( function () {
			if ( busy || document.hidden || body.contains( document.activeElement ) ) {
				return;
			}
			busy = true;
			var query = new URLSearchParams( {
				action: body.getAttribute( 'data-action' ),
				_ajax_nonce: body.getAttribute( 'data-nonce' ),
				hash: body.getAttribute( 'data-hash' ),
			} );
			window.fetch( body.getAttribute( 'data-url' ) + '?' + query.toString(), { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( json && json.success && json.data.hash !== body.getAttribute( 'data-hash' ) && 'string' === typeof json.data.html ) {
						body.innerHTML = json.data.html;
						body.setAttribute( 'data-hash', json.data.hash );
					}
				} )
				.catch( function () {} )
				.then( function () {
					busy = false;
				} );
		}, 10000 );
	}

	// Link content tab: fetch the other site's list in steps, then link the
	// ticked matches in batches.
	function post( url, fields ) {
		var body = new URLSearchParams( fields );
		return window.fetch( url, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: { message: 'HTTP ' + response.status } };
				} );
			} );
	}

	function texts( element ) {
		try {
			return JSON.parse( element.getAttribute( 'data-text' ) || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function fill( text, a, b ) {
		return String( text || '' ).replace( '%1$d', String( a ) ).replace( '%2$d', String( b ) );
	}

	function initLink() {
		var fetchBox = document.querySelector( '[data-sceneshifter-fetch]' );
		if ( fetchBox ) {
			var start = fetchBox.querySelector( '[data-sceneshifter-fetch-start]' );
			var status = fetchBox.querySelector( '[data-sceneshifter-fetch-status]' );
			var step = function ( first ) {
				post( fetchBox.getAttribute( 'data-url' ), {
					action: fetchBox.getAttribute( 'data-action' ),
					_ajax_nonce: fetchBox.getAttribute( 'data-nonce' ),
					sister: fetchBox.getAttribute( 'data-sister' ),
					start: first ? '1' : '',
				} ).then( function ( json ) {
					var data = json && json.data ? json.data : {};
					status.textContent = data.message || '';
					if ( ! json || ! json.success ) {
						status.classList.add( 'sceneshifter-error-text' );
						start.disabled = false;
						return;
					}
					if ( data.done ) {
						window.location.href = fetchBox.getAttribute( 'data-done-url' );
						return;
					}
					step( false );
				}, function () {
					status.textContent = texts( fetchBox ).failed || '';
					start.disabled = false;
				} );
			};
			start.addEventListener( 'click', function () {
				start.disabled = true;
				status.classList.remove( 'sceneshifter-error-text' );
				step( true );
			} );
		}

		document.addEventListener( 'change', function ( event ) {
			var all = event.target.closest( '[data-sceneshifter-check-all]' );
			if ( ! all ) {
				return;
			}
			all.closest( 'table' ).querySelectorAll( 'tbody input[data-local]' ).forEach( function ( box ) {
				box.checked = all.checked;
			} );
		} );

		var form = document.querySelector( '[data-sceneshifter-link]' );
		if ( ! form ) {
			return;
		}
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var submit = form.querySelector( '[data-sceneshifter-link-submit]' );
			var status = form.querySelector( '[data-sceneshifter-link-status]' );
			var failures = form.querySelector( '[data-sceneshifter-link-failures]' );
			var pairs = [];
			form.querySelectorAll( 'input[data-local]:checked' ).forEach( function ( box ) {
				pairs.push( [ parseInt( box.getAttribute( 'data-local' ), 10 ), parseInt( box.getAttribute( 'data-remote' ), 10 ) ] );
			} );
			if ( ! pairs.length ) {
				return;
			}
			var text = texts( form );
			var size = parseInt( form.getAttribute( 'data-batch' ), 10 ) || 100;
			var total = pairs.length;
			var linked = 0;
			var failed = [];
			submit.disabled = true;
			failures.innerHTML = '';

			var finish = function ( error ) {
				status.textContent = fill( text.linked, linked, total ) + ( error ? ' ' + error : '' );
				if ( failed.length || error ) {
					var list = document.createElement( 'ul' );
					list.className = 'sceneshifter-link-failed';
					failed.forEach( function ( text ) {
						var item = document.createElement( 'li' );
						item.textContent = text;
						list.appendChild( item );
					} );
					failures.appendChild( list );
					var again = document.createElement( 'a' );
					again.href = window.location.href;
					again.className = 'button';
					again.textContent = text.refresh || '';
					failures.appendChild( again );
					return;
				}
				window.location.reload();
			};

			var next = function () {
				var batch = pairs.splice( 0, size );
				if ( ! batch.length ) {
					finish( '' );
					return;
				}
				status.textContent = fill( text.linking, linked, total );
				post( form.getAttribute( 'data-url' ), {
					action: form.getAttribute( 'data-action' ),
					_ajax_nonce: form.getAttribute( 'data-nonce' ),
					sister: form.getAttribute( 'data-sister' ),
					pairs: JSON.stringify( batch ),
				} ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						finish( json && json.data && json.data.message ? json.data.message : text.failed );
						return;
					}
					linked += json.data.linked;
					failed = failed.concat( json.data.failed || [] );
					next();
				}, function () {
					finish( text.failed );
				} );
			};
			next();
		} );
	}

	function init() {
		initSites();
		initReceive();
		initPairing();
		initLink();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

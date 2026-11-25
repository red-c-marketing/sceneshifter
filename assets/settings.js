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
		document.querySelectorAll( 'form[data-sceneshifter-confirm]' ).forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( form.getAttribute( 'data-sceneshifter-confirm' ) ) ) {
					event.preventDefault();
				}
			} );
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

		document.querySelectorAll( '[data-sceneshifter-paste]' ).forEach( function ( link ) {
			link.addEventListener( 'click', function () {
				var field = document.getElementById( 'sceneshifter-code' );
				if ( field ) {
					window.setTimeout( function () {
						field.focus();
					}, 0 );
				}
			} );
		} );

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

	function init() {
		initSites();
		initReceive();
		initPairing();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

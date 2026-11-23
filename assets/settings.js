/**
 * Sister Sync settings: add and remove site rows, keep badge colours in step
 * with roles, stop sending while there are unsaved changes, and hand a
 * received site list to the review screen.
 */
( function () {
	'use strict';

	function initSites() {
		var form = document.getElementById( 'sister-sync-settings-form' );
		var rows = document.getElementById( 'sister-sync-site-rows' );
		var template = document.getElementById( 'sister-sync-site-template' );
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
				row.querySelector( '[data-sister-sync-role]' ).value = values.role;
				row.querySelector( '[data-sister-sync-colour]' ).value = defaults[ values.role ] || '#787c82';
			}
			rows.appendChild( row );
			row.querySelector( 'input[name$="[label]"]' ).focus();
			markDirty();
			return row;
		}

		document.getElementById( 'sister-sync-add-row' ).addEventListener( 'click', function () {
			addRow();
		} );

		document.querySelectorAll( '[data-sister-sync-add-site]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				addRow( { url: button.getAttribute( 'data-sister-sync-add-site' ) } );
				button.disabled = true;
			} );
		} );

		rows.addEventListener( 'click', function ( event ) {
			var remove = event.target.closest( '[data-sister-sync-remove]' );
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
			if ( ! event.target.matches( '[data-sister-sync-role]' ) ) {
				return;
			}
			var colour = event.target.closest( 'tr' ).querySelector( '[data-sister-sync-colour]' );
			var previous = event.target.getAttribute( 'data-previous' ) || '';
			if ( colour.value === defaults[ previous ] && defaults[ event.target.value ] ) {
				colour.value = defaults[ event.target.value ];
			}
			event.target.setAttribute( 'data-previous', event.target.value );
		} );
		rows.addEventListener( 'focusin', function ( event ) {
			if ( event.target.matches( '[data-sister-sync-role]' ) ) {
				event.target.setAttribute( 'data-previous', event.target.value );
			}
		} );

		var dirty = false;
		function markDirty() {
			if ( dirty ) {
				return;
			}
			dirty = true;
			var notice = document.querySelector( '.sister-sync-unsaved' );
			if ( notice ) {
				notice.hidden = false;
			}
			document.querySelectorAll( '[data-sister-sync-send]' ).forEach( function ( link ) {
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
			var link = event.target.closest( '[data-sister-sync-send]' );
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
		var form = document.getElementById( 'sister-sync-receive-form' );
		if ( ! form ) {
			return;
		}
		var bundle = window.location.hash.replace( /^#/, '' );
		if ( ! bundle ) {
			document.querySelector( '.sister-sync-receiving' ).hidden = true;
			document.querySelector( '.sister-sync-nothing' ).hidden = false;
			return;
		}
		// Drop the list from the address bar and history before posting it.
		window.history.replaceState( null, '', window.location.pathname + window.location.search );
		form.elements.bundle.value = bundle;
		form.submit();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			initSites();
			initReceive();
		} );
	} else {
		initSites();
		initReceive();
	}
}() );

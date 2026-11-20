/**
 * Sister Sync Check for the Classic editor. No build step: plain script using
 * the wp.apiFetch and wp.i18n globals.
 */
( function () {
	'use strict';

	const { __, sprintf } = wp.i18n;

	function currentContent() {
		const editor = window.tinymce && window.tinymce.get( 'content' );
		if ( editor && ! editor.isHidden() ) {
			return editor.getContent();
		}
		const textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	function currentExcerpt() {
		const excerpt = document.getElementById( 'excerpt' );
		return excerpt ? excerpt.value : undefined;
	}

	function el( tag, attrs, text ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			node.setAttribute( key, value );
		} );
		if ( text !== undefined ) {
			node.textContent = text;
		}
		return node;
	}

	function render( results, data ) {
		results.replaceChildren();

		if ( ! data.findings.length ) {
			results.appendChild( el( 'p', {}, __( 'No sister links found.', 'sister-sync' ) ) );
			return;
		}

		results.appendChild(
			el(
				'p',
				{},
				sprintf(
					/* translators: 1: number of errors, 2: number of warnings. */
					__( '%1$d errors, %2$d warnings', 'sister-sync' ),
					data.counts.error,
					data.counts.warning
				)
			)
		);

		const list = el( 'ul', { class: 'sister-sync-findings' } );
		data.findings.forEach( ( finding ) => {
			const item = el( 'li', {
				style:
					'border-left:4px solid ' +
					( finding.severity === 'error' ? '#d63638' : '#dba617' ) +
					';padding-left:8px;margin-bottom:10px;',
			} );
			const badge = el(
				'span',
				{
					style:
						'background:' +
						finding.sister.colour +
						';color:#fff;padding:1px 6px;border-radius:3px;font-size:11px;',
				},
				finding.sister.label
			);
			item.appendChild( badge );
			item.appendChild(
				el( 'div', { style: 'word-break:break-all;margin:4px 0;' }, finding.url )
			);
			item.appendChild(
				el(
					'div',
					{ style: 'color:#646970;font-size:12px;' },
					finding.location +
						( finding.count > 1 ? ' × ' + finding.count : '' )
				)
			);
			list.appendChild( item );
		} );
		results.appendChild( list );
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		const root = document.getElementById( 'sister-sync-classic' );
		const button = document.getElementById( 'sister-sync-classic-check' );
		const results = document.getElementById( 'sister-sync-classic-results' );
		if ( ! root || ! button || ! results ) {
			return;
		}

		button.addEventListener( 'click', () => {
			button.disabled = true;
			results.textContent = __( 'Checking…', 'sister-sync' );

			wp.apiFetch( {
				path: '/sister-sync/v1/check',
				method: 'POST',
				data: {
					post_id: parseInt( root.dataset.postId, 10 ),
					content: currentContent(),
					excerpt: currentExcerpt(),
				},
			} )
				.then( ( data ) => render( results, data ) )
				.catch( ( error ) => {
					results.textContent =
						( error && error.message ) ||
						__( 'The check failed.', 'sister-sync' );
				} )
				.finally( () => {
					button.disabled = false;
				} );
		} );
	} );
} )();

/**
 * Bulk Editor screen polish: live title/description length badges
 * (traffic-light coloring, matching Admin\BulkEditor::length_badge()'s
 * server-rendered initial state) and pagination controls (per-page
 * selector, "go to page" jump) that navigate rather than submit a form,
 * so they need a little JS of their own. Vanilla JS, no dependencies;
 * loaded only on the SEO-WP > Bulk Editor screen.
 */
( function () {
	'use strict';

	var LENGTH_BANDS = {
		title: { min: 30, max: 60 },
		description: { min: 70, max: 155 },
	};

	function statusFor( field, length ) {
		var band = LENGTH_BANDS[ field ];
		if ( ! band ) {
			return 'good';
		}
		if ( 0 === length ) {
			return 'empty';
		}
		if ( length < band.min ) {
			return 'short';
		}
		if ( length > band.max ) {
			return 'long';
		}
		return 'good';
	}

	function updateBadge( input ) {
		var field = input.getAttribute( 'data-seo-wp-length-field' );
		var row = input.closest( '.seo-wp-bulk-row' );
		var badge = row && row.querySelector( '.seo-wp-bulk-length[data-seo-wp-length-field="' + field + '"]' );
		if ( ! badge ) {
			return;
		}

		var length = input.value.length;
		badge.textContent = String( length );
		badge.className = 'seo-wp-bulk-length is-' + statusFor( field, length );
	}

	document.addEventListener( 'input', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'seo-wp-bulk-length-input' ) ) {
			updateBadge( event.target );
		}
	} );

	function navigateWithParams( params ) {
		var url = new URL( window.location.href );
		Object.keys( params ).forEach( function ( key ) {
			url.searchParams.set( key, params[ key ] );
		} );
		window.location.href = url.toString();
	}

	function clampedPage( input ) {
		var max = parseInt( input.getAttribute( 'max' ), 10 ) || 1;
		var page = parseInt( input.value, 10 ) || 1;
		return Math.min( max, Math.max( 1, page ) );
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'seo-wp-bulk-per-page' ) ) {
			navigateWithParams( { per_page: event.target.value, paged: 1 } );
		}
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'seo-wp-bulk-page-input' ) && 'Enter' === event.key ) {
			event.preventDefault();
			navigateWithParams( { paged: clampedPage( event.target ) } );
		}
	} );

	// blur doesn't bubble, so this listener runs in the capture phase to
	// still catch it via one delegated handler rather than binding one
	// per page-jump input (there are two, top and bottom pager).
	document.addEventListener(
		'blur',
		function ( event ) {
			if ( ! event.target.classList || ! event.target.classList.contains( 'seo-wp-bulk-page-input' ) ) {
				return;
			}

			var page = clampedPage( event.target );
			var currentPaged = parseInt( new URL( window.location.href ).searchParams.get( 'paged' ), 10 ) || 1;
			if ( page !== currentPaged ) {
				navigateWithParams( { paged: page } );
			}
		},
		true
	);
} )();

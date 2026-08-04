/**
 * Drives the Duplicate Content scan's admin-ajax batch loop. Vanilla JS,
 * no dependencies; loaded only on the SEO-WP > Duplicate Content screen.
 */
( function () {
	'use strict';

	function post( body ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', window.seoWpDuplicates.action );
		params.append( 'nonce', window.seoWpDuplicates.nonce );

		return fetch( window.seoWpDuplicates.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function scan() {
		var button = document.querySelector( '.seo-wp-duplicate-scan-start' );
		var status = document.querySelector( '.seo-wp-duplicate-scan-status' );
		var i18n = window.seoWpDuplicates.i18n;

		button.disabled = true;
		status.textContent = i18n.scanning;

		function step( page ) {
			post( { page: page } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						status.textContent = i18n.error;
						button.disabled = false;
						return;
					}

					if ( response.data.done ) {
						status.textContent = i18n.done;
						window.location.reload();
						return;
					}

					status.textContent = i18n.scanning + ' (' + response.data.page + ')';
					step( response.data.page );
				} )
				.catch( function () {
					status.textContent = i18n.error;
					button.disabled = false;
				} );
		}

		step( 1 );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '.seo-wp-duplicate-scan-start' ) ) {
			event.preventDefault();
			scan();
		}
	} );
} )();

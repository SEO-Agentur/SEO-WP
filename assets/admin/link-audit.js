/**
 * Drives the Link Audit's two admin-ajax actions: the content-scan batch
 * loop, and an on-demand "check queued links now" click. Vanilla JS, no
 * dependencies; loaded only on the SEO-WP > Link Audit screen.
 */
( function () {
	'use strict';

	function post( body, action ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', action );
		params.append( 'nonce', window.seoWpLinkAudit.nonce );

		return fetch( window.seoWpLinkAudit.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function scan() {
		var scanButton = document.querySelector( '.seo-wp-link-audit-scan' );
		var status = document.querySelector( '.seo-wp-link-audit-status' );
		var i18n = window.seoWpLinkAudit.i18n;

		scanButton.disabled = true;
		status.textContent = i18n.scanning;

		function step( page ) {
			post( { page: page }, window.seoWpLinkAudit.scanAction )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						status.textContent = i18n.error;
						scanButton.disabled = false;
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
					scanButton.disabled = false;
				} );
		}

		step( 1 );
	}

	function checkNow() {
		var checkButton = document.querySelector( '.seo-wp-link-audit-check-now' );
		var status = document.querySelector( '.seo-wp-link-audit-status' );
		var i18n = window.seoWpLinkAudit.i18n;

		checkButton.disabled = true;
		status.textContent = i18n.checking;

		post( {}, window.seoWpLinkAudit.checkNowAction )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					status.textContent = i18n.error;
					checkButton.disabled = false;
					return;
				}

				window.location.reload();
			} )
			.catch( function () {
				status.textContent = i18n.error;
				checkButton.disabled = false;
			} );
	}

	function recheck( button ) {
		var url = button.getAttribute( 'data-url' );
		var row = button.closest( 'tr' );
		var i18n = window.seoWpLinkAudit.i18n;

		if ( ! url ) {
			return;
		}

		button.disabled = true;
		button.textContent = i18n.rechecking;

		post( { url: url }, window.seoWpLinkAudit.recheckAction )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					button.disabled = false;
					button.textContent = i18n.error;
					return;
				}

				if ( response.data.fixed && row ) {
					row.parentNode.removeChild( row );
					return;
				}

				window.location.reload();
			} )
			.catch( function () {
				button.disabled = false;
				button.textContent = i18n.error;
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '.seo-wp-link-audit-scan' ) ) {
			event.preventDefault();
			scan();
			return;
		}

		if ( event.target.closest( '.seo-wp-link-audit-check-now' ) ) {
			event.preventDefault();
			checkNow();
			return;
		}

		var recheckButton = event.target.closest( '.seo-wp-link-audit-recheck' );
		if ( recheckButton ) {
			event.preventDefault();
			recheck( recheckButton );
		}
	} );
} )();

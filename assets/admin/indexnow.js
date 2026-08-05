/**
 * Drives the Instant Indexing page's one-click bulk run: submits every
 * checked post type and taxonomy, one small AJAX batch at a time, so a
 * large site never risks a single request timing out. Vanilla JS, no
 * dependencies; loaded only on the SEO-WP > Instant Indexing screen.
 */
( function () {
	'use strict';

	function post( body ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', window.seoWpIndexNow.action );
		params.append( 'nonce', window.seoWpIndexNow.nonce );

		return fetch( window.seoWpIndexNow.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function buildQueue() {
		var queue = [];

		document.querySelectorAll( '.seo-wp-indexnow-bulk-post-type:checked' ).forEach( function ( checkbox ) {
			queue.push( { kind: 'post_type', name: checkbox.value } );
		} );
		document.querySelectorAll( '.seo-wp-indexnow-bulk-taxonomy:checked' ).forEach( function ( checkbox ) {
			queue.push( { kind: 'taxonomy', name: checkbox.value } );
		} );

		return queue;
	}

	function appendLogLine( logEl, text ) {
		var line = document.createElement( 'div' );
		line.className = 'seo-wp-indexnow-log-entry';
		line.textContent = text;
		logEl.appendChild( line );
		logEl.scrollTop = logEl.scrollHeight;
	}

	function runBulk( button ) {
		var i18n = window.seoWpIndexNow.i18n;
		var queue = buildQueue();

		if ( 0 === queue.length ) {
			window.alert( i18n.nothingPicked ); // eslint-disable-line no-alert
			return;
		}

		var wrap = document.querySelector( '.seo-wp-indexnow-progress' );
		var status = wrap.querySelector( '.seo-wp-indexnow-status' );
		var logEl = wrap.querySelector( '.seo-wp-indexnow-log' );

		button.disabled = true;
		wrap.hidden = false;
		logEl.textContent = '';
		status.textContent = i18n.starting;

		var totalSubmitted = 0;

		function nextItem() {
			if ( 0 === queue.length ) {
				status.textContent = i18n.done + ' (' + totalSubmitted + ')';
				button.disabled = false;
				return;
			}

			var item = queue.shift();
			step( item, 0 );
		}

		function step( item, offset ) {
			post( { kind: item.kind, name: item.name, offset: offset } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						appendLogLine( logEl, item.name + ': ' + ( ( response && response.data && response.data.message ) || i18n.error ) );
						status.textContent = i18n.error;
						button.disabled = false;
						return;
					}

					var data = response.data;
					totalSubmitted += data.submitted || 0;

					if ( ! data.ok ) {
						appendLogLine( logEl, item.name + ': ' + ( data.error || i18n.error ) );
					}

					status.textContent = i18n.progress
						.replace( '%1$d', totalSubmitted )
						.replace( '%2$s', item.name );

					if ( data.has_more ) {
						step( item, data.next_offset );
						return;
					}

					appendLogLine( logEl, item.name + ': ' + data.total + ' URLs' );
					nextItem();
				} )
				.catch( function () {
					status.textContent = i18n.error;
					button.disabled = false;
				} );
		}

		nextItem();
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.seo-wp-indexnow-bulk-start' );
		if ( button ) {
			event.preventDefault();
			runBulk( button );
		}
	} );
} )();

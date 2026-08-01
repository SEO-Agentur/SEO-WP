/**
 * Drives the Import Wizard's admin-ajax batch loop (posts, then terms,
 * then Yoast author settings). Vanilla JS, no dependencies; loaded only
 * on the SEO-WP > Import screen.
 */
( function () {
	'use strict';

	function post( body ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', window.seoWpImport.action );
		params.append( 'nonce', window.seoWpImport.nonce );

		return fetch( window.seoWpImport.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function describeEntry( entry ) {
		if ( 'redirects-skipped' === entry.type ) {
			return entry.note;
		}

		var fields = ( entry.fields || [] ).join( ', ' );
		var suffix = entry.dry_run ? ' (preview)' : '';

		return entry.type + ' #' + entry.id + suffix + ( fields ? ': ' + fields : ' — no changes' );
	}

	function appendLog( container, entries ) {
		( entries || [] ).forEach( function ( entry ) {
			var line = document.createElement( 'div' );
			line.className = 'seo-wp-import-log-entry';
			line.textContent = describeEntry( entry );
			container.appendChild( line );
		} );
		container.scrollTop = container.scrollHeight;
	}

	function run( sourceEl, dryRun ) {
		var source = sourceEl.getAttribute( 'data-seo-wp-source' );
		var progressWrap = sourceEl.querySelector( '.seo-wp-import-progress' );
		var status = progressWrap.querySelector( '.seo-wp-import-status' );
		var logEl = sourceEl.querySelector( '.seo-wp-import-log' );
		var buttons = sourceEl.querySelectorAll( '.seo-wp-import-run' );

		buttons.forEach( function ( button ) {
			button.disabled = true;
		} );
		progressWrap.hidden = false;
		logEl.textContent = '';
		status.textContent = window.seoWpImport.i18n.processing;

		function step( stage, page ) {
			post( { source: source, stage: stage, page: page, dry_run: dryRun ? 1 : 0 } )
				.then( function ( response ) {
					if ( ! response || ! response.success ) {
						status.textContent = window.seoWpImport.i18n.error;
						buttons.forEach( function ( button ) {
							button.disabled = false;
						} );
						return;
					}

					var data = response.data;
					appendLog( logEl, data.entries );

					if ( data.done ) {
						status.textContent = window.seoWpImport.i18n.done;
						buttons.forEach( function ( button ) {
							button.disabled = false;
						} );
						return;
					}

					status.textContent = data.stage + ' – ' + data.page;
					step( data.stage, data.page );
				} )
				.catch( function () {
					status.textContent = window.seoWpImport.i18n.error;
					buttons.forEach( function ( button ) {
						button.disabled = false;
					} );
				} );
		}

		step( 'posts', 1 );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.seo-wp-import-run' );
		if ( ! button ) {
			return;
		}

		event.preventDefault();
		var sourceEl = button.closest( '[data-seo-wp-source]' );
		if ( sourceEl ) {
			run( sourceEl, '1' === button.getAttribute( 'data-seo-wp-dry-run' ) );
		}
	} );
} )();

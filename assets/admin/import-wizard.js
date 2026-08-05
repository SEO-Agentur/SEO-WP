/**
 * Drives the Import Wizard's admin-ajax batch loop (posts, then terms,
 * then Yoast author settings). Vanilla JS, no dependencies; loaded only
 * on the SEO-WP > Import screen.
 */
( function () {
	'use strict';

	function post( body, action ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', action || window.seoWpImport.action );
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

	function deactivateSource( sourceEl ) {
		var i18n = window.seoWpImport.i18n;
		if ( ! window.confirm( i18n.confirmDeactivate ) ) { // eslint-disable-line no-alert
			return;
		}

		var source = sourceEl.getAttribute( 'data-seo-wp-source' );
		var button = sourceEl.querySelector( '.seo-wp-import-deactivate' );
		var status = sourceEl.querySelector( '.seo-wp-import-deactivate-status' );

		button.disabled = true;
		status.textContent = i18n.deactivating;

		post( { source: source }, window.seoWpImport.deactivateAction )
			.then( function ( response ) {
				if ( response && response.success ) {
					status.textContent = i18n.deactivated;
					button.remove();
					return;
				}

				status.textContent = ( response && response.data && response.data.message ) || i18n.deactivateFailed;
				button.disabled = false;
			} )
			.catch( function () {
				status.textContent = i18n.deactivateFailed;
				button.disabled = false;
			} );
	}

	function describeEntry( entry ) {
		if ( 'redirects-skipped' === entry.type ) {
			return entry.note;
		}

		var fields = ( entry.fields || [] ).join( ', ' );
		var skipped = ( entry.skipped || [] ).join( ', ' );
		var suffix = entry.dry_run ? ' (preview)' : '';

		var detail = fields ? ': ' + fields : '';
		if ( skipped ) {
			// Present in the source plugin but SEO-WP already had a value
			// there (from a previous import or a manual edit) — never
			// overwritten, so this is expected, not a failure.
			detail += ( fields ? '; ' : ': ' ) + 'already set, left alone: ' + skipped;
		}
		if ( ! detail ) {
			detail = ' — no changes';
		}

		// The "settings" entry (Batch::run_settings()) is a single global
		// import step, not tied to one object, so it carries no id.
		if ( 'settings' === entry.type ) {
			return 'settings' + suffix + detail;
		}

		return entry.type + ' #' + entry.id + suffix + detail;
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

						if ( ! dryRun ) {
							var deactivateWrap = sourceEl.querySelector( '.seo-wp-import-deactivate-wrap' );
							if ( deactivateWrap ) {
								deactivateWrap.hidden = false;
							}
						}

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
		var runButton = event.target.closest( '.seo-wp-import-run' );
		if ( runButton ) {
			event.preventDefault();
			var runSourceEl = runButton.closest( '[data-seo-wp-source]' );
			if ( runSourceEl ) {
				run( runSourceEl, '1' === runButton.getAttribute( 'data-seo-wp-dry-run' ) );
			}
			return;
		}

		var deactivateButton = event.target.closest( '.seo-wp-import-deactivate' );
		if ( deactivateButton ) {
			event.preventDefault();
			var deactivateSourceEl = deactivateButton.closest( '[data-seo-wp-source]' );
			if ( deactivateSourceEl ) {
				deactivateSource( deactivateSourceEl );
			}
		}
	} );
} )();

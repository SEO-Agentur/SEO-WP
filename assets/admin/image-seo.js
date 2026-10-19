/**
 * Image SEO screen: inline alt-text editing, AI suggestions and the
 * batched content scan. Vanilla JS, no dependencies.
 */
( function () {
	'use strict';

	var cfg = window.seoWpImageSeo;
	if ( ! cfg ) {
		return;
	}

	function post( action, data ) {
		var params = new URLSearchParams();
		params.append( 'action', action );
		params.append( 'nonce', cfg.nonce );
		Object.keys( data ).forEach( function ( key ) {
			params.append( key, data[ key ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( json ) {
			if ( ! json || ! json.success ) {
				throw new Error( ( json && json.data && json.data.message ) || cfg.i18n.error );
			}
			return json.data;
		} );
	}

	function setStatus( row, text, isError ) {
		var status = row.querySelector( '.seo-wp-alt-status' );
		status.textContent = text;
		status.style.color = isError ? '#b32d2e' : '';
	}

	function provider() {
		var keys = Object.keys( cfg.providers || {} );
		return keys.length ? keys[ 0 ] : '';
	}

	function save( row ) {
		var input = row.querySelector( '.seo-wp-alt-input' );
		var decorative = row.querySelector( '.seo-wp-alt-decorative' ).checked;
		setStatus( row, cfg.i18n.saving, false );

		return post( 'seo_wp_image_alt_save', {
			id: row.dataset.id,
			alt: input.value,
			decorative: decorative ? '1' : '',
		} ).then( function () {
			setStatus( row, cfg.i18n.saved, false );
		} ).catch( function ( error ) {
			setStatus( row, error.message, true );
		} );
	}

	function suggest( row ) {
		setStatus( row, cfg.i18n.asking, false );

		return post( 'seo_wp_image_alt_suggest', { id: row.dataset.id, provider: provider() } ).then( function ( data ) {
			row.querySelector( '.seo-wp-alt-input' ).value = data.alt;
			row.querySelector( '.seo-wp-alt-decorative' ).checked = false;
			setStatus( row, '', false );
		} ).catch( function ( error ) {
			setStatus( row, error.message, true );
		} );
	}

	function suggestAll( button ) {
		var status = document.querySelector( '.seo-wp-image-bulk-status' );
		var rows = Array.prototype.slice.call( document.querySelectorAll( '.seo-wp-image-table tbody tr' ) );
		button.disabled = true;
		status.textContent = cfg.i18n.suggestAll;

		rows.reduce( function ( chain, row ) {
			return chain.then( function () {
				return suggest( row );
			} );
		}, Promise.resolve() ).then( function () {
			button.disabled = false;
			status.textContent = '';
		} );
	}

	function scan( button ) {
		var status = document.querySelector( '.seo-wp-image-scan-status' );
		button.disabled = true;
		status.textContent = cfg.i18n.scanning;

		function step( offset ) {
			return post( 'seo_wp_image_content_scan', { offset: offset } ).then( function ( data ) {
				if ( data.done ) {
					button.disabled = false;
					status.textContent = cfg.i18n.scanDone;
					return;
				}
				status.textContent = cfg.i18n.progress.replace( '%1$s', data.next ).replace( '%2$s', data.total );
				return step( data.next );
			} );
		}

		step( 0 ).catch( function ( error ) {
			button.disabled = false;
			status.textContent = error.message;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var target = event.target;
		var row = target.closest( '.seo-wp-image-table tr[data-id]' );

		if ( row && target.closest( '.seo-wp-alt-save' ) ) {
			event.preventDefault();
			save( row );
		} else if ( row && target.closest( '.seo-wp-alt-suggest' ) ) {
			event.preventDefault();
			suggest( row );
		} else if ( row && target.closest( '.seo-wp-alt-title' ) ) {
			event.preventDefault();
			row.querySelector( '.seo-wp-alt-input' ).value = row.dataset.fallback || '';
			row.querySelector( '.seo-wp-alt-decorative' ).checked = false;
		} else if ( target.closest( '.seo-wp-image-suggest-all' ) ) {
			event.preventDefault();
			suggestAll( target.closest( '.seo-wp-image-suggest-all' ) );
		} else if ( target.closest( '.seo-wp-image-scan' ) ) {
			event.preventDefault();
			scan( target.closest( '.seo-wp-image-scan' ) );
		}
	} );
} )();

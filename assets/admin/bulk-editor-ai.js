/**
 * AI "Suggest" and "Translate" actions in the Bulk Editor table. Vanilla
 * JS, no dependencies; loaded only on the SEO-WP > Bulk Editor screen.
 *
 * Suggestions are written straight into the row's own Title/Description
 * inputs — nothing is saved until the page's normal "Save changes" submit
 * runs, so every suggestion stays fully editable/discardable first.
 * Translations target *other* posts (the WPML translations of this row),
 * so they write immediately via their own request instead.
 */
( function () {
	'use strict';

	function post( body, action ) {
		var params = new URLSearchParams();

		Object.keys( body ).forEach( function ( key ) {
			params.append( key, body[ key ] );
		} );
		params.append( 'action', action );
		params.append( 'nonce', window.seoWpBulkAi.nonce );

		return fetch( window.seoWpBulkAi.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function populateProviderSelects() {
		var providers = window.seoWpBulkAi.providers || {};
		var providerKeys = Object.keys( providers );

		document.querySelectorAll( '.seo-wp-bulk-ai-provider' ).forEach( function ( select ) {
			providerKeys.forEach( function ( key ) {
				var option = document.createElement( 'option' );
				option.value = key;
				option.textContent = providers[ key ];
				select.appendChild( option );
			} );

			if ( 0 === providerKeys.length ) {
				select.disabled = true;
			}
		} );
	}

	function rowFields( row ) {
		return {
			title: row.querySelector( '.seo-wp-bulk-ai-title' ),
			description: row.querySelector( '.seo-wp-bulk-ai-description' ),
			provider: row.querySelector( '.seo-wp-bulk-ai-provider' ),
			status: row.querySelector( '.seo-wp-bulk-ai-status' ),
		};
	}

	function suggestForRow( row ) {
		var i18n = window.seoWpBulkAi.i18n;
		var fields = rowFields( row );
		var postId = row.getAttribute( 'data-seo-wp-post-id' );

		if ( 0 === Object.keys( window.seoWpBulkAi.providers || {} ).length ) {
			fields.status.textContent = i18n.noProviders;
			return Promise.resolve();
		}

		fields.status.textContent = i18n.suggesting;

		return post( { post_id: postId, provider: fields.provider.value }, window.seoWpBulkAi.suggestAction )
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					fields.status.textContent = ( response && response.data && response.data.message ) || i18n.error;
					return;
				}

				fields.title.value = response.data.title;
				fields.description.value = response.data.description;
				// Dispatched so bulk-editor-ux.js's length badges pick up
				// the new values — setting .value directly doesn't fire
				// its own 'input' listener.
				fields.title.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				fields.description.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				fields.status.textContent = i18n.done;
			} )
			.catch( function () {
				fields.status.textContent = i18n.error;
			} );
	}

	function translateForRow( row ) {
		var i18n = window.seoWpBulkAi.i18n;
		var fields = rowFields( row );
		var postId = row.getAttribute( 'data-seo-wp-post-id' );

		if ( ! window.seoWpBulkAi.wpmlActive ) {
			fields.status.textContent = i18n.noWpml;
			return;
		}

		if ( ! window.seoWpBulkAi.deeplConfigured ) {
			fields.status.textContent = i18n.noDeepl;
			return;
		}

		fields.status.textContent = i18n.translating;

		post(
			{ post_id: postId, title: fields.title.value, description: fields.description.value },
			window.seoWpBulkAi.translateAction
		)
			.then( function ( response ) {
				if ( ! response || ! response.success ) {
					fields.status.textContent = ( response && response.data && response.data.message ) || i18n.error;
					return;
				}

				var count = ( response.data.translated || [] ).length;
				var errorCount = ( response.data.errors || [] ).length;
				fields.status.textContent = i18n.done + ' (' + count + ( errorCount ? ', ' + errorCount + ' errors' : '' ) + ')';
			} )
			.catch( function () {
				fields.status.textContent = i18n.error;
			} );
	}

	function suggestForEmptyFieldsOnPage() {
		var status = document.querySelector( '.seo-wp-bulk-ai-suggest-empty-status' );
		var rows = Array.prototype.filter.call( document.querySelectorAll( '.seo-wp-bulk-row' ), function ( row ) {
			var fields = rowFields( row );
			return '' === fields.title.value.trim() || '' === fields.description.value.trim();
		} );

		if ( 0 === rows.length ) {
			status.textContent = window.seoWpBulkAi.i18n.done;
			return;
		}

		var index = 0;
		function next() {
			if ( index >= rows.length ) {
				status.textContent = window.seoWpBulkAi.i18n.done;
				return;
			}

			status.textContent = ( index + 1 ) + ' / ' + rows.length;
			suggestForRow( rows[ index ] ).then( function () {
				index += 1;
				next();
			} );
		}

		next();
	}

	document.addEventListener( 'DOMContentLoaded', populateProviderSelects );

	document.addEventListener( 'click', function ( event ) {
		var suggestButton = event.target.closest( '.seo-wp-bulk-ai-suggest' );
		if ( suggestButton ) {
			event.preventDefault();
			var suggestRow = suggestButton.closest( '.seo-wp-bulk-row' );
			if ( suggestRow ) {
				suggestForRow( suggestRow );
			}
			return;
		}

		var translateButton = event.target.closest( '.seo-wp-bulk-ai-translate' );
		if ( translateButton ) {
			event.preventDefault();
			var translateRow = translateButton.closest( '.seo-wp-bulk-row' );
			if ( translateRow ) {
				translateForRow( translateRow );
			}
			return;
		}

		if ( event.target.closest( '.seo-wp-bulk-ai-suggest-empty' ) ) {
			event.preventDefault();
			suggestForEmptyFieldsOnPage();
		}
	} );
} )();

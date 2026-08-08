/**
 * Drives the Performance tab's "Scan homepage for suggestions" button.
 * Vanilla JS, no dependencies; loaded only on SEO-WP's own admin pages.
 *
 * Fetches suggested resource-hint lines from the server, lists them as
 * checkboxes (all checked by default), and an "Add checked" button
 * appends the selected ones to the Resource Hints textarea — nothing is
 * written until the admin also clicks the page's own "Save Settings".
 */
( function () {
	'use strict';

	function scan() {
		var button = document.querySelector( '.seo-wp-scan-resource-hints' );
		var status = document.querySelector( '.seo-wp-resource-hint-scan-status' );
		var list = document.querySelector( '.seo-wp-resource-hint-suggestions' );
		var i18n = window.seoWpResourceHintScanner.i18n;

		button.disabled = true;
		status.textContent = i18n.scanning;
		list.innerHTML = '';

		var params = new URLSearchParams();
		params.append( 'action', 'seo_wp_scan_resource_hints' );
		params.append( 'nonce', window.seoWpResourceHintScanner.nonce );

		fetch( window.seoWpResourceHintScanner.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: params.toString(),
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				button.disabled = false;

				if ( ! response || ! response.success ) {
					status.textContent = ( response && response.data && response.data.message ) || i18n.error;
					return;
				}

				var lines = response.data.lines || [];
				status.textContent = '';

				if ( 0 === lines.length ) {
					status.textContent = i18n.none;
					return;
				}

				renderSuggestions( lines );
			} )
			.catch( function () {
				button.disabled = false;
				status.textContent = i18n.error;
			} );
	}

	function renderSuggestions( lines ) {
		var list = document.querySelector( '.seo-wp-resource-hint-suggestions' );

		lines.forEach( function ( line ) {
			var item = document.createElement( 'li' );
			var label = document.createElement( 'label' );
			var checkbox = document.createElement( 'input' );

			checkbox.type = 'checkbox';
			checkbox.checked = true;
			checkbox.value = line;

			label.appendChild( checkbox );
			label.appendChild( document.createTextNode( ' ' + line ) );
			item.appendChild( label );
			list.appendChild( item );
		} );

		var addButton = document.createElement( 'button' );
		addButton.type = 'button';
		addButton.className = 'button button-secondary seo-wp-add-resource-hints';
		addButton.textContent = 'Add checked to Resource Hints';

		var addItem = document.createElement( 'li' );
		addItem.appendChild( addButton );
		list.appendChild( addItem );
	}

	function addCheckedSuggestions() {
		var textarea = document.getElementById( 'resource_hints' );
		if ( ! textarea ) {
			return;
		}

		var checked = document.querySelectorAll( '.seo-wp-resource-hint-suggestions input[type="checkbox"]:checked' );
		var existingLines = textarea.value.split( '\n' ).map( function ( line ) {
			return line.trim();
		} );

		var added = [];
		checked.forEach( function ( checkbox ) {
			if ( -1 === existingLines.indexOf( checkbox.value ) ) {
				added.push( checkbox.value );
			}
		} );

		if ( 0 === added.length ) {
			return;
		}

		var current = textarea.value.replace( /\s+$/, '' );
		textarea.value = ( '' === current ? '' : current + '\n' ) + added.join( '\n' ) + '\n';
	}

	document.addEventListener( 'click', function ( event ) {
		if ( event.target.closest( '.seo-wp-scan-resource-hints' ) ) {
			event.preventDefault();
			scan();
			return;
		}

		if ( event.target.closest( '.seo-wp-add-resource-hints' ) ) {
			event.preventDefault();
			addCheckedSuggestions();
		}
	} );
} )();

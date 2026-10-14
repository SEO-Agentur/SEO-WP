/**
 * Drives the Performance tab's "Scan site for suggestions" button.
 * Vanilla JS, no dependencies; loaded only on this plugin's own admin pages.
 *
 * The server scans the home page and a few sample pages and returns, per
 * external host, what is loaded from it. This lists those hosts in a table
 * with a checkbox per suggested hint line (hosts that already have a hint
 * start unchecked); "Add checked" appends the selected lines to the
 * Resource Hints textarea — nothing is saved until the admin also clicks
 * the page's own Save button.
 */
( function () {
	'use strict';

	function el( tag, text, className ) {
		var node = document.createElement( tag );
		if ( text ) {
			node.textContent = text;
		}
		if ( className ) {
			node.className = className;
		}
		return node;
	}

	function scan() {
		var button = document.querySelector( '.seo-wp-scan-resource-hints' );
		var status = document.querySelector( '.seo-wp-resource-hint-scan-status' );
		var result = document.querySelector( '.seo-wp-resource-hint-suggestions' );
		var i18n = window.seoWpResourceHintScanner.i18n;

		button.disabled = true;
		status.textContent = i18n.scanning;
		result.innerHTML = '';

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

				var rows = response.data.rows || [];
				status.textContent = '';

				if ( 0 === rows.length ) {
					status.textContent = i18n.none;
					return;
				}

				status.textContent = i18n.scanned.replace( '%d', response.data.pages || 1 );
				renderRows( rows );
			} )
			.catch( function () {
				button.disabled = false;
				status.textContent = i18n.error;
			} );
	}

	function describeTypes( types ) {
		var parts = [];
		Object.keys( types ).forEach( function ( type ) {
			parts.push( types[ type ] + ' × ' + type );
		} );
		return parts.join( ', ' );
	}

	function renderRows( rows ) {
		var i18n = window.seoWpResourceHintScanner.i18n;
		var result = document.querySelector( '.seo-wp-resource-hint-suggestions' );

		var table = el( 'table', '', 'widefat striped' );
		var head = el( 'tr' );
		[ i18n.colHost, i18n.colLoads, i18n.colPages, i18n.colHint ].forEach( function ( label ) {
			head.appendChild( el( 'th', label ) );
		} );
		var thead = el( 'thead' );
		thead.appendChild( head );
		table.appendChild( thead );

		var tbody = el( 'tbody' );
		rows.forEach( function ( row ) {
			var tr = el( 'tr' );
			var hostCell = el( 'td' );
			hostCell.appendChild( el( 'code', row.host ) );
			if ( row.hinted ) {
				hostCell.appendChild( el( 'div', i18n.alreadyHinted, 'description' ) );
			}
			tr.appendChild( hostCell );
			tr.appendChild( el( 'td', row.total + ' — ' + describeTypes( row.types ) ) );
			tr.appendChild( el( 'td', String( row.pages ) ) );

			var hintCell = el( 'td' );
			row.suggestions.forEach( function ( line ) {
				var label = el( 'label' );
				var checkbox = document.createElement( 'input' );
				checkbox.type = 'checkbox';
				checkbox.checked = ! row.hinted;
				checkbox.value = line;
				label.appendChild( checkbox );
				label.appendChild( el( 'code', ' ' + line ) );
				hintCell.appendChild( label );
				hintCell.appendChild( document.createElement( 'br' ) );
			} );
			tr.appendChild( hintCell );
			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		result.appendChild( table );

		var addButton = el( 'button', i18n.addChecked, 'button button-secondary seo-wp-add-resource-hints' );
		addButton.type = 'button';
		var p = el( 'p' );
		p.appendChild( addButton );
		result.appendChild( p );
		result.appendChild( el( 'p', i18n.advice, 'description' ) );
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

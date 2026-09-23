/**
 * Tab switching, the FAQ repeater and the live title/description length
 * badges for the SEO-WP metabox. Vanilla JS, no dependencies; loaded only
 * on post-edit and term-edit screens.
 */
( function () {
	'use strict';

	/**
	 * Classifies a length exactly like SeoWp\Support\LengthAdvisor::status()
	 * — same bands, localized from PHP so the two never drift apart.
	 */
	function lengthStatus( field, length ) {
		var bands = ( window.seoWpMetabox && window.seoWpMetabox.lengthBands ) || {};
		var band = bands[ field ];

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

	function updateLengthBadges() {
		document.querySelectorAll( '.seo-wp-length-badge' ).forEach( function ( badge ) {
			var field = badge.getAttribute( 'data-seo-wp-length-field' );
			var input = document.getElementById( 'seo-wp-_seo_wp_' + field );
			if ( ! input ) {
				return;
			}

			var length = input.value.length;
			badge.textContent = String( length );
			badge.className = 'seo-wp-length-badge is-' + lengthStatus( field, length );
		} );
	}

	function activateTab( metabox, tabName ) {
		metabox.querySelectorAll( '.seo-wp-tab-button' ).forEach( function ( button ) {
			button.classList.toggle( 'seo-wp-tab-active', button.getAttribute( 'data-seo-wp-tab' ) === tabName );
		} );
		metabox.querySelectorAll( '.seo-wp-tab-panel' ).forEach( function ( panel ) {
			panel.classList.toggle( 'seo-wp-tab-active', panel.getAttribute( 'data-seo-wp-panel' ) === tabName );
		} );
	}

	function addFaqRow( container ) {
		var i18n = ( window.seoWpMetabox && window.seoWpMetabox.i18n ) || {};
		var row = document.createElement( 'div' );

		row.className = 'seo-wp-faq-row';

		var question = document.createElement( 'input' );
		question.type = 'text';
		question.name = 'seo_wp_faq_question[]';
		question.className = 'widefat';
		question.placeholder = i18n.question || 'Question';

		var answer = document.createElement( 'textarea' );
		answer.name = 'seo_wp_faq_answer[]';
		answer.rows = 2;
		answer.className = 'widefat';
		answer.placeholder = i18n.answer || 'Answer';

		var remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link seo-wp-faq-remove';
		remove.textContent = i18n.remove || 'Remove';

		row.appendChild( question );
		row.appendChild( answer );
		row.appendChild( remove );
		container.appendChild( row );
	}

	/**
	 * Builds a custom, JS-driven suggestion dropdown for the schema type
	 * field, replacing the native <input list> + <datalist> pairing —
	 * that combination's actual popup behavior is inconsistent enough
	 * across browsers (Safari/WebKit in particular) to be unreliable.
	 * Reads its option list from the still-server-rendered <datalist>
	 * element, which is otherwise inert once the input's own `list`
	 * attribute is removed (see Admin\Metabox::field_schema_type()).
	 */
	function initSchemaTypeAutocomplete() {
		var input = document.getElementById( 'seo-wp-_seo_wp_schema_type' );
		var datalist = document.getElementById( 'seo-wp-schema-type-suggestions' );
		if ( ! input || ! datalist ) {
			return;
		}

		var options = Array.prototype.map.call( datalist.options, function ( option ) {
			return option.value;
		} );

		var dropdown = document.createElement( 'ul' );
		dropdown.className = 'seo-wp-schema-suggestions';
		dropdown.hidden = true;
		input.insertAdjacentElement( 'afterend', dropdown );

		var activeIndex = -1;

		function setActive( index ) {
			var items = dropdown.children;
			if ( items[ activeIndex ] ) {
				items[ activeIndex ].classList.remove( 'is-active' );
			}
			activeIndex = index;
			if ( items[ activeIndex ] ) {
				items[ activeIndex ].classList.add( 'is-active' );
				items[ activeIndex ].scrollIntoView( { block: 'nearest' } );
			}
		}

		function close() {
			dropdown.hidden = true;
			dropdown.innerHTML = '';
			activeIndex = -1;
		}

		function open( query ) {
			var needle = query.toLowerCase();
			var matches = options.filter( function ( option ) {
				return option.toLowerCase().indexOf( needle ) !== -1;
			} );

			if ( 0 === matches.length ) {
				close();
				return;
			}

			dropdown.innerHTML = '';
			matches.forEach( function ( match ) {
				var item = document.createElement( 'li' );
				item.textContent = match;
				dropdown.appendChild( item );
			} );
			dropdown.hidden = false;
			activeIndex = -1;
		}

		function choose( value ) {
			input.value = value;
			close();
			input.dispatchEvent( new Event( 'input' ) );
		}

		input.addEventListener( 'input', function () {
			open( input.value );
		} );

		input.addEventListener( 'focus', function () {
			open( input.value );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( dropdown.hidden ) {
				return;
			}

			var count = dropdown.children.length;

			if ( 'ArrowDown' === event.key ) {
				event.preventDefault();
				setActive( ( activeIndex + 1 ) % count );
			} else if ( 'ArrowUp' === event.key ) {
				event.preventDefault();
				setActive( ( activeIndex - 1 + count ) % count );
			} else if ( 'Enter' === event.key && activeIndex > -1 ) {
				event.preventDefault();
				choose( dropdown.children[ activeIndex ].textContent );
			} else if ( 'Escape' === event.key ) {
				close();
			}
		} );

		// mousedown (not click) fires before the input's own blur handler
		// would otherwise close the dropdown first.
		dropdown.addEventListener( 'mousedown', function ( event ) {
			var item = event.target.closest( 'li' );
			if ( item ) {
				event.preventDefault();
				choose( item.textContent );
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( event.target !== input && ! dropdown.contains( event.target ) ) {
				close();
			}
		} );
	}

	function fieldValue( id ) {
		var el = document.getElementById( id );
		return el ? el.value.trim() : '';
	}

	function truncate( text, max ) {
		if ( text.length <= max ) {
			return text;
		}
		return text.slice( 0, max - 1 ).trim() + '…';
	}

	function updateSerpPreview() {
		var box = document.querySelector( '.seo-wp-serp-preview' );
		if ( ! box ) {
			return;
		}

		var prefs = ( window.seoWpMetabox && window.seoWpMetabox.preview ) || {};
		var titleField = fieldValue( 'seo-wp-_seo_wp_title' ) || fieldValue( 'title' );
		var descriptionField = fieldValue( 'seo-wp-_seo_wp_description' ) || fieldValue( 'excerpt' );

		var title = titleField
			? titleField + ' ' + ( prefs.separator || '-' ) + ' ' + ( prefs.siteName || '' )
			: ( prefs.siteName || '' );

		box.querySelector( '[data-seo-wp-serp-url]' ).textContent = prefs.domain || '';
		box.querySelector( '[data-seo-wp-serp-title]' ).textContent = truncate( title, 60 );
		box.querySelector( '[data-seo-wp-serp-description]' ).textContent = truncate( descriptionField, 155 );
	}

	function updateSocialPreview() {
		var box = document.querySelector( '.seo-wp-social-preview' );
		if ( ! box ) {
			return;
		}

		var i18n = ( window.seoWpMetabox && window.seoWpMetabox.i18n ) || {};
		var prefs = ( window.seoWpMetabox && window.seoWpMetabox.preview ) || {};

		var title = fieldValue( 'seo-wp-_seo_wp_og_title' ) || fieldValue( 'seo-wp-_seo_wp_title' ) || fieldValue( 'title' );
		var description = fieldValue( 'seo-wp-_seo_wp_og_description' ) || fieldValue( 'seo-wp-_seo_wp_description' ) || fieldValue( 'excerpt' );
		var image = fieldValue( 'seo-wp-_seo_wp_og_image' );

		var imageBox = box.querySelector( '[data-seo-wp-social-image]' );
		if ( image ) {
			imageBox.style.backgroundImage = 'url(' + image.replace( /["'\\]/g, '' ) + ')';
			imageBox.textContent = '';
		} else {
			imageBox.style.backgroundImage = '';
			imageBox.textContent = i18n.noImage || 'No image set';
		}

		box.querySelector( '[data-seo-wp-social-domain]' ).textContent = prefs.domain || '';
		box.querySelector( '[data-seo-wp-social-title]' ).textContent = truncate( title, 90 );
		box.querySelector( '[data-seo-wp-social-description]' ).textContent = truncate( description, 200 );
	}

	function updatePreviews() {
		updateSerpPreview();
		updateSocialPreview();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! document.querySelector( '.seo-wp-metabox' ) ) {
			return;
		}

		updatePreviews();
		updateLengthBadges();
		initSchemaTypeAutocomplete();

		[
			'seo-wp-_seo_wp_title',
			'seo-wp-_seo_wp_description',
			'seo-wp-_seo_wp_og_title',
			'seo-wp-_seo_wp_og_description',
			'seo-wp-_seo_wp_og_image',
			'title',
			'excerpt',
		].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', updatePreviews );
			}
		} );

		[ 'seo-wp-_seo_wp_title', 'seo-wp-_seo_wp_description' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'input', updateLengthBadges );
			}
		} );
	} );

	document.addEventListener( 'click', function ( event ) {
		var tabButton = event.target.closest( '.seo-wp-tab-button' );
		if ( tabButton ) {
			event.preventDefault();
			var metabox = tabButton.closest( '.seo-wp-metabox' );
			if ( metabox ) {
				activateTab( metabox, tabButton.getAttribute( 'data-seo-wp-tab' ) );
			}
			return;
		}

		var addButton = event.target.closest( '.seo-wp-faq-add' );
		if ( addButton ) {
			event.preventDefault();
			var rows = addButton.parentElement.querySelector( '.seo-wp-faq-rows' );
			if ( rows ) {
				addFaqRow( rows );
			}
			return;
		}

		var removeButton = event.target.closest( '.seo-wp-faq-remove' );
		if ( removeButton ) {
			event.preventDefault();
			var row = removeButton.closest( '.seo-wp-faq-row' );
			if ( row ) {
				row.remove();
			}
		}
	} );
} )();

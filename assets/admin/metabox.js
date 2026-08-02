/**
 * Tab switching and the FAQ repeater for the SEO-WP metabox. Vanilla JS,
 * no dependencies; loaded only on post-edit and term-edit screens.
 */
( function () {
	'use strict';

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

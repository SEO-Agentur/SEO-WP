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

/**
 * Block editor sidebar: a persistently visible panel mirroring the
 * classic SEO-WP metabox's Title/Meta description fields (SERP preview +
 * live length traffic light), so the two most frequently edited SEO
 * fields don't require scrolling down to the metabox. This is a UI
 * mirror only — the classic metabox's own <input>/<textarea> elements
 * stay the single source of truth that actually gets submitted on save;
 * this panel just keeps them in sync in both directions. Deliberately no
 * register_post_meta()/REST persistence of its own: that would be a
 * second save path for the same fields, and the classic metabox's
 * save_post handler already has no reliable way to tell "field
 * deliberately cleared" from "field not part of this request" — a second
 * writer only invites the two to disagree. Vanilla wp.element (no JSX,
 * no build step); loaded only on post-edit screens.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.editPost || ! wp.element || ! wp.components || ! wp.data || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useSelect = wp.data.useSelect;
	var __ = wp.i18n.__;

	var TITLE_ID = 'seo-wp-_seo_wp_title';
	var DESCRIPTION_ID = 'seo-wp-_seo_wp_description';

	function truncate( text, max ) {
		if ( text.length <= max ) {
			return text;
		}
		return text.slice( 0, max - 1 ).trim() + '…';
	}

	/**
	 * Mirrors SeoWp\Support\LengthAdvisor::status() — same bands,
	 * localized from PHP (window.seoWpMetabox.lengthBands) so the two
	 * never drift apart.
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

	function readClassicValue( id ) {
		var input = document.getElementById( id );
		return input ? input.value : null;
	}

	function writeClassicValue( id, value ) {
		var input = document.getElementById( id );
		if ( ! input || input.value === value ) {
			return;
		}
		input.value = value;
		// Dispatched so metabox.js's own preview/length-badge listeners on
		// the classic field also update — keeps both surfaces in sync
		// regardless of which one the user is actually typing into.
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function LengthBadge( props ) {
		var status = lengthStatus( props.field, props.value.length );
		return el(
			'span',
			{ className: 'seo-wp-length-badge is-' + status, style: { float: 'right' } },
			String( props.value.length )
		);
	}

	function SeoSidebar() {
		var classicAvailable = null !== readClassicValue( TITLE_ID ) || null !== readClassicValue( DESCRIPTION_ID );

		var titleState = useState( readClassicValue( TITLE_ID ) || '' );
		var title = titleState[ 0 ];
		var setTitle = titleState[ 1 ];

		var descriptionState = useState( readClassicValue( DESCRIPTION_ID ) || '' );
		var description = descriptionState[ 0 ];
		var setDescription = descriptionState[ 1 ];

		var editedTitle = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
		}, array() );

		var editedExcerpt = useSelect( function ( select ) {
			return select( 'core/editor' ).getEditedPostAttribute( 'excerpt' ) || '';
		}, array() );

		useEffect( function () {
			function onTitleInput( event ) {
				setTitle( event.target.value );
			}
			function onDescriptionInput( event ) {
				setDescription( event.target.value );
			}

			var titleEl = document.getElementById( TITLE_ID );
			var descriptionEl = document.getElementById( DESCRIPTION_ID );

			if ( titleEl ) {
				titleEl.addEventListener( 'input', onTitleInput );
			}
			if ( descriptionEl ) {
				descriptionEl.addEventListener( 'input', onDescriptionInput );
			}

			return function () {
				if ( titleEl ) {
					titleEl.removeEventListener( 'input', onTitleInput );
				}
				if ( descriptionEl ) {
					descriptionEl.removeEventListener( 'input', onDescriptionInput );
				}
			};
		}, array() );

		if ( ! classicAvailable ) {
			return el(
				wp.components.PanelBody,
				{ title: __( 'SEO-WP', 'seo-wp' ) },
				el( 'p', {}, __( 'The SEO-WP fields aren’t available on this screen.', 'seo-wp' ) )
			);
		}

		var prefs = ( window.seoWpMetabox && window.seoWpMetabox.preview ) || {};
		var effectiveTitle = title || editedTitle;
		var previewTitle = effectiveTitle
			? effectiveTitle + ' ' + ( prefs.separator || '-' ) + ' ' + ( prefs.siteName || '' )
			: ( prefs.siteName || '' );
		var previewDescription = description || editedExcerpt;

		return el(
			wp.components.PanelBody,
			{ title: __( 'Search & Social', 'seo-wp' ), initialOpen: true },
			el(
				'div',
				{ className: 'seo-wp-serp-box', style: { marginBottom: '16px' } },
				el( 'div', { className: 'seo-wp-serp-url' }, prefs.domain || '' ),
				el( 'div', { className: 'seo-wp-serp-title' }, truncate( previewTitle, 60 ) ),
				el( 'div', { className: 'seo-wp-serp-description' }, truncate( previewDescription, 155 ) )
			),
			el(
				'div',
				{ style: { marginBottom: '16px' } },
				el( LengthBadge, { field: 'title', value: title } ),
				el( wp.components.TextControl, {
					label: __( 'SEO title', 'seo-wp' ),
					help: __( 'Falls back to the post title when left empty.', 'seo-wp' ),
					value: title,
					onChange: function ( value ) {
						setTitle( value );
						writeClassicValue( TITLE_ID, value );
					},
				} )
			),
			el(
				'div',
				{},
				el( LengthBadge, { field: 'description', value: description } ),
				el( wp.components.TextareaControl, {
					label: __( 'Meta description', 'seo-wp' ),
					value: description,
					rows: 4,
					onChange: function ( value ) {
						setDescription( value );
						writeClassicValue( DESCRIPTION_ID, value );
					},
				} )
			),
			el(
				'p',
				{ className: 'description' },
				__( 'Open Graph, Schema, canonical and robots settings are still in the SEO-WP box below the editor.', 'seo-wp' )
			)
		);
	}

	wp.plugins.registerPlugin( 'seo-wp-sidebar', {
		icon: 'search',
		render: function () {
			return el(
				wp.editPost.PluginSidebar,
				{
					name: 'seo-wp-sidebar',
					title: __( 'SEO-WP', 'seo-wp' ),
				},
				el( SeoSidebar )
			);
		},
	} );
} )( window.wp );

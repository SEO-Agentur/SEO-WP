/**
 * Block editor panel "Link suggestions": proposes pages to link to and,
 * where the text already holds a fitting phrase, links that phrase with one
 * click. Vanilla wp.element, no build step.
 */
( function ( wp ) {
	'use strict';

	var cfg = window.seoWpLinkSuggestions;
	if ( ! cfg || ! wp || ! wp.plugins || ! wp.editPost || ! wp.editPost.PluginDocumentSettingPanel ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var __ = wp.i18n.__;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Panel = wp.editPost.PluginDocumentSettingPanel;

	function escapeRegExp( text ) {
		return text.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	function escapeAttr( text ) {
		return text.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	/** Wraps the first occurrence of the phrase in the text parts of an HTML string (never inside a link or a tag). */
	function linkInHtml( html, anchor, url ) {
		var parts = html.split( /(<a\b[^>]*>[\s\S]*?<\/a>|<[^>]+>)/i );
		var re = new RegExp( '(?<![\\p{L}\\p{N}])' + escapeRegExp( anchor ) + '(?![\\p{L}\\p{N}])', 'iu' );

		for ( var i = 0; i < parts.length; i += 2 ) {
			if ( re.test( parts[ i ] ) ) {
				parts[ i ] = parts[ i ].replace( re, function ( match ) {
					return '<a href="' + escapeAttr( url ) + '">' + match + '</a>';
				} );
				return parts.join( '' );
			}
		}
		return null;
	}

	function flatten( blocks ) {
		var out = [];
		blocks.forEach( function ( block ) {
			out.push( block );
			if ( block.innerBlocks && block.innerBlocks.length ) {
				out = out.concat( flatten( block.innerBlocks ) );
			}
		} );
		return out;
	}

	function applyLink( anchor, url ) {
		var blocks = flatten( wp.data.select( 'core/block-editor' ).getBlocks() );

		for ( var i = 0; i < blocks.length; i++ ) {
			var content = blocks[ i ].attributes && blocks[ i ].attributes.content;
			if ( 'string' !== typeof content ) {
				continue;
			}
			var updated = linkInHtml( content, anchor, url );
			if ( updated ) {
				wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( blocks[ i ].clientId, { content: updated } );
				return true;
			}
		}
		return false;
	}

	function copy( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text );
		}
	}

	function Suggestions() {
		var state = useState( null );
		var items = state[ 0 ];
		var setItems = state[ 1 ];
		var busyState = useState( false );
		var busy = busyState[ 0 ];
		var setBusy = busyState[ 1 ];
		var msgState = useState( '' );
		var message = msgState[ 0 ];
		var setMessage = msgState[ 1 ];

		function find() {
			var editor = wp.data.select( 'core/editor' );
			var params = new URLSearchParams();
			params.append( 'action', 'seo_wp_link_suggestions' );
			params.append( 'nonce', cfg.nonce );
			params.append( 'post_id', editor.getCurrentPostId() );
			params.append( 'title', editor.getEditedPostAttribute( 'title' ) || '' );
			params.append( 'content', editor.getEditedPostContent() || '' );

			setBusy( true );
			setMessage( '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: params.toString(),
			} ).then( function ( response ) {
				return response.json();
			} ).then( function ( json ) {
				setBusy( false );
				if ( ! json || ! json.success ) {
					setMessage( ( json && json.data && json.data.message ) || __( 'Something went wrong.', 'seooptimierung-toolkit' ) );
					return;
				}
				setItems( json.data.suggestions );
			} ).catch( function () {
				setBusy( false );
				setMessage( __( 'Something went wrong.', 'seooptimierung-toolkit' ) );
			} );
		}

		function remove( id ) {
			setItems( items.filter( function ( item ) {
				return item.id !== id;
			} ) );
		}

		function link( item ) {
			if ( applyLink( item.anchor, item.url ) ) {
				remove( item.id );
			} else {
				setMessage( __( 'The phrase was not found in a text block — copy the link instead.', 'seooptimierung-toolkit' ) );
			}
		}

		return el(
			'div',
			null,
			el( 'p', { className: 'description' }, __( 'Pages on this site that fit the text. Where a phrase in your text matches, one click turns it into a link.', 'seooptimierung-toolkit' ) ),
			el( Button, { variant: 'secondary', onClick: find, disabled: busy }, busy ? __( 'Searching…', 'seooptimierung-toolkit' ) : __( 'Find link suggestions', 'seooptimierung-toolkit' ) ),
			busy ? el( Spinner ) : null,
			message ? el( 'p', { style: { color: '#b32d2e' } }, message ) : null,
			null !== items && 0 === items.length && ! busy ? el( 'p', null, __( 'No further suggestions.', 'seooptimierung-toolkit' ) ) : null,
			( items || [] ).map( function ( item ) {
				return el(
					'div',
					{ key: item.id, style: { borderTop: '1px solid #ddd', padding: '8px 0' } },
					el( 'a', { href: item.url, target: '_blank', rel: 'noopener noreferrer' }, item.title ),
					item.matched && item.matched.length ? el( 'div', { className: 'description' }, __( 'Shared:', 'seooptimierung-toolkit' ) + ' ' + item.matched.join( ', ' ) ) : null,
					item.anchor ? el( 'div', { className: 'description' }, __( 'Link the phrase:', 'seooptimierung-toolkit' ) + ' “' + item.anchor + '”' ) : null,
					el(
						'div',
						{ style: { display: 'flex', gap: '6px', marginTop: '6px' } },
						item.anchor ? el( Button, { variant: 'primary', size: 'small', onClick: function () { link( item ); } }, __( 'Link it', 'seooptimierung-toolkit' ) ) : null,
						el( Button, { variant: 'secondary', size: 'small', onClick: function () { copy( item.url ); } }, __( 'Copy link', 'seooptimierung-toolkit' ) )
					)
				);
			} )
		);
	}

	wp.plugins.registerPlugin( 'seooptimierung-link-suggestions', {
		render: function () {
			return el( Panel, { name: 'seooptimierung-link-suggestions', title: __( 'Link suggestions', 'seooptimierung-toolkit' ), className: 'seooptimierung-link-suggestions' }, el( Suggestions ) );
		},
	} );
} )( window.wp );

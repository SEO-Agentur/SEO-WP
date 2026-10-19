/**
 * Editor side of the Seooptimierung Toolkit blocks. No build step: plain
 * wp.element calls.
 *
 * - Breadcrumbs is rendered on the server; the editor shows a live preview.
 * - The structured-data content blocks (FAQ, how-to, recipe, event, job
 *   posting, course, software, video) all share one editor: a form drawn
 *   from the field definitions PHP passes in as window.seoWpBlocks.types.
 *   Field types: text, textarea, number, date, datetime, url, select,
 *   toggle, image, list, qa.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender = wp.serverSideRender;
	var blockEditor = wp.blockEditor;
	var components = wp.components;
	var useBlockProps = blockEditor.useBlockProps;
	var cfg = window.seoWpBlocks || { types: {}, i18n: {} };

	// ---- Breadcrumbs ------------------------------------------------------

	registerBlockType( 'seooptimierung/breadcrumbs', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Breadcrumbs', 'seooptimierung-toolkit' ) },
						el( components.ToggleControl, {
							label: __( 'Link the current page', 'seooptimierung-toolkit' ),
							checked: !! props.attributes.linkCurrent,
							onChange: function ( value ) {
								props.setAttributes( { linkCurrent: value } );
							},
						} )
					)
				),
				el( ServerSideRender, {
					block: 'seooptimierung/breadcrumbs',
					attributes: props.attributes,
					EmptyResponsePlaceholder: function () {
						return el( 'p', { style: { opacity: 0.6 } }, __( 'Breadcrumbs appear here on pages that have a trail (not on the home page).', 'seooptimierung-toolkit' ) );
					},
				} )
			);
		},
		save: function () {
			return null;
		},
	} );

	// ---- Content blocks: field editors ----------------------------------------

	function listEditor( field, value, onChange ) {
		var items = Array.isArray( value ) ? value : [];

		function update( index, text ) {
			var next = items.slice();
			next[ index ] = text;
			onChange( next );
		}

		return el(
			'div',
			{ className: 'seooptimierung-field seooptimierung-field--list' },
			el( 'strong', null, field.label ),
			field.help ? el( 'p', { className: 'description' }, field.help ) : null,
			items.map( function ( text, index ) {
				return el(
					'div',
					{ key: index, style: { display: 'flex', gap: '6px', alignItems: 'flex-start' } },
					el( components.TextareaControl, {
						value: text,
						rows: 2,
						style: { flex: 1 },
						onChange: function ( v ) {
							update( index, v );
						},
					} ),
					el( components.Button, {
						isDestructive: true,
						variant: 'tertiary',
						onClick: function () {
							onChange( items.filter( function ( _, i ) {
								return i !== index;
							} ) );
						},
					}, cfg.i18n.remove )
				);
			} ),
			el( components.Button, {
				variant: 'secondary',
				onClick: function () {
					onChange( items.concat( [ '' ] ) );
				},
			}, cfg.i18n.addItem )
		);
	}

	function qaEditor( field, value, onChange ) {
		var items = Array.isArray( value ) ? value : [];

		function update( index, key, text ) {
			var next = items.map( function ( item, i ) {
				if ( i !== index ) {
					return item;
				}
				var copy = Object.assign( {}, item );
				copy[ key ] = text;
				return copy;
			} );
			onChange( next );
		}

		return el(
			'div',
			{ className: 'seooptimierung-field seooptimierung-field--qa' },
			items.map( function ( item, index ) {
				return el(
					'div',
					{ key: index, style: { border: '1px solid #ddd', padding: '8px', marginBottom: '8px' } },
					el( components.TextControl, {
						label: cfg.i18n.question,
						value: item.question || '',
						onChange: function ( v ) {
							update( index, 'question', v );
						},
					} ),
					el( components.TextareaControl, {
						label: cfg.i18n.answer,
						value: item.answer || '',
						rows: 3,
						onChange: function ( v ) {
							update( index, 'answer', v );
						},
					} ),
					el( components.Button, {
						isDestructive: true,
						variant: 'tertiary',
						onClick: function () {
							onChange( items.filter( function ( _, i ) {
								return i !== index;
							} ) );
						},
					}, cfg.i18n.remove )
				);
			} ),
			el( components.Button, {
				variant: 'secondary',
				onClick: function () {
					onChange( items.concat( [ { question: '', answer: '' } ] ) );
				},
			}, cfg.i18n.addQuestion )
		);
	}

	function imageEditor( field, value, onChange ) {
		return el(
			'div',
			{ className: 'seooptimierung-field seooptimierung-field--image' },
			el( components.TextControl, {
				label: field.label,
				value: value || '',
				type: 'url',
				onChange: onChange,
			} ),
			el(
				blockEditor.MediaUploadCheck,
				null,
				el( blockEditor.MediaUpload, {
					allowedTypes: [ 'image' ],
					onSelect: function ( media ) {
						onChange( media && media.url ? media.url : '' );
					},
					render: function ( open ) {
						return el( components.Button, { variant: 'secondary', onClick: open.open }, cfg.i18n.chooseImage );
					},
				} )
			)
		);
	}

	function fieldEditor( field, attributes, setAttributes ) {
		var value = attributes[ field.key ];

		function set( v ) {
			var patch = {};
			patch[ field.key ] = v;
			setAttributes( patch );
		}

		switch ( field.type ) {
			case 'textarea':
				return el( components.TextareaControl, { key: field.key, label: field.label, help: field.help, value: value || '', rows: 3, onChange: set } );
			case 'number':
				return el( components.TextControl, { key: field.key, label: field.label, help: field.help, type: 'number', value: value || '', onChange: set } );
			case 'date':
				return el( components.TextControl, { key: field.key, label: field.label, help: field.help, type: 'date', value: value || '', onChange: set } );
			case 'datetime':
				return el( components.TextControl, { key: field.key, label: field.label, help: field.help, type: 'datetime-local', value: value || '', onChange: set } );
			case 'url':
				return el( components.TextControl, { key: field.key, label: field.label, help: field.help, type: 'url', value: value || '', onChange: set } );
			case 'toggle':
				return el( components.ToggleControl, { key: field.key, label: field.label, help: field.help, checked: !! value, onChange: set } );
			case 'select':
				return el( components.SelectControl, {
					key: field.key,
					label: field.label,
					value: value || '',
					options: [ { label: '—', value: '' } ].concat(
						Object.keys( field.options || {} ).map( function ( key ) {
							return { label: field.options[ key ], value: key };
						} )
					),
					onChange: set,
				} );
			case 'image':
				return el( Fragment, { key: field.key }, imageEditor( field, value, set ) );
			case 'list':
				return el( Fragment, { key: field.key }, listEditor( field, value, set ) );
			case 'qa':
				return el( Fragment, { key: field.key }, qaEditor( field, value, set ) );
			default:
				return el( components.TextControl, { key: field.key, label: field.label, help: field.help, placeholder: field.placeholder || '', value: value || '', onChange: set } );
		}
	}

	Object.keys( cfg.types ).forEach( function ( slug ) {
		var definition = cfg.types[ slug ];

		registerBlockType( definition.name, {
			edit: function ( props ) {
				return el(
					'div',
					useBlockProps( { className: 'seooptimierung-block-editor' } ),
					el( 'p', { style: { margin: '0 0 8px', fontWeight: 600 } }, definition.title ),
					el( 'p', { className: 'description', style: { marginTop: 0 } }, cfg.i18n.note ),
					definition.fields.map( function ( field ) {
						return fieldEditor( field, props.attributes, props.setAttributes );
					} )
				);
			},
			save: function () {
				return null;
			},
		} );
	} );
} )( window.wp );

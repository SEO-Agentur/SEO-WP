<?php
/**
 * Front-end blocks and shortcodes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Frontend;

use Seooptimierung\Toolkit\Schema\ContentTypes;
use Seooptimierung\Toolkit\Support\Breadcrumbs;

/**
 * Registers every block of the plugin (breadcrumbs and the structured-data
 * content blocks) and the breadcrumbs shortcode. All blocks render on the
 * server, so there is nothing to build or bundle: the editor script is plain
 * JavaScript that draws a form from the block definitions passed to it.
 */
final class Blocks {

	/**
	 * Block category slug.
	 */
	private const CATEGORY = 'seooptimierung';

	/**
	 * Registers the hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'add_category' ) );
		add_shortcode( 'seooptimierung_breadcrumbs', array( $this, 'render_shortcode' ) );
		add_shortcode( 'seooptimierung_schema', array( $this, 'render_schema_shortcode' ) );
	}

	/**
	 * Adds the "Seooptimierung Toolkit" block category.
	 *
	 * @param array<int,array<string,string>> $categories Existing categories.
	 * @return array<int,array<string,string>>
	 */
	public function add_category( $categories ): array {
		$categories   = is_array( $categories ) ? $categories : array();
		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Seooptimierung Toolkit', 'seooptimierung-toolkit' ),
		);

		return $categories;
	}

	/**
	 * Registers the editor script and all blocks.
	 */
	public function register_blocks(): void {
		wp_register_script(
			'seooptimierung-blocks',
			plugins_url( 'assets/blocks/blocks.js', \Seooptimierung\Toolkit\FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-server-side-render', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			\Seooptimierung\Toolkit\VERSION,
			true
		);

		wp_set_script_translations( 'seooptimierung-blocks', 'seooptimierung-toolkit', \Seooptimierung\Toolkit\DIR . '/languages' );

		wp_localize_script(
			'seooptimierung-blocks',
			'seoWpBlocks',
			array(
				'types' => ContentTypes::definitions_for_editor(),
				'i18n'  => array(
					'addItem'     => __( 'Add item', 'seooptimierung-toolkit' ),
					'addQuestion' => __( 'Add question', 'seooptimierung-toolkit' ),
					'remove'      => __( 'Remove', 'seooptimierung-toolkit' ),
					'question'    => __( 'Question', 'seooptimierung-toolkit' ),
					'answer'      => __( 'Answer', 'seooptimierung-toolkit' ),
					'chooseImage' => __( 'Choose image', 'seooptimierung-toolkit' ),
					'note'        => __( 'Shown on the page and added to the structured data (JSON-LD).', 'seooptimierung-toolkit' ),
				),
			)
		);

		register_block_type(
			'seooptimierung/breadcrumbs',
			array(
				'api_version'     => 3,
				'title'           => __( 'Breadcrumbs', 'seooptimierung-toolkit' ),
				'description'     => __( 'The trail from the home page to the current page.', 'seooptimierung-toolkit' ),
				'category'        => self::CATEGORY,
				'icon'            => 'arrow-right-alt2',
				'editor_script'   => 'seooptimierung-blocks',
				'render_callback' => array( $this, 'render_breadcrumbs' ),
				'attributes'      => array(
					'linkCurrent' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		foreach ( ContentTypes::all() as $slug => $definition ) {
			register_block_type(
				'seooptimierung/' . $slug,
				array(
					'api_version'     => 3,
					'title'           => $definition['title'],
					'description'     => $definition['description'],
					'category'        => self::CATEGORY,
					'icon'            => $definition['icon'],
					'keywords'        => array( 'schema', 'structured data', $definition['type'] ),
					'editor_script'   => 'seooptimierung-blocks',
					'attributes'      => ContentTypes::block_attributes( $slug ),
					'render_callback' => function ( $attributes ) use ( $slug ) {
						return $this->render_schema_block( $slug, is_array( $attributes ) ? $attributes : array() );
					},
				)
			);
		}
	}

	/**
	 * Block render callback for the breadcrumbs.
	 *
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	public function render_breadcrumbs( $attributes ): string {
		$attributes = is_array( $attributes ) ? $attributes : array();

		return $this->breadcrumbs( array( 'link_current' => ! empty( $attributes['linkCurrent'] ) ) );
	}

	/**
	 * Shortcode: [seooptimierung_breadcrumbs separator="/" home_label="Start"].
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'separator'    => '',
				'home_label'   => '',
				'link_current' => '',
			),
			is_array( $atts ) ? $atts : array(),
			'seooptimierung_breadcrumbs'
		);

		$args = array();
		foreach ( array( 'separator', 'home_label' ) as $key ) {
			if ( '' !== $atts[ $key ] ) {
				$args[ $key ] = $atts[ $key ];
			}
		}
		if ( '' !== $atts['link_current'] ) {
			$args['link_current'] = filter_var( $atts['link_current'], FILTER_VALIDATE_BOOLEAN );
		}

		return $this->breadcrumbs( $args );
	}

	/**
	 * Shortcode fallback for the content blocks, for editors without the
	 * block editor: [seooptimierung_schema type="faq" q1="…" a1="…"].
	 * Only the simple text fields of a type are available this way; the
	 * structured data is produced by Schema\EmbeddedSchema from the same
	 * attributes, so both paths agree.
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 */
	public function render_schema_shortcode( $atts ): string {
		$atts = is_array( $atts ) ? $atts : array();
		$type = isset( $atts['type'] ) ? sanitize_key( (string) $atts['type'] ) : '';

		if ( ! isset( ContentTypes::all()[ $type ] ) ) {
			return '';
		}

		unset( $atts['type'] );

		return $this->render_schema_block( $type, ContentTypes::attributes_from_shortcode( $type, $atts ) );
	}

	/**
	 * Renders one content block: the visible markup, plus the stylesheet.
	 *
	 * @param string              $slug       Content type slug.
	 * @param array<string,mixed> $attributes Block attributes.
	 */
	private function render_schema_block( string $slug, array $attributes ): string {
		$html = ContentTypes::html( $slug, $attributes );

		if ( '' !== $html ) {
			self::enqueue_schema_style();
		}

		return $html;
	}

	/**
	 * Small stylesheet for the content blocks (printed once, only on pages
	 * that use one).
	 */
	private static function enqueue_schema_style(): void {
		if ( ! wp_style_is( 'seooptimierung-schema', 'registered' ) ) {
			wp_register_style( 'seooptimierung-schema', false, array(), \Seooptimierung\Toolkit\VERSION );
			wp_add_inline_style(
				'seooptimierung-schema',
				'.seooptimierung-schema{margin:1.5em 0;padding:1em 1.25em;border:1px solid rgba(127,127,127,.3);border-radius:6px}' .
				'.seooptimierung-schema__title{margin:0 0 .5em;font-weight:700;font-size:1.15em}' .
				'.seooptimierung-schema__meta{list-style:none;margin:0 0 1em;padding:0;display:flex;flex-wrap:wrap;gap:.25em 1.5em;opacity:.85}' .
				'.seooptimierung-schema h3{margin:1em 0 .4em;font-size:1em}' .
				'.seooptimierung-schema ol,.seooptimierung-schema ul.seooptimierung-schema__list{margin:0 0 1em 1.25em}' .
				'.seooptimierung-schema dl{display:grid;grid-template-columns:max-content 1fr;gap:.25em 1em;margin:0 0 1em}' .
				'.seooptimierung-schema dt{font-weight:600}.seooptimierung-schema dd{margin:0}' .
				'.seooptimierung-schema__faq h3{margin-top:1em}'
			);
		}

		wp_enqueue_style( 'seooptimierung-schema' );
	}

	/**
	 * Renders breadcrumbs and makes sure their stylesheet is printed.
	 *
	 * @param array<string,mixed> $args Render arguments.
	 */
	private function breadcrumbs( array $args ): string {
		$html = Breadcrumbs::render( $args );

		if ( '' !== $html ) {
			Breadcrumbs::enqueue_style();
		}

		return $html;
	}
}

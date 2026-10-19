<?php
/**
 * Finds the structured-data content blocks inside a post and turns them
 * into JSON-LD nodes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

use WP_Post;

/**
 * Reads a post's stored content (blocks, and the [seooptimierung_schema]
 * shortcode for editors without the block editor) and builds one node per
 * block through Schema\ContentTypes — the same code that prints the visible
 * markup, so page and markup stay in step.
 *
 * Works from the stored content rather than from rendering, so it needs no
 * render pass of its own and the result is covered by the schema fragment
 * cache like the rest of the graph.
 */
final class EmbeddedSchema {

	/**
	 * Block name prefix of this plugin's content blocks.
	 */
	private const PREFIX = 'seooptimierung/';

	/**
	 * How deep reusable blocks are followed.
	 */
	private const MAX_DEPTH = 3;

	/**
	 * The content blocks of a post: a list of [slug, attributes].
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{0:string,1:array<string,mixed>}>
	 */
	public static function blocks_for( WP_Post $post ): array {
		return array_merge(
			self::from_blocks( parse_blocks( $post->post_content ), 0 ),
			self::from_shortcodes( $post->post_content )
		);
	}

	/**
	 * JSON-LD nodes for every content block except FAQ (whose questions
	 * join the page's single FAQPage node, see faq_entities_for()).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	public static function nodes_for( WP_Post $post ): array {
		$ctx    = self::context( $post );
		$counts = array();
		$nodes  = array();

		foreach ( self::blocks_for( $post ) as $block ) {
			list( $slug, $attrs ) = $block;

			if ( 'faq' === $slug ) {
				continue;
			}

			$counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + 1;
			$node            = ContentTypes::node( $slug, $attrs, $ctx, $counts[ $slug ] );

			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * The questions of every FAQ block in a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array<string,mixed>>
	 */
	public static function faq_entities_for( WP_Post $post ): array {
		$entities = array();

		foreach ( self::blocks_for( $post ) as $block ) {
			if ( 'faq' === $block[0] ) {
				$entities = array_merge( $entities, ContentTypes::faq_entities( $block[1] ) );
			}
		}

		return $entities;
	}

	/**
	 * Whether a post is the kind of page Google's Indexing API accepts:
	 * a job posting, or a page with a live stream (a VideoObject carrying a
	 * BroadcastEvent) — either as a content block or as the post's schema
	 * type override.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function is_indexing_api_page( WP_Post $post ): bool {
		if ( 'JobPosting' === GraphBuilder::schema_type_override( $post ) ) {
			return true;
		}

		foreach ( self::blocks_for( $post ) as $block ) {
			if ( 'job-posting' === $block[0] && ContentTypes::has_content( 'job-posting', $block[1] ) ) {
				return true;
			}
			if ( 'video' === $block[0] && ! empty( $block[1]['isLive'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Page facts the nodes refer to.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,string>
	 */
	private static function context( WP_Post $post ): array {
		$image = get_the_post_thumbnail_url( $post, 'full' );

		return array(
			'url'       => (string) get_permalink( $post ),
			'title'     => wp_strip_all_tags( (string) get_the_title( $post ) ),
			'author'    => (string) get_the_author_meta( 'display_name', (int) $post->post_author ),
			'image'     => is_string( $image ) ? $image : '',
			'published' => (string) get_post_time( 'c', false, $post ),
			'modified'  => (string) get_post_modified_time( 'c', false, $post ),
		);
	}

	/**
	 * Walks parsed blocks, following nested and reusable blocks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @param int                            $depth  Reusable-block depth so far.
	 * @return array<int,array{0:string,1:array<string,mixed>}>
	 */
	private static function from_blocks( array $blocks, int $depth ): array {
		$found = array();

		foreach ( $blocks as $block ) {
			$name = (string) ( $block['blockName'] ?? '' );

			if ( 0 === strpos( $name, self::PREFIX ) ) {
				$slug = substr( $name, strlen( self::PREFIX ) );
				if ( isset( ContentTypes::all()[ $slug ] ) ) {
					$defaults = array();
					foreach ( ContentTypes::block_attributes( $slug ) as $key => $definition ) {
						$defaults[ $key ] = $definition['default'];
					}
					$found[] = array( $slug, array_merge( $defaults, (array) ( $block['attrs'] ?? array() ) ) );
				}
			}

			if ( 'core/block' === $name && $depth < self::MAX_DEPTH && ! empty( $block['attrs']['ref'] ) ) {
				$reusable = get_post( (int) $block['attrs']['ref'] );
				if ( $reusable instanceof WP_Post && 'wp_block' === $reusable->post_type ) {
					$found = array_merge( $found, self::from_blocks( parse_blocks( $reusable->post_content ), $depth + 1 ) );
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::from_blocks( (array) $block['innerBlocks'], $depth ) );
			}
		}

		return $found;
	}

	/**
	 * Finds [seooptimierung_schema] shortcodes in raw content.
	 *
	 * @param string $content Raw post content.
	 * @return array<int,array{0:string,1:array<string,mixed>}>
	 */
	private static function from_shortcodes( string $content ): array {
		if ( false === strpos( $content, '[seooptimierung_schema' ) ) {
			return array();
		}

		$found = array();

		if ( preg_match_all( '/' . get_shortcode_regex( array( 'seooptimierung_schema' ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$atts = shortcode_parse_atts( $match[3] );
				$atts = is_array( $atts ) ? $atts : array();
				$slug = isset( $atts['type'] ) ? sanitize_key( (string) $atts['type'] ) : '';

				if ( isset( ContentTypes::all()[ $slug ] ) ) {
					unset( $atts['type'] );
					$found[] = array( $slug, ContentTypes::attributes_from_shortcode( $slug, $atts ) );
				}
			}
		}

		return $found;
	}
}

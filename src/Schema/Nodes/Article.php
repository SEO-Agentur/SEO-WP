<?php
/**
 * Article schema node.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema\Nodes;

use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Social\OpenGraph;
use WP_Post;
use WP_Post_Type;

/**
 * Builds the Article JSON-LD node for singular content on non-hierarchical
 * post types (posts and article-like custom post types). Hierarchical
 * types (pages and page-like custom post types) get a WebPage node only,
 * without hardcoding any post type name. WooCommerce products are Product's
 * job, not Article's — a product page gets exactly one primary content
 * node, never both.
 */
final class Article {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Builds the node, or null when the current request is not a singular,
	 * non-hierarchical post type.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( ! is_singular() ) {
			return null;
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$type_object = get_post_type_object( $post->post_type );
		if ( ! $type_object instanceof WP_Post_Type || $type_object->hierarchical ) {
			return null;
		}

		$url = get_permalink( $post );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		$node = array(
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'headline'         => get_the_title( $post ),
			'url'              => $url,
			'datePublished'    => get_the_date( DATE_W3C, $post ),
			'dateModified'     => get_the_modified_date( DATE_W3C, $post ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'isPartOf'         => array( '@id' => home_url( '/#website' ) ),
			'inLanguage'       => str_replace( '_', '-', get_locale() ),
		);

		$author_name = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( '' !== $author_name ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author_name,
			);
		}

		$excerpt = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		if ( '' !== $excerpt ) {
			$node['description'] = $excerpt;
		}

		// Same resolution as Open Graph (override -> featured image -> site
		// logo -> site icon), memoised there per request — so the schema
		// image and the social image are always the same, and the same
		// og:image override field controls both.
		$image = ( new OpenGraph() )->image();
		if ( '' !== $image['url'] ) {
			$node['image'] = array(
				'@type'  => 'ImageObject',
				'url'    => $image['url'],
				'width'  => $image['width'],
				'height' => $image['height'],
			);
		}

		$settings = get_option( self::OPTION_KEY, array() );
		if ( 'person' !== ( $settings['identity_type'] ?? 'organization' ) ) {
			$node['publisher'] = array( '@id' => home_url( '/#identity' ) );
		}

		$type_override = GraphBuilder::schema_type_override( $post );
		if ( '' !== $type_override ) {
			$node['@type'] = $type_override;
		}

		return $node;
	}
}

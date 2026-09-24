<?php
/**
 * WebPage schema node.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema\Nodes;

use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Support\Url;
use WP_Post;
use WP_Post_Type;
use WP_Term;

/**
 * Builds the WebPage JSON-LD node for any indexable front-end request.
 */
final class WebPage {

	/**
	 * Builds the node, or null on search/404 pages.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( is_search() || is_404() ) {
			return null;
		}

		$url = $this->current_url();
		if ( '' === $url ) {
			return null;
		}

		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => wp_strip_all_tags( (string) wp_get_document_title() ),
			'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
			'inLanguage' => str_replace( '_', '-', get_locale() ),
		);

		$type_override = $this->type_override();
		if ( '' !== $type_override ) {
			$node['@type'] = $type_override;
		}

		return $node;
	}

	/**
	 * Resolves the schema type override for the current request: a term
	 * override on a taxonomy archive, or a post override when this WebPage
	 * node is the primary content node (hierarchical post types only —
	 * non-hierarchical types get both an Article and a WebPage node, and
	 * the override belongs on Article there, not on both at once).
	 */
	private function type_override(): string {
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? GraphBuilder::schema_type_override_for_term( $term ) : '';
		}

		if ( is_singular() && $this->is_primary_content_node() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? GraphBuilder::schema_type_override( $post ) : '';
		}

		return '';
	}

	/**
	 * Whether this WebPage node is the primary content node for the
	 * current singular request — true only for hierarchical post types,
	 * where Article never builds a node. Non-hierarchical types get both
	 * an Article and a WebPage node; the schema type override belongs on
	 * Article there, not on both nodes at once.
	 */
	private function is_primary_content_node(): bool {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		$type_object = get_post_type_object( $post->post_type );

		return $type_object instanceof WP_Post_Type && $type_object->hierarchical;
	}

	/**
	 * Resolves the current request's URL.
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();
			return is_string( $permalink ) ? $permalink : '';
		}

		return Url::current();
	}
}

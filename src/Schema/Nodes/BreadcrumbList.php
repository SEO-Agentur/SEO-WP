<?php
/**
 * BreadcrumbList schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

use SeoWp\Support\Url;
use WP_Post;
use WP_Term;

/**
 * Builds the BreadcrumbList JSON-LD node from the home page down to the
 * current object, using post/term ancestors for any public post type or
 * taxonomy without hardcoding type names.
 */
final class BreadcrumbList {

	/**
	 * Builds the node, or null when there is nothing beyond the home page
	 * to list.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		$items = $this->items();

		if ( count( $items ) < 2 ) {
			return null;
		}

		$list = array();
		foreach ( $items as $index => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => Url::current() . '#breadcrumb',
			'itemListElement' => $list,
		);
	}

	/**
	 * Builds the ordered list of { name, url } breadcrumb items.
	 *
	 * @return array<int,array{name: string, url: string}>
	 */
	private function items(): array {
		$items = array(
			array(
				'name' => (string) get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
		);

		if ( is_singular() ) {
			return array_merge( $items, $this->post_trail() );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			return array_merge( $items, $this->term_trail() );
		}

		if ( is_post_type_archive() ) {
			$items[] = array(
				'name' => (string) post_type_archive_title( '', false ),
				'url'  => Url::current(),
			);
			return $items;
		}

		return array();
	}

	/**
	 * Builds the ancestor trail for a singular post, ending with itself.
	 *
	 * @return array<int,array{name: string, url: string}>
	 */
	private function post_trail(): array {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$trail     = array();
		$ancestors = array_reverse( get_post_ancestors( $post ) );

		foreach ( $ancestors as $ancestor_id ) {
			$trail[] = array(
				'name' => get_the_title( $ancestor_id ),
				'url'  => (string) get_permalink( $ancestor_id ),
			);
		}

		$trail[] = array(
			'name' => get_the_title( $post ),
			'url'  => (string) get_permalink( $post ),
		);

		return $trail;
	}

	/**
	 * Builds the ancestor trail for a taxonomy term, ending with itself.
	 *
	 * @return array<int,array{name: string, url: string}>
	 */
	private function term_trail(): array {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$trail        = array();
		$ancestor_ids = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );

		foreach ( $ancestor_ids as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$link = get_term_link( $ancestor );
				if ( ! is_wp_error( $link ) ) {
					$trail[] = array(
						'name' => $ancestor->name,
						'url'  => (string) $link,
					);
				}
			}
		}

		$trail[] = array(
			'name' => $term->name,
			'url'  => Url::current(),
		);

		return $trail;
	}
}

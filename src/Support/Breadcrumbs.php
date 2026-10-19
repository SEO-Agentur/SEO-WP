<?php
/**
 * Breadcrumb trail: the data behind both the visible breadcrumbs and the
 * BreadcrumbList schema.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

use WP_Post;
use WP_Term;

/**
 * One trail, two consumers: Schema\Nodes\BreadcrumbList prints it as
 * JSON-LD, render() prints it as a visible <nav>. Because both read the
 * same list, what a visitor sees and what a search engine is told can
 * never disagree.
 *
 * Trails: Home › (posts page | post type archive | category chain |
 * page ancestors) › current. For a post in a hierarchical taxonomy
 * (category, product category, ...) the deepest assigned term and its
 * ancestors form the middle of the trail, unless switched off.
 */
final class Breadcrumbs {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * The trail for the current request, home first. Empty when there is
	 * nothing sensible to show (search, 404, date and author archives).
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function items(): array {
		$items = array(
			array(
				'name' => self::home_label(),
				'url'  => home_url( '/' ),
			),
		);

		if ( is_front_page() ) {
			return array();
		}

		if ( is_singular() ) {
			$items = array_merge( $items, self::post_trail() );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$items = array_merge( $items, self::term_trail() );
		} elseif ( is_post_type_archive() ) {
			$items[] = array(
				'name' => (string) post_type_archive_title( '', false ),
				'url'  => Url::current(),
			);
		} elseif ( is_home() && (int) get_option( 'page_for_posts' ) > 0 ) {
			$items[] = array(
				'name' => get_the_title( (int) get_option( 'page_for_posts' ) ),
				'url'  => (string) get_permalink( (int) get_option( 'page_for_posts' ) ),
			);
		} else {
			return array();
		}

		/**
		 * Filters the breadcrumb trail, for the visible breadcrumbs and
		 * the BreadcrumbList schema alike.
		 *
		 * @param array<int,array{name:string,url:string}> $items Trail, home first, current page last.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		$filtered = apply_filters( 'seooptimierung/breadcrumbs/items', $items );

		return is_array( $filtered ) ? array_values( $filtered ) : $items;
	}

	/**
	 * The visible breadcrumbs as HTML, or '' when there is no trail.
	 *
	 * @param array<string,mixed> $args Optional: separator, home_label, link_current (bool), class.
	 */
	public static function render( array $args = array() ): string {
		$items = self::items();
		if ( count( $items ) < 2 ) {
			return '';
		}

		$settings     = self::settings();
		$separator    = isset( $args['separator'] ) ? (string) $args['separator'] : ( '' !== (string) ( $settings['breadcrumb_separator'] ?? '' ) ? (string) $settings['breadcrumb_separator'] : '›' );
		$link_current = isset( $args['link_current'] ) ? (bool) $args['link_current'] : ! empty( $settings['breadcrumb_link_current'] );
		$class        = isset( $args['class'] ) ? sanitize_html_class( (string) $args['class'] ) : '';

		if ( isset( $args['home_label'] ) && '' !== (string) $args['home_label'] ) {
			$items[0]['name'] = (string) $args['home_label'];
		}

		$last = count( $items ) - 1;
		$html = '<nav class="seooptimierung-breadcrumbs' . ( '' !== $class ? ' ' . $class : '' ) . '" aria-label="' . esc_attr__( 'Breadcrumb', 'seooptimierung-toolkit' ) . '"><ol>';

		foreach ( $items as $index => $item ) {
			$name = esc_html( $item['name'] );

			if ( $index === $last ) {
				$html .= '<li aria-current="page">' . ( $link_current ? '<a href="' . esc_url( $item['url'] ) . '">' . $name . '</a>' : $name ) . '</li>';
			} else {
				$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . $name . '</a><span class="seooptimierung-breadcrumbs__sep" aria-hidden="true">' . esc_html( $separator ) . '</span></li>';
			}
		}

		$html .= '</ol></nav>';

		/**
		 * Filters the visible breadcrumbs HTML.
		 *
		 * @param string                                   $html  Breadcrumb markup.
		 * @param array<int,array{name:string,url:string}> $items Trail the markup was built from.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		return (string) apply_filters( 'seooptimierung/breadcrumbs/html', $html, $items );
	}

	/**
	 * Small stylesheet for the visible breadcrumbs, attached to a handle
	 * so it is printed once and only on pages that show breadcrumbs.
	 */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( 'seooptimierung-breadcrumbs', 'registered' ) ) {
			wp_register_style( 'seooptimierung-breadcrumbs', false, array(), \Seooptimierung\Toolkit\VERSION );
			wp_add_inline_style(
				'seooptimierung-breadcrumbs',
				'.seooptimierung-breadcrumbs ol{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:.35em;font-size:.9em}' .
				'.seooptimierung-breadcrumbs li{display:inline-flex;gap:.35em;align-items:center}' .
				'.seooptimierung-breadcrumbs__sep{opacity:.6}'
			);
		}

		wp_enqueue_style( 'seooptimierung-breadcrumbs' );
	}

	/**
	 * The label of the first crumb.
	 */
	private static function home_label(): string {
		$label = (string) ( self::settings()['breadcrumb_home_label'] ?? '' );

		return '' !== $label ? $label : (string) get_bloginfo( 'name' );
	}

	/**
	 * Settings array.
	 *
	 * @return array<string,mixed>
	 */
	private static function settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Trail for a singular post: archive or posts page or category chain
	 * first, then page ancestors, then the post itself.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function post_trail(): array {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$trail     = array();
		$ancestors = array_reverse( get_post_ancestors( $post ) );

		if ( array() === $ancestors ) {
			$trail = array_merge( $trail, self::section_trail( $post ) );
		}

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
	 * What sits between home and a non-hierarchical post: the posts page,
	 * the post type's archive, and the deepest category with its parents.
	 *
	 * @param WP_Post $post The post.
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function section_trail( WP_Post $post ): array {
		$trail = array();

		if ( 'post' === $post->post_type ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page > 0 ) {
				$trail[] = array(
					'name' => get_the_title( $posts_page ),
					'url'  => (string) get_permalink( $posts_page ),
				);
			}
		} elseif ( is_post_type_viewable( $post->post_type ) ) {
			$object = get_post_type_object( $post->post_type );
			$link   = $object && $object->has_archive ? get_post_type_archive_link( $post->post_type ) : false;
			if ( $object && is_string( $link ) && '' !== $link ) {
				$trail[] = array(
					'name' => (string) $object->labels->name,
					'url'  => $link,
				);
			}
		}

		if ( ! empty( self::settings()['breadcrumb_include_category'] ) || ! array_key_exists( 'breadcrumb_include_category', self::settings() ) ) {
			$trail = array_merge( $trail, self::term_chain_for_post( $post ) );
		}

		return $trail;
	}

	/**
	 * The deepest term of the post's first hierarchical public taxonomy,
	 * preceded by its ancestors.
	 *
	 * @param WP_Post $post The post.
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function term_chain_for_post( WP_Post $post ): array {
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || ! $taxonomy->hierarchical || 'post_format' === $taxonomy->name ) {
				continue;
			}

			$terms = get_the_terms( $post, $taxonomy->name );
			if ( ! is_array( $terms ) || array() === $terms ) {
				continue;
			}

			$deepest = null;
			$depth   = -1;
			foreach ( $terms as $term ) {
				$level = count( get_ancestors( $term->term_id, $taxonomy->name, 'taxonomy' ) );
				if ( $level > $depth ) {
					$deepest = $term;
					$depth   = $level;
				}
			}

			if ( ! $deepest instanceof WP_Term ) {
				continue;
			}

			$chain = array();
			foreach ( array_reverse( get_ancestors( $deepest->term_id, $taxonomy->name, 'taxonomy' ) ) as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, $taxonomy->name );
				$link     = $ancestor instanceof WP_Term ? get_term_link( $ancestor ) : '';
				if ( $ancestor instanceof WP_Term && ! is_wp_error( $link ) ) {
					$chain[] = array(
						'name' => $ancestor->name,
						'url'  => (string) $link,
					);
				}
			}

			$link = get_term_link( $deepest );
			if ( ! is_wp_error( $link ) ) {
				$chain[] = array(
					'name' => $deepest->name,
					'url'  => (string) $link,
				);
			}

			return $chain;
		}

		return array();
	}

	/**
	 * Trail for a taxonomy archive: term ancestors, then the term.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	private static function term_trail(): array {
		$term = get_queried_object();
		if ( ! $term instanceof WP_Term ) {
			return array();
		}

		$trail = array();

		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			$link     = $ancestor instanceof WP_Term ? get_term_link( $ancestor ) : '';
			if ( $ancestor instanceof WP_Term && ! is_wp_error( $link ) ) {
				$trail[] = array(
					'name' => $ancestor->name,
					'url'  => (string) $link,
				);
			}
		}

		$trail[] = array(
			'name' => $term->name,
			'url'  => Url::current(),
		);

		return $trail;
	}
}

<?php
/**
 * Robots meta directive overrides.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Meta;

use WP_Post;
use WP_Term;

/**
 * Adds noindex/nofollow/noarchive/nosnippet/noimageindex directives on top
 * of WordPress core's wp_robots output. Never touches max-image-preview
 * (core sets that itself since WP 5.7) or search/404 (core already
 * noindexes those).
 */
final class Robots {

	/**
	 * Post/term meta key for the noindex override ('1', '0' or unset).
	 */
	public const META_NOINDEX = '_seo_wp_robots_noindex';

	/**
	 * Post/term meta key for the nofollow override ('1', '0' or unset).
	 */
	public const META_NOFOLLOW = '_seo_wp_robots_nofollow';

	/**
	 * Post/term meta key for extra directives (array of directive names).
	 */
	public const META_ADVANCED = '_seo_wp_robots_advanced';

	/**
	 * User meta key for a per-author "noindex this author's archive"
	 * override ('1' or unset).
	 */
	public const META_AUTHOR_NOINDEX = '_seo_wp_author_noindex';

	/**
	 * Advanced directives this plugin is allowed to add.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVES = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Registers the wp_robots filter.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
	}

	/**
	 * Merges this plugin's directive overrides into core's robots array.
	 *
	 * @param array<string,bool> $robots Directives collected so far by WordPress core.
	 * @return array<string,bool>
	 */
	public function filter_robots( array $robots ): array {
		$overrides = $this->overrides();

		if ( array() === $overrides ) {
			return $robots;
		}

		if ( '1' === $overrides['noindex'] ) {
			$robots['noindex'] = true;
		} elseif ( '0' === $overrides['noindex'] ) {
			unset( $robots['noindex'] );
		}

		if ( '1' === $overrides['nofollow'] ) {
			$robots['nofollow'] = true;
		} elseif ( '0' === $overrides['nofollow'] ) {
			unset( $robots['nofollow'] );
		}

		foreach ( self::ADVANCED_DIRECTIVES as $directive ) {
			if ( in_array( $directive, $overrides['advanced'], true ) ) {
				$robots[ $directive ] = true;
			}
		}

		return $robots;
	}

	/**
	 * Reads the robots overrides for the current object, if any. Never
	 * returns overrides for search/404, which core already handles.
	 *
	 * @return array{noindex: string, nofollow: string, advanced: string[]}|array{}
	 */
	private function overrides(): array {
		if ( is_search() || is_404() ) {
			return array();
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return array();
			}

			return array(
				'noindex'  => (string) get_post_meta( $post->ID, self::META_NOINDEX, true ),
				'nofollow' => (string) get_post_meta( $post->ID, self::META_NOFOLLOW, true ),
				'advanced' => (array) get_post_meta( $post->ID, self::META_ADVANCED, true ),
			);
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return array();
			}

			return array(
				'noindex'  => (string) get_term_meta( $term->term_id, self::META_NOINDEX, true ),
				'nofollow' => (string) get_term_meta( $term->term_id, self::META_NOFOLLOW, true ),
				'advanced' => (array) get_term_meta( $term->term_id, self::META_ADVANCED, true ),
			);
		}

		if ( is_author() ) {
			$author_id = (int) get_query_var( 'author' );
			if ( $author_id <= 0 ) {
				return array();
			}

			return array(
				'noindex'  => '1' === (string) get_user_meta( $author_id, self::META_AUTHOR_NOINDEX, true ) ? '1' : '',
				'nofollow' => '',
				'advanced' => array(),
			);
		}

		return array();
	}
}

<?php
/**
 * Adds the news:news sitemap extension.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Sitemaps;

use WP_Query;

/**
 * Renders a Google News sitemap limited to content published in the last
 * 48 hours, across any public, non-hierarchical post type (the same
 * "article-like" heuristic used for the Article schema node), without
 * hardcoding a post type name.
 */
final class NewsSitemap {

	/**
	 * How far back to look for eligible content.
	 */
	private const WINDOW_HOURS = 48;

	/**
	 * Post meta key marking a post as excluded from indexing.
	 */
	private const META_NOINDEX = '_seo_wp_robots_noindex';

	/**
	 * Whether there is any content to list right now.
	 */
	public function has_entries(): bool {
		return array() !== $this->query()->posts;
	}

	/**
	 * Renders the news sitemap, or null when there is nothing to show.
	 */
	public function render(): ?string {
		$query = $this->query();

		if ( array() === $query->posts ) {
			return null;
		}

		$language = substr( get_locale(), 0, 2 );
		$name     = (string) get_bloginfo( 'name' );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . "\n";

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$permalink = get_permalink( $post_id );

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$xml .= '<url>' . "\n";
			$xml .= '<loc>' . esc_url( $permalink ) . '</loc>' . "\n";
			$xml .= '<news:news>' . "\n";
			$xml .= '<news:publication>' . "\n";
			$xml .= '<news:name>' . esc_html( $name ) . '</news:name>' . "\n";
			$xml .= '<news:language>' . esc_html( $language ) . '</news:language>' . "\n";
			$xml .= '</news:publication>' . "\n";
			$xml .= '<news:publication_date>' . esc_html( (string) get_post_time( DATE_W3C, true, $post_id ) ) . '</news:publication_date>' . "\n";
			$xml .= '<news:title>' . esc_html( get_the_title( $post_id ) ) . '</news:title>' . "\n";
			$xml .= '</news:news>' . "\n";
			$xml .= '</url>' . "\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}

	/**
	 * Queries eligible content from the last 48 hours.
	 */
	private function query(): WP_Query {
		return new WP_Query(
			array(
				'post_type'              => $this->eligible_post_types(),
				'post_status'            => 'publish',
				'has_password'           => false,
				'date_query'             => array(
					array( 'after' => self::WINDOW_HOURS . ' hours ago' ),
				),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- 1000 is the Google News sitemap protocol's own per-file URL cap, not an arbitrary large query.
				'posts_per_page'         => 1000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_NOINDEX,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_NOINDEX,
						'value'   => '1',
						'compare' => '!=',
					),
				),
			)
		);
	}

	/**
	 * Public, non-hierarchical post types: the same heuristic used for
	 * Article schema, so "article-like" content is defined consistently
	 * across the plugin without naming any specific post type.
	 *
	 * @return string[]
	 */
	private function eligible_post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( ! $post_type->hierarchical ) {
				$types[] = $post_type->name;
			}
		}

		return $types;
	}
}

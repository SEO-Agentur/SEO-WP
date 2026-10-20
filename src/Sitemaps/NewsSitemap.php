<?php
/**
 * Adds the news:news sitemap extension.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

use Seooptimierung\Toolkit\Meta\Robots;
use WP_Query;

/**
 * Renders a Google News sitemap limited to content published in the last
 * 48 hours, across any public, non-hierarchical post type (the same
 * "article-like" heuristic used for the Article schema node), without
 * hardcoding a post type name. Follows Google's News sitemap guidelines
 * (https://developers.google.com/search/docs/crawling-indexing/sitemaps/news-sitemap):
 * a dedicated file, only <news:news>-tagged <url> entries (no mixing with
 * the regular sitemaps), only content from the last 48 hours, and at most
 * 1000 URLs. Opt-in via the "Enable Google News sitemap" setting — most
 * sites don't publish news content, so it doesn't ship on unconditionally.
 */
final class NewsSitemap {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * How far back to look for eligible content, per Google's News
	 * sitemap guidelines.
	 */
	private const WINDOW_HOURS = 48;

	/**
	 * Whether the site owner has enabled the news sitemap (on by default,
	 * matching this feature's behavior before the setting existed).
	 */
	private function enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! isset( $settings['enable_news_sitemap'] ) || ! empty( $settings['enable_news_sitemap'] );
	}

	/**
	 * Whether there is any content to list right now.
	 */
	public function has_entries(): bool {
		return $this->enabled() && array() !== $this->query()->posts;
	}

	/**
	 * Renders the news sitemap, or null when disabled or there is nothing
	 * to show.
	 */
	public function render(): ?string {
		if ( ! $this->enabled() ) {
			return null;
		}

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
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the noindex flag lives in meta; there is no cheaper way to leave those items out, and the query runs only when the sitemap is generated.
					'relation' => 'OR',
					array(
						'key'     => Robots::META_NOINDEX,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => Robots::META_NOINDEX,
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

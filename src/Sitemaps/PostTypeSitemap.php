<?php
/**
 * Generic sitemap for a single public post type.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Sitemaps;

use WP_Query;

/**
 * Renders a sitemap for any public post type, excluding noindex/private/
 * password-protected objects, with embedded image:image entries.
 */
final class PostTypeSitemap {

	/**
	 * Maximum number of URLs per sitemap page, matching WordPress core's
	 * own default.
	 */
	private const ITEMS_PER_PAGE = 2000;

	/**
	 * Post meta key marking a post as excluded from indexing.
	 */
	private const META_NOINDEX = '_seo_wp_robots_noindex';

	/**
	 * Number of sitemap pages a post type needs, given the exclusion
	 * rules.
	 *
	 * @param string $post_type Post type slug.
	 */
	public function max_pages( string $post_type ): int {
		$query = new WP_Query( $this->query_args( $post_type, 1 ) );

		return (int) $query->max_num_pages;
	}

	/**
	 * Renders one page of a post type's sitemap, or null when the post
	 * type is not public or the page has nothing to show.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $page      Page number, starting at 1.
	 */
	public function render( string $post_type, int $page ): ?string {
		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
			return null;
		}

		$query = new WP_Query( $this->query_args( $post_type, $page ) );

		if ( array() === $query->posts ) {
			return null;
		}

		$image_sitemap = new ImageSitemap();
		$hreflang      = new HreflangAlternates();
		$urls          = array();

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$permalink = get_permalink( $post_id );

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$urls[] = array(
				'loc'        => $permalink,
				'lastmod'    => (string) get_post_modified_time( DATE_W3C, true, $post_id ),
				'images'     => $image_sitemap->images_for( $post_id ),
				'alternates' => $hreflang->for_post( $post_id ),
			);
		}

		return $this->xml( $urls );
	}

	/**
	 * Builds the WP_Query args for a post type's sitemap page, excluding
	 * password-protected and noindexed posts.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $page      Page number, starting at 1.
	 * @return array<string,mixed>
	 */
	private function query_args( string $post_type, int $page ): array {
		return array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'has_password'           => false,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'posts_per_page'         => self::ITEMS_PER_PAGE,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
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
		);
	}

	/**
	 * Builds the urlset XML for a list of URL entries.
	 *
	 * @param array<int,array{loc: string, lastmod: string, images: string[], alternates: array<int,array{hreflang:string,href:string}>}> $urls URL entries.
	 */
	private function xml( array $urls ): string {
		$hreflang_renderer = new HreflangAlternates();

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $urls as $url ) {
			$xml .= '<url>' . "\n";
			$xml .= '<loc>' . esc_url( $url['loc'] ) . '</loc>' . "\n";

			if ( '' !== $url['lastmod'] ) {
				$xml .= '<lastmod>' . esc_html( $url['lastmod'] ) . '</lastmod>' . "\n";
			}

			foreach ( $url['images'] as $image_url ) {
				$xml .= '<image:image><image:loc>' . esc_url( $image_url ) . '</image:loc></image:image>' . "\n";
			}

			$xml .= $hreflang_renderer->xml( $url['alternates'] );

			$xml .= '</url>' . "\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}
}

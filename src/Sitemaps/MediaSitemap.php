<?php
/**
 * Dedicated image and video sitemaps.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

use Seooptimierung\Toolkit\Meta\Robots;
use WP_Query;

/**
 * Renders /sitemap_images.xml and /sitemap_video.xml: one <url> per
 * published page that actually contains an image or video, with the
 * image:image / video:video entries inside. Which post types are scanned
 * follows the same per-post-type switches as the content sitemaps.
 *
 * Finding out which posts contain media means rendering their content, so
 * every page of this sitemap is object-cached (same group and lifetime as
 * the rest, flushed together with them); a page with no media at all
 * renders as null and the index leaves it out.
 */
final class MediaSitemap {

	/**
	 * Posts scanned per sitemap page. Smaller than the content sitemaps'
	 * 2000 because every post's content has to be rendered to look for
	 * media.
	 */
	private const POSTS_PER_PAGE = 500;

	/**
	 * Object cache group shared with Sitemaps\Router.
	 */
	private const CACHE_GROUP = 'seo_wp_sitemaps';

	/**
	 * Object cache lifetime.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Number of pages to scan for a media sitemap kind.
	 *
	 * @param string $kind SitemapNames::KIND_IMAGES or KIND_VIDEO.
	 */
	public function max_pages( string $kind ): int {
		if ( ! $this->enabled( $kind ) ) {
			return 0;
		}

		$post_types = $this->post_types();
		if ( array() === $post_types ) {
			return 0;
		}

		$query = new WP_Query( $this->query_args( $post_types, 1, true ) );

		return (int) $query->max_num_pages;
	}

	/**
	 * Renders one page, or null when the kind is off or the page holds
	 * no media.
	 *
	 * @param string $kind SitemapNames::KIND_IMAGES or KIND_VIDEO.
	 * @param int    $page Page number, starting at 1.
	 */
	public function render( string $kind, int $page ): ?string {
		if ( ! $this->enabled( $kind ) ) {
			return null;
		}

		$cache_key = "media:{$kind}:{$page}";
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( $found ) {
			return is_string( $cached ) ? $cached : null;
		}

		$xml = $this->build( $kind, $page );

		wp_cache_set( $cache_key, $xml, self::CACHE_GROUP, self::CACHE_TTL );

		return $xml;
	}

	/**
	 * Builds a page's XML without consulting the cache.
	 *
	 * @param string $kind SitemapNames::KIND_IMAGES or KIND_VIDEO.
	 * @param int    $page Page number, starting at 1.
	 */
	private function build( string $kind, int $page ): ?string {
		$post_types = $this->post_types();
		if ( array() === $post_types ) {
			return null;
		}

		$query = new WP_Query( $this->query_args( $post_types, $page, false ) );
		if ( array() === $query->posts ) {
			return null;
		}

		$images     = new ImageSitemap();
		$videos     = new VideoSitemap();
		$xml_writer = new PostTypeSitemap();
		$body       = '';

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$permalink = get_permalink( $post_id );
			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$entries = '';

			if ( SitemapNames::KIND_IMAGES === $kind ) {
				foreach ( $images->images_for( $post_id ) as $image_url ) {
					$entries .= '<image:image><image:loc>' . esc_url( $image_url ) . '</image:loc></image:image>' . "\n";
				}
			} else {
				foreach ( $videos->videos_for( $post_id ) as $entry ) {
					$entries .= $xml_writer->video_xml( $entry );
				}
			}

			if ( '' === $entries ) {
				continue;
			}

			$body .= '<url>' . "\n" . '<loc>' . esc_url( $permalink ) . '</loc>' . "\n" . $entries . '</url>' . "\n";
		}

		if ( '' === $body ) {
			return null;
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . "\n";

		return $xml . $body . '</urlset>';
	}

	/**
	 * Whether the given media sitemap is switched on.
	 *
	 * @param string $kind SitemapNames::KIND_IMAGES or KIND_VIDEO.
	 */
	private function enabled( string $kind ): bool {
		if ( SitemapNames::KIND_IMAGES === $kind ) {
			return Inclusion::images_sitemap();
		}

		return SitemapNames::KIND_VIDEO === $kind && Inclusion::video_sitemap();
	}

	/**
	 * The post types scanned: public ones that are in the content
	 * sitemaps (attachments themselves are never scanned).
	 *
	 * @return string[]
	 */
	private function post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( 'attachment' !== $post_type && Inclusion::post_type( $post_type ) ) {
				$types[] = $post_type;
			}
		}

		return $types;
	}

	/**
	 * WP_Query args for one page: published, public, indexable posts of
	 * every scanned type, most recently changed first.
	 *
	 * @param string[] $post_types      Post types to scan.
	 * @param int      $page            Page number, starting at 1.
	 * @param bool     $need_found_rows Whether the caller needs max_num_pages.
	 * @return array<string,mixed>
	 */
	private function query_args( array $post_types, int $page, bool $need_found_rows ): array {
		$args = array(
			'post_type'              => $post_types,
			'post_status'            => 'publish',
			'has_password'           => false,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'posts_per_page'         => self::POSTS_PER_PAGE,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'no_found_rows'          => ! $need_found_rows,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'meta_query'             => array(
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
		);

		$excluded = PostTypeSitemap::excluded_post_ids();
		if ( array() !== $excluded ) {
			$args['post__not_in'] = $excluded;
		}

		return $args;
	}
}

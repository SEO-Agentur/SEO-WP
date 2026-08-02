<?php
/**
 * Adds the image:image sitemap extension.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Sitemaps;

use WP_Post;

/**
 * Resolves image:image entries for a post's featured image and any images
 * embedded in its content.
 */
final class ImageSitemap {

	/**
	 * Resolves the image URLs to list for a post's sitemap entry.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function images_for( int $post_id ): array {
		$images = array();

		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $post_id ), 'full' );
			if ( is_array( $src ) ) {
				$images[] = (string) $src[0];
			}
		}

		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && '' !== $post->post_content ) {
			$images = array_merge( $images, self::extract_image_urls( $this->rendered_content( $post ) ) );
		}

		return array_values( array_unique( array_filter( $images ) ) );
	}

	/**
	 * Runs post_content through the same 'the_content' filters WordPress
	 * applies on the front end. Page builders (Divi, WPBakery, and others)
	 * store their own shortcodes or block markup in post_content rather
	 * than literal <img> tags, so images only exist in the output once
	 * those filters expand it — reading raw post_content would silently
	 * miss every image on a builder-made page. Sitemap pages are cached
	 * (see Sitemaps\Router), so this runs at most once per post per cache
	 * window, not on every request.
	 *
	 * @param WP_Post $post Post to render.
	 */
	private function rendered_content( WP_Post $post ): string {
		$original_post = $GLOBALS['post'] ?? null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- required so builder 'the_content' filters that read global $post see the right one.
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WP core's own 'the_content' filter to expand builder shortcodes/blocks, not defining a hook of our own.
		$content = (string) apply_filters( 'the_content', $post->post_content );

		if ( $original_post instanceof WP_Post ) {
			$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring, not overriding.
			setup_postdata( $original_post );
		} else {
			unset( $GLOBALS['post'] );
			wp_reset_postdata();
		}

		return $content;
	}

	/**
	 * Extracts <img src="..."> URLs from a block of HTML. Pure string
	 * logic, independent of WordPress, for easy unit testing.
	 *
	 * @param string $html HTML content to scan.
	 * @return string[]
	 */
	public static function extract_image_urls( string $html ): array {
		if ( ! preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return array();
		}

		return $matches[1];
	}
}

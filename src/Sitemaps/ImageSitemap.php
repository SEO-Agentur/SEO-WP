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
			$images = array_merge( $images, self::extract_image_urls( $post->post_content ) );
		}

		return array_values( array_unique( array_filter( $images ) ) );
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

<?php
/**
 * Adds the video:video sitemap extension.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

use Seooptimierung\Toolkit\Support\ContentRenderer;
use WP_Post;

/**
 * Resolves video:video entries for videos embedded in a post's content.
 * Google requires every entry to carry a thumbnail — rather than inventing
 * or fetching one, an entry is only emitted when a real thumbnail is
 * actually available: a self-hosted <video>'s own poster attribute, or a
 * YouTube embed's well-known, static thumbnail URL. A self-hosted video
 * with no poster, or an embed from a provider with no predictable
 * thumbnail URL (Vimeo and others, which need their own API call to
 * resolve one), is left out entirely — an incomplete video sitemap entry
 * is invalid, so skipping it is more correct than guessing.
 */
final class VideoSitemap {

	/**
	 * The <video>...</video> block regex below uses a lazy dotall match
	 * anchored on a literal closing tag, so content with many unclosed
	 * <video opens scans to the end of the string on each attempt (O(n·k),
	 * quadratic in the worst case, not exponential). Rendered content is
	 * truncated to this length before scanning to bound that cost —
	 * post_content this large is already far outside any realistic single
	 * post.
	 */
	private const MAX_SCAN_LENGTH = 200000;

	/**
	 * Resolves the video entries to list for a post's sitemap entry.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{title:string,description:string,thumbnail_loc:string,content_loc?:string,player_loc?:string}>
	 */
	public function videos_for( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || '' === $post->post_content ) {
			return array();
		}

		// Sitemap pages are cached (see Sitemaps\Router), so rendering the
		// content this way runs at most once per post per cache window.
		$html = ContentRenderer::render( $post );
		if ( strlen( $html ) > self::MAX_SCAN_LENGTH ) {
			$html = substr( $html, 0, self::MAX_SCAN_LENGTH );
		}

		$found = array_merge(
			self::extract_self_hosted_videos( $html ),
			self::extract_youtube_videos( $html )
		);

		if ( array() === $found ) {
			return array();
		}

		$title       = get_the_title( $post );
		$description = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		$description = '' !== $description ? $description : $title;

		$videos = array();
		foreach ( $found as $entry ) {
			$videos[] = array_merge(
				array(
					'title'       => $title,
					'description' => $description,
				),
				$entry
			);
		}

		return $videos;
	}

	/**
	 * Extracts self-hosted <video> elements that have both a poster and a
	 * resolvable source (its own src attribute, or a child <source src>).
	 * Pure string logic, independent of WordPress, for easy unit testing.
	 *
	 * @param string $html HTML content to scan.
	 * @return array<int,array{thumbnail_loc:string,content_loc:string}>
	 */
	public static function extract_self_hosted_videos( string $html ): array {
		if ( ! preg_match_all( '/<video\b([^>]*)>(.*?)<\/video>/is', $html, $blocks, PREG_SET_ORDER ) ) {
			return array();
		}

		$videos = array();
		foreach ( $blocks as $block ) {
			$attrs = $block[1];
			$inner = $block[2];

			if ( ! preg_match( '/\bposter=["\']([^"\']+)["\']/i', $attrs, $poster_match ) ) {
				continue;
			}

			$src = '';
			if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
				$src = $src_match[1];
			} elseif ( preg_match( '/<source\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $inner, $source_match ) ) {
				$src = $source_match[1];
			}

			if ( '' === $src ) {
				continue;
			}

			$videos[] = array(
				'thumbnail_loc' => $poster_match[1],
				'content_loc'   => $src,
			);
		}

		return $videos;
	}

	/**
	 * Extracts YouTube iframe embeds, using YouTube's well-known static
	 * thumbnail URL (no API call needed) as the required thumbnail.
	 *
	 * @param string $html HTML content to scan.
	 * @return array<int,array{thumbnail_loc:string,player_loc:string}>
	 */
	public static function extract_youtube_videos( string $html ): array {
		$pattern = '/<iframe\b[^>]*\bsrc=["\'](https?:\/\/(?:www\.)?(?:youtube(?:-nocookie)?\.com\/embed\/|youtu\.be\/)([A-Za-z0-9_-]{6,})[^"\']*)["\']/i';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$videos = array();
		foreach ( $matches as $match ) {
			$videos[] = array(
				'thumbnail_loc' => self::youtube_thumbnail_url( $match[2] ),
				'player_loc'    => $match[1],
			);
		}

		return $videos;
	}

	/**
	 * YouTube's documented, stable thumbnail URL pattern for a video ID.
	 *
	 * @param string $video_id YouTube video ID.
	 */
	public static function youtube_thumbnail_url( string $video_id ): string {
		return "https://i.ytimg.com/vi/{$video_id}/hqdefault.jpg";
	}
}

<?php
/**
 * Strips links from inserted images to their own attachment page.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

/**
 * WordPress lets an editor link an inserted image to its own attachment
 * page (a thin, often-unwanted standalone page for that one image). When
 * "strip_attachment_links" is enabled, removes that wrapping <a> from
 * rendered content — the <img> itself, and any other kind of link, is
 * left untouched. Non-destructive: the stored post_content is never
 * modified, only the rendered output.
 */
final class ImageLinks {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the the_content filter.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'maybe_strip_attachment_links' ), 20 );
	}

	/**
	 * Strips attachment-page links from images in the content, when the
	 * setting is enabled.
	 *
	 * @param string $content Rendered post content.
	 */
	public function maybe_strip_attachment_links( string $content ): string {
		if ( is_admin() ) {
			return $content;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		if ( empty( $settings['strip_attachment_links'] ) ) {
			return $content;
		}

		return self::strip_links( $content, 'get_attachment_link' );
	}

	/**
	 * Removes <a> tags wrapping a WordPress-managed image (one carrying a
	 * wp-image-{ID} class, which core/Gutenberg always adds) when the
	 * link's href matches that exact image's own attachment permalink —
	 * so a deliberate link to an unrelated page is never touched, only a
	 * genuine "linked to its own attachment page" image. The attachment
	 * link resolver is injected so this stays pure/testable without
	 * WordPress; images without a wp-image-{ID} class are left alone.
	 *
	 * @param string   $content                  HTML content to scan.
	 * @param callable $resolve_attachment_link Resolves an attachment ID to its permalink, or a falsy value.
	 */
	public static function strip_links( string $content, callable $resolve_attachment_link ): string {
		return (string) preg_replace_callback(
			'#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(\s*<img[^>]*\bwp-image-(\d+)\b[^>]*>\s*)</a>#i',
			static function ( array $matches ) use ( $resolve_attachment_link ): string {
				$attachment_link = $resolve_attachment_link( (int) $matches[3] );

				if ( ! is_string( $attachment_link ) || '' === $attachment_link ) {
					return $matches[0];
				}

				return rtrim( $matches[1], '/' ) === rtrim( $attachment_link, '/' ) ? $matches[2] : $matches[0];
			},
			$content
		);
	}
}

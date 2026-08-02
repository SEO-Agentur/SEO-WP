<?php
/**
 * Handling for author, date, search, 404 and attachment archives.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

use WP_Post;

/**
 * Search and 404 are already noindexed by WordPress core (see Meta\Robots)
 * and need nothing extra here. This class covers what core leaves open:
 * optionally disabling thin author/date archives, and — per the
 * "attachment_handling" setting — either redirecting attachment pages to
 * their parent (default) or leaving them reachable but noindexed
 * (Meta\Robots reads the same setting).
 */
final class SpecialArchives {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the template_redirect handling.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ) );
	}

	/**
	 * Redirects away from a disabled archive type, or (when configured)
	 * an attachment page.
	 */
	public function maybe_redirect(): void {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( is_author() && ! empty( $settings['disable_author_archives'] ) ) {
			$this->redirect( home_url( '/' ) );
			return;
		}

		if ( is_date() && ! empty( $settings['disable_date_archives'] ) ) {
			$this->redirect( home_url( '/' ) );
			return;
		}

		$attachment_handling = isset( $settings['attachment_handling'] ) ? (string) $settings['attachment_handling'] : 'redirect';

		if ( is_attachment() && 'redirect' === $attachment_handling ) {
			$this->redirect_attachment();
		}
	}

	/**
	 * Redirects the attachment page to its parent post, or to the file
	 * itself when it has none.
	 */
	private function redirect_attachment(): void {
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$target = $post->post_parent > 0 ? get_permalink( $post->post_parent ) : wp_get_attachment_url( $post->ID );

		if ( is_string( $target ) && '' !== $target ) {
			$this->redirect( $target );
		}
	}

	/**
	 * Issues a permanent redirect and stops execution.
	 *
	 * @param string $url Target URL.
	 */
	private function redirect( string $url ): void {
		wp_safe_redirect( $url, 301 );
		exit;
	}
}

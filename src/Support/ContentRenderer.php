<?php
/**
 * Renders a post's content exactly as the front end would, outside the
 * main query.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

use WP_Post;

/**
 * Runs post_content through the same 'the_content' filters WordPress
 * applies on the front end. Page builders (Divi, WPBakery, and others)
 * store their own shortcodes or block markup in post_content rather than
 * literal HTML — images, links, whatever a scanner is looking for only
 * exist in the output once those filters expand it. Reading raw
 * post_content would silently miss all of it. Used by anything that needs
 * to inspect a post's real rendered output outside of the main loop
 * (image sitemaps, link audits), where there is no natural "current post"
 * for builder filters that read the global $post to fall back on. The
 * main query is swapped for a query on that post for the duration, so
 * filters that depend on the request context (is_page_template(),
 * is_singular(), in_the_loop()) behave as they do on the post's own page.
 */
final class ContentRenderer {

	/**
	 * Renders a post's content.
	 *
	 * @param WP_Post $post Post to render.
	 */
	public static function render( WP_Post $post ): string {
		$original_post  = $GLOBALS['post'] ?? null;
		$original_query = $GLOBALS['wp_query'] ?? null;
		$original_main  = $GLOBALS['wp_the_query'] ?? null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- required so builder 'the_content' filters that read global $post see the right one.
		setup_postdata( $post );

		// Themes and plugins decide inside their 'the_content' filters from the
		// main query — is_page_template(), is_singular(), in_the_loop() — and a
		// theme that rewrites asset paths or expands shortcodes only for its own
		// templates does nothing on a sitemap request, where the main query is
		// not this post. Rendering inside a query for this very post gives them
		// the same answers as on the post's own page.
		$query                   = new \WP_Query(
			array(
				'p'             => $post->ID,
				'post_type'     => $post->post_type,
				'post_status'   => $post->post_status,
				'no_found_rows' => true,
			)
		);
		$query->in_the_loop      = true;
		$GLOBALS['wp_query']     = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in the finally block below.
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in the finally block below.

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WP core's own 'the_content' filter to expand builder shortcodes/blocks, not defining a hook of our own.
			return (string) apply_filters( 'the_content', $post->post_content );
		} finally {
			$GLOBALS['wp_query']     = $original_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring, not overriding.
			$GLOBALS['wp_the_query'] = $original_main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring, not overriding.

			// A third-party 'the_content' callback (a page builder, another
			// plugin) throwing must not leave $GLOBALS['post'] pointed at
			// the wrong post for the rest of this request — this method
			// runs in scanning loops (image/video sitemap, link audit)
			// where that would taint every post processed afterward.
			if ( $original_post instanceof WP_Post ) {
				$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring, not overriding.
				setup_postdata( $original_post );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
		}
	}
}

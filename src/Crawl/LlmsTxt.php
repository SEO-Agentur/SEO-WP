<?php
/**
 * /llms.txt (and optional /llms-full.txt) output.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

use WP_Query;

/**
 * Renders a virtual /llms.txt (Markdown overview of the site, enabled by
 * default) and an optional, richer /llms-full.txt (includes excerpts,
 * opt-in), generic across any public, non-hierarchical post type.
 */
final class LlmsTxt {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Post meta key marking a post as excluded from indexing.
	 */
	private const META_NOINDEX = '_seo_wp_robots_noindex';

	/**
	 * Maximum items listed per post type.
	 */
	private const ITEMS_PER_TYPE = 20;

	/**
	 * Registers the rewrite rules, query var and request dispatcher.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Adds the /llms.txt and /llms-full.txt rewrite rules.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^llms\.txt$', 'index.php?seo_wp_llms=summary', 'top' );
		add_rewrite_rule( '^llms-full\.txt$', 'index.php?seo_wp_llms=full', 'top' );
	}

	/**
	 * Registers the seo_wp_llms query var.
	 *
	 * @param string[] $vars Query vars known to WordPress core.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'seo_wp_llms';

		return $vars;
	}

	/**
	 * Renders the requested variant, or 404s when it is disabled.
	 */
	public function maybe_render(): void {
		$mode = (string) get_query_var( 'seo_wp_llms' );
		if ( '' === $mode ) {
			return;
		}

		$full = 'full' === $mode;

		if ( ! $this->is_enabled( $full ) ) {
			status_header( 404 );
			nocache_headers();
			return;
		}

		header( 'Content-Type: text/markdown; charset=' . get_bloginfo( 'charset' ) );
		echo $this->render( $full ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() already escapes each value.
		exit;
	}

	/**
	 * Whether the requested variant is enabled: /llms.txt defaults on,
	 * /llms-full.txt is opt-in.
	 *
	 * @param bool $full Whether the full variant was requested.
	 */
	private function is_enabled( bool $full ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( $full ) {
			return ! empty( $settings['enable_llms_full_txt'] );
		}

		return ! isset( $settings['enable_llms_txt'] ) || ! empty( $settings['enable_llms_txt'] );
	}

	/**
	 * Builds the Markdown document.
	 *
	 * @param bool $full Whether to include each item's excerpt.
	 */
	private function render( bool $full ): string {
		$lines   = array();
		$lines[] = '# ' . esc_html( (string) get_bloginfo( 'name' ) );

		$tagline = (string) get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . esc_html( $tagline );
		}

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$items = $this->recent_items( $post_type->name );
			if ( array() === $items ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . esc_html( $post_type->labels->name );
			$lines[] = '';

			foreach ( $items as $item ) {
				$lines[] = '- [' . esc_html( $item['title'] ) . '](' . esc_url( $item['url'] ) . ')';
				if ( $full && '' !== $item['excerpt'] ) {
					$lines[] = '  ' . esc_html( $item['excerpt'] );
				}
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Fetches the most recently modified, indexable items of a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return array<int,array{title:string,url:string,excerpt:string}>
	 */
	private function recent_items( string $post_type ): array {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::ITEMS_PER_TYPE,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
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

		$items = array();

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$permalink = get_permalink( $post_id );

			if ( ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			$items[] = array(
				'title'   => get_the_title( $post_id ),
				'url'     => $permalink,
				'excerpt' => wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ),
			);
		}

		return $items;
	}
}

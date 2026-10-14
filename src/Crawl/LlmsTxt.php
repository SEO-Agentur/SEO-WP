<?php
/**
 * /llms.txt (and optional /llms-full.txt) output.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

use Seooptimierung\Toolkit\Meta\Robots;
use Seooptimierung\Toolkit\Meta\TitleDescription;
use WP_Post;
use WP_Query;

/**
 * Renders a virtual /llms.txt (Markdown index of the site: every entry is a
 * link plus the page's meta description; enabled by default) and an optional
 * /llms-full.txt (the same index, but each entry also carries the page's
 * plain-text body; opt-in), generic across any public post type.
 */
final class LlmsTxt {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Maximum items listed per post type.
	 */
	private const ITEMS_PER_TYPE = 20;

	/**
	 * Maximum characters of body text per entry in /llms-full.txt.
	 */
	private const FULL_BODY_LIMIT = 3000;

	/**
	 * Registers the rewrite rules, query var and request dispatcher.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Priority 1: see the identical comment in Sitemaps\Router::register()
		// — core's redirect_canonical() runs at the default priority (10)
		// and, registered earlier in the boot sequence, wins any tie there,
		// 301-redirecting this virtual route before this callback ever runs.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
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
			// Mark the main query as a real 404 as well: otherwise core's
			// redirect_canonical() (priority 10, runs next) still sees a
			// front-page-style request and 301s this URL to a trailing-slash
			// variant that then 404s anyway.
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		header( 'Content-Type: text/markdown; charset=' . get_bloginfo( 'charset' ) );
		echo $this->render( $full ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown response; render() strips all tags from every value.
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
	 * @param bool $full Whether to include each item's body text.
	 */
	private function render( bool $full ): string {
		$lines   = array();
		$lines[] = '# ' . self::plain( (string) get_bloginfo( 'name' ) );

		$tagline = self::plain( (string) get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$items = $this->recent_items( $post_type->name, $full );
			if ( array() === $items ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . self::plain( $post_type->labels->name );
			$lines[] = '';

			foreach ( $items as $item ) {
				$link = '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), $item['title'] ) . '](' . esc_url( $item['url'] ) . ')';
				if ( '' !== $item['description'] ) {
					$link .= ': ' . $item['description'];
				}
				$lines[] = $link;

				if ( $full && '' !== $item['body'] ) {
					$lines[] = '';
					$lines[] = '  ' . $item['body'];
					$lines[] = '';
				}
			}
		}

		$custom = $this->custom_content();
		if ( '' !== $custom ) {
			$lines[] = '';
			$lines[] = $custom;
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Reads the freely-editable custom content appended after the
	 * auto-generated list, so site owners aren't limited to what this
	 * plugin can infer on its own.
	 */
	private function custom_content(): string {
		$settings = get_option( self::OPTION_KEY, array() );

		return isset( $settings['llms_txt_custom'] ) ? trim( (string) $settings['llms_txt_custom'] ) : '';
	}

	/**
	 * Fetches the most recently modified, indexable items of a post type.
	 *
	 * @param string $post_type Post type slug.
	 * @param bool   $full      Whether to also build the body text.
	 * @return array<int,array{title:string,url:string,description:string,body:string}>
	 */
	private function recent_items( string $post_type, bool $full ): array {
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
				'update_post_meta_cache' => true,
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
			)
		);

		$titles = new TitleDescription();
		$items  = array();

		foreach ( $query->posts as $post_id ) {
			$post_id   = (int) $post_id;
			$post      = get_post( $post_id );
			$permalink = get_permalink( $post_id );

			if ( ! $post instanceof WP_Post || ! is_string( $permalink ) || '' === $permalink ) {
				continue;
			}

			// The page's own meta description (override or template);
			// falls back to the excerpt when that renders empty.
			$description = self::plain( $titles->resolve_description_for_post( $post ) );
			if ( '' === $description ) {
				$description = self::plain( (string) get_the_excerpt( $post ) );
			}

			$items[] = array(
				'title'       => self::plain( get_the_title( $post_id ) ),
				'url'         => $permalink,
				'description' => $description,
				'body'        => $full ? $this->body_text( $post ) : '',
			);
		}

		return $items;
	}

	/**
	 * Plain-text body of a post, shortcodes and markup removed (never
	 * executed, so no front-end side effects), capped in length.
	 *
	 * @param WP_Post $post Post to read.
	 */
	private function body_text( WP_Post $post ): string {
		$text = self::plain( strip_shortcodes( $post->post_content ) );

		if ( function_exists( 'mb_substr' ) && mb_strlen( $text ) > self::FULL_BODY_LIMIT ) {
			return rtrim( mb_substr( $text, 0, self::FULL_BODY_LIMIT ) ) . '…';
		}

		return $text;
	}

	/**
	 * Tags stripped, entities decoded (e.g. &#038; to &), whitespace
	 * collapsed to a single line — safe to embed in a Markdown line.
	 *
	 * @param string $value Raw value.
	 */
	private static function plain( string $value ): string {
		$value = wp_strip_all_tags( $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );

		return trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
	}
}

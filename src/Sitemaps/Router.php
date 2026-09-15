<?php
/**
 * Rewrite rules and request routing for the XML sitemaps.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Sitemaps;

use SeoWp\Wpml\Hreflang;

/**
 * Registers dynamic rewrite rules for sitemap endpoints and dispatches
 * matching requests to the right builder. No physical XML files are
 * written to the webroot; rendered output is cached in the object cache
 * and invalidated on the content changes that could affect it.
 */
final class Router {

	/**
	 * Object cache group for rendered sitemap XML.
	 */
	private const CACHE_GROUP = 'seo_wp_sitemaps';

	/**
	 * How long a rendered sitemap page stays cached.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers rewrite rules, query vars, the request dispatcher, the
	 * core sitemap toggle and cache invalidation.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'maybe_disable_core_sitemap' ) );

		add_action( 'save_post', array( $this, 'maybe_invalidate_cache_on_save' ) );
		add_action( 'deleted_post', array( $this, 'invalidate_cache' ) );
		add_action( 'edited_term', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_term', array( $this, 'invalidate_cache' ) );
	}

	/**
	 * Adds the rewrite rules mapping sitemap URLs to query vars.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?seo_wp_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap-news\.xml$', 'index.php?seo_wp_sitemap=news', 'top' );
		add_rewrite_rule(
			'^sitemap-posts-(.+)-([0-9]+)\.xml$',
			'index.php?seo_wp_sitemap=posts&seo_wp_sitemap_name=$matches[1]&seo_wp_sitemap_page=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^sitemap-taxonomies-(.+)-([0-9]+)\.xml$',
			'index.php?seo_wp_sitemap=taxonomies&seo_wp_sitemap_name=$matches[1]&seo_wp_sitemap_page=$matches[2]',
			'top'
		);
	}

	/**
	 * Registers the query vars used by the rewrite rules.
	 *
	 * @param string[] $vars Query vars known to WordPress core.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'seo_wp_sitemap';
		$vars[] = 'seo_wp_sitemap_name';
		$vars[] = 'seo_wp_sitemap_page';

		return $vars;
	}

	/**
	 * Renders and outputs the requested sitemap, or 404s when there is
	 * nothing to show.
	 */
	public function maybe_render(): void {
		$type = (string) get_query_var( 'seo_wp_sitemap' );
		if ( '' === $type ) {
			return;
		}

		$name = (string) get_query_var( 'seo_wp_sitemap_name' );
		$page = max( 1, (int) get_query_var( 'seo_wp_sitemap_page' ) );

		if ( ! $this->page_in_range( $type, $name, $page ) ) {
			status_header( 404 );
			nocache_headers();
			return;
		}

		$xml = $this->cached_render( $type, $name, $page );

		if ( null === $xml ) {
			status_header( 404 );
			nocache_headers();
			return;
		}

		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, self-escaping XML string from render().
		echo $xml;
		exit;
	}

	/**
	 * Disables WordPress core's own /wp-sitemap.xml unless a setting
	 * explicitly asks to keep it.
	 *
	 * @param bool $enabled Whether core's sitemaps are enabled.
	 */
	public function maybe_disable_core_sitemap( bool $enabled ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( isset( $settings['disable_core_sitemap'] ) && false === (bool) $settings['disable_core_sitemap'] ) {
			return $enabled;
		}

		return false;
	}

	/**
	 * Flushes every cached sitemap page, but only for a save that actually
	 * changes what a sitemap could show. save_post fires unconditionally
	 * for every wp_insert_post() call, including autosaves and revisions —
	 * without this guard, any editor's normal autosave loop would keep the
	 * whole sitemap cache perpetually cold.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function maybe_invalidate_cache_on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->invalidate_cache();
	}

	/**
	 * Flushes every cached sitemap page. Cheaper than tracking which
	 * specific pages a given post/term affects.
	 */
	public function invalidate_cache(): void {
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Whether a requested page number is within the sitemap's actual page
	 * count. Checked before ever touching the cache or a builder, so an
	 * out-of-range page number (e.g. an enumerated, ever-increasing value)
	 * can't be used to force a guaranteed cache-miss followed by an
	 * expensive, large-offset query — it 404s immediately instead.
	 * index/news are single-page sitemaps with no page concept, so any
	 * page other than 1 is out of range for them.
	 *
	 * @param string $type Sitemap type: index, news, posts or taxonomies.
	 * @param string $name Post type or taxonomy slug, when applicable.
	 * @param int    $page Requested page number.
	 */
	private function page_in_range( string $type, string $name, int $page ): bool {
		switch ( $type ) {
			case 'index':
			case 'news':
				return 1 === $page;
			case 'posts':
				return $page <= max( 1, ( new PostTypeSitemap() )->max_pages( $name ) );
			case 'taxonomies':
				return $page <= max( 1, ( new TaxonomySitemap() )->max_pages( $name ) );
			default:
				return false;
		}
	}

	/**
	 * Renders a sitemap through the object cache, including caching a
	 * "nothing to show" result so repeated bad requests stay cheap.
	 *
	 * @param string $type Sitemap type: index, news, posts or taxonomies.
	 * @param string $name Post type or taxonomy slug, when applicable.
	 * @param int    $page Requested page number.
	 */
	private function cached_render( string $type, string $name, int $page ): ?string {
		$cache_key = "sitemap:{$type}:{$name}:{$page}:" . $this->language();
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$xml = $this->render( $type, $name, $page );

		wp_cache_set( $cache_key, $xml, self::CACHE_GROUP, self::CACHE_TTL );

		return $xml;
	}

	/**
	 * Resolves the current language, when WPML or Polylang (or a
	 * compatible plugin) is active, so cache entries never mix URLs
	 * resolved under one language with a request for another — same
	 * pattern as Support\FragmentCache.
	 */
	private function language(): string {
		return Hreflang::current_language();
	}

	/**
	 * Dispatches to the builder for the requested sitemap type.
	 *
	 * @param string $type Sitemap type: index, news, posts or taxonomies.
	 * @param string $name Post type or taxonomy slug, when applicable.
	 * @param int    $page Requested page number.
	 */
	private function render( string $type, string $name, int $page ): ?string {
		switch ( $type ) {
			case 'index':
				return ( new Index() )->render();
			case 'news':
				return ( new NewsSitemap() )->render();
			case 'posts':
				return ( new PostTypeSitemap() )->render( $name, $page );
			case 'taxonomies':
				return ( new TaxonomySitemap() )->render( $name, $page );
			default:
				return null;
		}
	}
}

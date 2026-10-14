<?php
/**
 * Rewrite rules and request routing for the XML sitemaps.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

use Seooptimierung\Toolkit\Wpml\Hreflang;

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
		// Priority 1: WordPress core's own redirect_canonical() is hooked
		// on template_redirect at the default priority (10) very early in
		// the request lifecycle, before any plugin's init-triggered hook
		// registration — so a handler registered at the same priority
		// always loses that race and never gets to run at all. Without a
		// static front page configured, core's query for these unmatched
		// virtual routes falls back to is_home()/is_front_page(), and
		// redirect_canonical() 301s them to add a trailing slash before
		// this callback would ever see the request. Matches the same
		// priority Crawl\Redirects already uses for the same reason.
		add_action( 'template_redirect', array( $this, 'maybe_render' ), 1 );
		add_filter( 'wp_sitemaps_enabled', array( $this, 'maybe_disable_core_sitemap' ) );

		add_action( 'save_post', array( $this, 'maybe_invalidate_cache_on_save' ) );
		add_action( 'deleted_post', array( $this, 'invalidate_cache' ) );
		add_action( 'edited_term', array( $this, 'invalidate_cache' ) );
		add_action( 'delete_term', array( $this, 'invalidate_cache' ) );
		// Names, inclusion switches and the media sitemaps all live in the
		// settings; without this a rename or switch would show up in the
		// cached index only after the cache lifetime ran out.
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'invalidate_cache' ) );
	}

	/**
	 * Adds the rewrite rules mapping sitemap URLs to query vars.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^index_sitemap\.xml$', 'index.php?seo_wp_sitemap=index', 'top' );
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?seo_wp_sitemap=index', 'top' );
		// Configurable names: /sitemap_{name}.xml and /sitemap_{name}_{page}.xml,
		// resolved against SitemapNames in maybe_render().
		add_rewrite_rule(
			'^sitemap_([a-z0-9-]+(?:_[a-z0-9-]+)*?)(?:_([0-9]+))?\.xml$',
			'index.php?seo_wp_sitemap=named&seo_wp_sitemap_name=$matches[1]&seo_wp_sitemap_page=$matches[2]',
			'top'
		);
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

		if ( 'named' === $type ) {
			list( $type, $name ) = $this->resolve_named( $name );
		}

		$xml = '' === $type ? null : $this->cached_render( $type, $name, $page );

		if ( null === $xml ) {
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

		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-built, self-escaping XML string from render().
		echo $xml;
		exit;
	}

	/**
	 * Maps a /sitemap_{name}.xml name onto an internal sitemap type.
	 * "index" (/sitemap_index.xml) is an alias for the index; an unknown
	 * or switched-off name yields an empty type, answered with a 404.
	 *
	 * @param string $stem Name taken from the URL.
	 * @return array{0:string,1:string} Internal type and post type/taxonomy slug.
	 */
	private function resolve_named( string $stem ): array {
		if ( 'index' === $stem ) {
			return array( 'index', '' );
		}

		$entry = SitemapNames::resolve( $stem );
		if ( null === $entry || ! $entry['enabled'] ) {
			return array( '', '' );
		}

		switch ( $entry['kind'] ) {
			case SitemapNames::KIND_POST_TYPE:
				return array( 'posts', $entry['slug'] );
			case SitemapNames::KIND_TAXONOMY:
				return array( 'taxonomies', $entry['slug'] );
			case SitemapNames::KIND_IMAGES:
				return array( 'images', '' );
			case SitemapNames::KIND_VIDEO:
				return array( 'video', '' );
			case SitemapNames::KIND_NEWS:
				return array( 'news', '' );
			default:
				return array( '', '' );
		}
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
	 * count. Checked in cached_render() only on a cache miss (never before
	 * the cache lookup itself — this check is a builder call in its own
	 * right, e.g. a fresh WP_Query with SQL_CALC_FOUND_ROWS for a post-type
	 * sitemap, and running it unconditionally on every request would erase
	 * most of the point of caching), so an out-of-range page number (e.g.
	 * an enumerated, ever-increasing value) still can't be used to force a
	 * guaranteed cache-miss followed by an expensive, large-offset query —
	 * it 404s immediately instead, just as cheaply the second time since
	 * that "nothing to show" result is itself cached by cached_render().
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
			case 'images':
				return $page <= max( 1, ( new MediaSitemap() )->max_pages( SitemapNames::KIND_IMAGES ) );
			case 'video':
				return $page <= max( 1, ( new MediaSitemap() )->max_pages( SitemapNames::KIND_VIDEO ) );
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

		// Only reached on a miss: an out-of-range page still can't reach
		// render()'s own, potentially large-offset query, but a cache HIT
		// (the common case once a sitemap has been crawled) never pays for
		// this check at all.
		$xml = $this->page_in_range( $type, $name, $page ) ? $this->render( $type, $name, $page ) : null;

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
			case 'images':
				return ( new MediaSitemap() )->render( SitemapNames::KIND_IMAGES, $page );
			case 'video':
				return ( new MediaSitemap() )->render( SitemapNames::KIND_VIDEO, $page );
			default:
				return null;
		}
	}
}

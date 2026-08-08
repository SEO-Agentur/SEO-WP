<?php
/**
 * Opt-in performance/hardening toggles: unused scripts, revisions,
 * autosave, Heartbeat, comments, third-party embeds, WooCommerce assets,
 * XML-RPC, jQuery Migrate, the WordPress version, RSD/wlwmanifest/
 * shortlink/adjacent-post links, RSS feeds, self-pingbacks and the REST
 * API.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Performance;

/**
 * Every single feature here defaults to off and is gated by its own
 * setting, checked inside the callback rather than by conditionally
 * registering the hook — the same pattern IndexNow/GoogleIndexing use.
 * Nothing here is a WordPress core sanctioned "best practice" applied
 * automatically; these are opinionated, occasionally breaking changes
 * (disabling the REST API for anonymous visitors, for one) a site owner
 * opts into deliberately on Settings > Performance.
 */
final class Optimizations {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers every hook this module can act on; each callback below
	 * still checks its own setting before doing anything.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybe_disable_emojis' ) );
		add_action( 'init', array( $this, 'maybe_disable_embeds' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_dashicons' ), 100 );

		add_filter( 'wp_revisions_to_keep', array( $this, 'maybe_limit_post_revisions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_override_autosave_interval' ) );

		add_action( 'init', array( $this, 'maybe_disable_heartbeat' ), 1 );
		add_filter( 'heartbeat_settings', array( $this, 'maybe_adjust_heartbeat_frequency' ) );

		add_action( 'init', array( $this, 'maybe_disable_comments_support' ) );
		add_filter( 'comments_open', array( $this, 'maybe_force_comments_closed' ), 20, 2 );
		add_filter( 'pings_open', array( $this, 'maybe_force_comments_closed' ), 20, 2 );
		add_action( 'admin_menu', array( $this, 'maybe_remove_comments_admin_menu' ), 999 );
		add_action( 'admin_bar_menu', array( $this, 'maybe_remove_comments_admin_bar' ), 999 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_comments_admin_page' ) );
		add_filter( 'get_comment_author_link', array( $this, 'maybe_strip_comment_author_link' ), 10, 2 );

		add_action( 'wp_print_scripts', array( $this, 'maybe_dequeue_google_maps' ), 100 );

		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_wc_cart_fragments' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_dequeue_wc_assets_off_shop' ), 99 );

		add_filter( 'xmlrpc_enabled', array( $this, 'maybe_disable_xmlrpc' ) );
		add_action( 'wp_default_scripts', array( $this, 'maybe_remove_jquery_migrate' ) );

		add_action( 'init', array( $this, 'maybe_trim_head_output' ) );
		add_action( 'pre_ping', array( $this, 'maybe_remove_self_pingbacks' ) );
		add_filter( 'rest_authentication_errors', array( $this, 'maybe_restrict_rest_api' ) );
	}

	/**
	 * The plugin settings array.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a given boolean setting is turned on (off by default).
	 *
	 * @param string $key Settings key.
	 */
	private function enabled( string $key ): bool {
		return ! empty( $this->settings()[ $key ] );
	}

	// -- Emojis, embeds, Dashicons -----------------------------------

	/**
	 * Strips WordPress core's own emoji-support scripts, styles and
	 * TinyMCE plugin (front-end and wp-admin alike — an emoji typed into
	 * the block editor still renders as a real emoji character either
	 * way, this only removes the polyfill for browsers that lack native
	 * emoji glyphs).
	 */
	public function maybe_disable_emojis(): void {
		if ( ! $this->enabled( 'disable_emojis' ) ) {
			return;
		}

		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', array( $this, 'remove_emoji_tinymce_plugin' ) );
		add_filter( 'wp_resource_hints', array( $this, 'remove_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Removes the emoji TinyMCE plugin from the block editor's plugin list.
	 *
	 * @param mixed $plugins TinyMCE plugin list as passed by the filter.
	 * @return string[]
	 */
	public function remove_emoji_tinymce_plugin( $plugins ): array {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Removes the emoji CDN's dns-prefetch hint from the resource hints list.
	 *
	 * @param mixed  $urls          Resource hint URLs as passed by the filter.
	 * @param string $relation_type Hint relation, e.g. 'dns-prefetch'.
	 * @return array<int,mixed>
	 */
	public function remove_emoji_dns_prefetch( $urls, string $relation_type ): array {
		$urls = is_array( $urls ) ? $urls : array();

		if ( 'dns-prefetch' !== $relation_type ) {
			return $urls;
		}

		return array_values(
			array_filter(
				$urls,
				static function ( $url ): bool {
					return false === strpos( (string) $url, 'https://s.w.org/images/core/emoji' );
				}
			)
		);
	}

	/**
	 * Removes oEmbed discovery links, the REST oEmbed route and the
	 * wp-embed front-end script.
	 */
	public function maybe_disable_embeds(): void {
		if ( ! $this->enabled( 'disable_embeds' ) ) {
			return;
		}

		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );
		add_filter( 'embed_oembed_discover', '__return_false' );
		add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_wp_embed_script' ), 100 );
	}

	/**
	 * Dequeues wp-embed once maybe_disable_embeds() has confirmed the
	 * setting is on.
	 */
	public function dequeue_wp_embed_script(): void {
		wp_deregister_script( 'wp-embed' );
	}

	/**
	 * Removes Dashicons for visitors who aren't logged in (the admin bar,
	 * when shown to a logged-in visitor on the front end, still needs
	 * them).
	 */
	public function maybe_dequeue_dashicons(): void {
		if ( ! $this->enabled( 'disable_dashicons' ) || is_admin_bar_showing() ) {
			return;
		}

		wp_deregister_style( 'dashicons' );
	}

	// -- Revisions & autosave ------------------------------------------

	/**
	 * Overrides how many revisions WordPress keeps per post.
	 *
	 * @param int $default_num WordPress' own computed default.
	 */
	public function maybe_limit_post_revisions( int $default_num ): int {
		$limit = $this->post_revisions_limit();

		return null === $limit ? $default_num : $limit;
	}

	/**
	 * The configured revisions limit, or null to leave WordPress' own
	 * default alone.
	 */
	private function post_revisions_limit(): ?int {
		$raw = isset( $this->settings()['post_revisions_limit'] ) ? trim( (string) $this->settings()['post_revisions_limit'] ) : '';

		return '' === $raw ? null : max( 0, (int) $raw );
	}

	/**
	 * Overrides the post editor's autosave interval by re-assigning the
	 * value core already localized onto window.autosaveL10n — the
	 * AUTOSAVE_INTERVAL constant it's sourced from is defined by WordPress
	 * core before any plugin loads, so define()-ing it here would silently
	 * do nothing; this is the one point late enough for a plugin to still
	 * change it.
	 */
	public function maybe_override_autosave_interval(): void {
		$raw = isset( $this->settings()['autosave_interval'] ) ? trim( (string) $this->settings()['autosave_interval'] ) : '';
		if ( '' === $raw ) {
			return;
		}

		$seconds = max( 10, (int) $raw );
		wp_add_inline_script( 'autosave', 'window.autosaveL10n && ( window.autosaveL10n.autosaveInterval = ' . $seconds . ' );' );
	}

	// -- Heartbeat -------------------------------------------------------

	/**
	 * The configured Heartbeat mode, defaulting to 'default' (leave
	 * WordPress' own behavior alone) for any unrecognized value.
	 */
	private function heartbeat_mode(): string {
		$mode = isset( $this->settings()['heartbeat_mode'] ) ? (string) $this->settings()['heartbeat_mode'] : 'default';

		return in_array( $mode, array( 'disable_admin_except_editor', 'disable_frontend', 'disable_everywhere' ), true ) ? $mode : 'default';
	}

	/**
	 * Deregisters the heartbeat script per the configured mode. Run at
	 * init priority 1 — after core's own default-scripts registration,
	 * before anything enqueues it.
	 */
	public function maybe_disable_heartbeat(): void {
		$mode = $this->heartbeat_mode();

		if ( 'disable_everywhere' === $mode ) {
			wp_deregister_script( 'heartbeat' );
			return;
		}

		if ( 'disable_frontend' === $mode && ! is_admin() ) {
			wp_deregister_script( 'heartbeat' );
			return;
		}

		if ( 'disable_admin_except_editor' === $mode && is_admin() ) {
			global $pagenow;
			if ( ! in_array( $pagenow, array( 'post.php', 'post-new.php' ), true ) ) {
				wp_deregister_script( 'heartbeat' );
			}
		}
	}

	/**
	 * Overrides the Heartbeat polling interval when configured.
	 *
	 * @param array<string,mixed> $settings Heartbeat settings as passed by the filter.
	 * @return array<string,mixed>
	 */
	public function maybe_adjust_heartbeat_frequency( array $settings ): array {
		$raw = isset( $this->settings()['heartbeat_frequency'] ) ? trim( (string) $this->settings()['heartbeat_frequency'] ) : '';
		if ( '' === $raw ) {
			return $settings;
		}

		$settings['interval'] = max( 15, (int) $raw );

		return $settings;
	}

	// -- Comments --------------------------------------------------------

	/**
	 * Removes "comments"/"trackbacks" post type support sitewide.
	 */
	public function maybe_disable_comments_support(): void {
		if ( ! $this->enabled( 'disable_comments' ) ) {
			return;
		}

		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}
			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

	/**
	 * Forces comments/pings closed when comments are disabled sitewide.
	 *
	 * @param bool $open    Whether comments/pings are currently open.
	 * @param int  $post_id Post being checked (unused).
	 */
	public function maybe_force_comments_closed( bool $open, int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $post_id is part of the comments_open/pings_open hook signature.
		return $this->enabled( 'disable_comments' ) ? false : $open;
	}

	/**
	 * Hides the Comments admin menu item.
	 */
	public function maybe_remove_comments_admin_menu(): void {
		if ( $this->enabled( 'disable_comments' ) ) {
			remove_menu_page( 'edit-comments.php' );
		}
	}

	/**
	 * Removes the admin bar's comments bubble.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function maybe_remove_comments_admin_bar( $wp_admin_bar ): void {
		if ( $this->enabled( 'disable_comments' ) && $wp_admin_bar instanceof \WP_Admin_Bar ) {
			$wp_admin_bar->remove_node( 'comments' );
		}
	}

	/**
	 * Redirects a direct visit to edit-comments.php back to the dashboard
	 * — the menu item is hidden, but the URL itself would still work
	 * without this.
	 */
	public function maybe_redirect_comments_admin_page(): void {
		if ( ! $this->enabled( 'disable_comments' ) ) {
			return;
		}

		global $pagenow;
		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	/**
	 * Replaces the author name/link HTML with plain text when configured.
	 *
	 * @param string $existing_html The HTML core built for the author's name/link.
	 * @param string $author        Comment author's display name.
	 */
	public function maybe_strip_comment_author_link( string $existing_html, string $author ): string {
		return $this->enabled( 'remove_comment_author_links' ) ? esc_html( $author ) : $existing_html;
	}

	// -- Third-party embeds ----------------------------------------------

	/**
	 * Dequeues any registered script whose src points at Google's Maps
	 * API — hooked late (wp_print_scripts, priority 100) so it catches
	 * scripts enqueued by themes/page builders/widgets, not just this
	 * plugin's own.
	 */
	public function maybe_dequeue_google_maps(): void {
		if ( ! $this->enabled( 'disable_google_maps' ) || is_admin() ) {
			return;
		}

		foreach ( wp_scripts()->registered as $handle => $script ) {
			$src = isset( $script->src ) ? (string) $script->src : '';
			if ( '' !== $src && ( false !== strpos( $src, 'maps.googleapis.com' ) || false !== strpos( $src, 'maps.google.com' ) ) ) {
				wp_dequeue_script( $handle );
			}
		}
	}

	// -- WooCommerce -------------------------------------------------------

	/**
	 * Dequeues wc-cart-fragments, the wc-ajax=get_refreshed_fragments
	 * request's own script.
	 */
	public function maybe_dequeue_wc_cart_fragments(): void {
		if ( $this->enabled( 'disable_wc_cart_fragments' ) ) {
			wp_dequeue_script( 'wc-cart-fragments' );
		}
	}

	/**
	 * Dequeues WooCommerce's core scripts/styles outside shop/cart/
	 * checkout/account pages.
	 */
	public function maybe_dequeue_wc_assets_off_shop(): void {
		if ( ! $this->enabled( 'disable_wc_scripts_off_shop' ) || ! function_exists( 'is_woocommerce' ) ) {
			return;
		}

		$on_shop_page = is_woocommerce()
			|| ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() );

		if ( $on_shop_page ) {
			return;
		}

		wp_dequeue_style( 'woocommerce-general' );
		wp_dequeue_style( 'woocommerce-layout' );
		wp_dequeue_style( 'woocommerce-smallscreen' );
		wp_dequeue_script( 'woocommerce' );
		wp_dequeue_script( 'wc-add-to-cart' );
		wp_dequeue_script( 'wc-cart-fragments' );
		wp_dequeue_script( 'js-cookie' );
		wp_dequeue_script( 'jquery-blockui' );
	}

	// -- XML-RPC, jQuery Migrate ------------------------------------------

	/**
	 * Disables XML-RPC when configured.
	 *
	 * @param mixed $enabled Whether XML-RPC is currently enabled, as passed by the filter.
	 */
	public function maybe_disable_xmlrpc( $enabled ) {
		return $this->enabled( 'disable_xmlrpc' ) ? false : $enabled;
	}

	/**
	 * Drops jquery-migrate from jQuery's dependency list on the front end.
	 *
	 * @param \WP_Scripts $scripts Script registry, as passed by wp_default_scripts.
	 */
	public function maybe_remove_jquery_migrate( $scripts ): void {
		if ( ! $this->enabled( 'remove_jquery_migrate' ) || is_admin() || ! $scripts instanceof \WP_Scripts ) {
			return;
		}

		if ( ! isset( $scripts->registered['jquery'] ) ) {
			return;
		}

		$jquery = $scripts->registered['jquery'];
		if ( $jquery->deps ) {
			$jquery->deps = array_diff( $jquery->deps, array( 'jquery-migrate' ) );
		}
	}

	// -- Head/header trimming, feeds, pingbacks, REST ---------------------

	/**
	 * Groups every independent one-line wp_head/template_redirect removal
	 * together: WP version, RSD link, wlwmanifest link, shortlink,
	 * adjacent-post rel links, feed auto-discovery links, REST API
	 * discovery links, and (if configured) killing feed requests
	 * entirely.
	 */
	public function maybe_trim_head_output(): void {
		if ( $this->enabled( 'remove_wp_version' ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( $this->enabled( 'remove_rsd_link' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( $this->enabled( 'remove_shortlink' ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header' );
		}

		if ( $this->enabled( 'remove_wlwmanifest_link' ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( $this->enabled( 'remove_adjacent_posts_links' ) ) {
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		}

		if ( $this->enabled( 'remove_rss_feed_links' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( $this->enabled( 'remove_rest_api_links' ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
			remove_action( 'xmlrpc_rsd_apis', 'rest_output_rsd' );
		}

		if ( $this->enabled( 'disable_rss_feeds' ) ) {
			foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ) as $hook ) {
				add_action( $hook, array( $this, 'die_no_feed' ), 1 );
			}
		}
	}

	/**
	 * Short-circuits a feed request when RSS feeds are disabled.
	 */
	public function die_no_feed(): void {
		wp_die(
			esc_html__( 'No feed available, please visit our homepage.', 'seo-wp' ),
			'',
			array( 'response' => 404 )
		);
	}

	/**
	 * Strips links to this same site out of a post's own outgoing
	 * pingback list before WordPress pings them.
	 *
	 * @param array<int,string> $links Outgoing links collected for pinging, by reference.
	 */
	public function maybe_remove_self_pingbacks( array &$links ): void {
		if ( ! $this->enabled( 'disable_self_pingbacks' ) ) {
			return;
		}

		$home = home_url();
		foreach ( $links as $index => $link ) {
			if ( 0 === strpos( (string) $link, $home ) ) {
				unset( $links[ $index ] );
			}
		}
	}

	/**
	 * Rejects anonymous REST API requests when configured; logged-in
	 * users, and requests another filter already errored, are untouched.
	 *
	 * @param mixed $result Current authentication result, as passed by the filter.
	 * @return mixed
	 */
	public function maybe_restrict_rest_api( $result ) {
		if ( ! $this->enabled( 'restrict_rest_api' ) || is_wp_error( $result ) || is_user_logged_in() ) {
			return $result;
		}

		return new \WP_Error(
			'seo_wp_rest_not_logged_in',
			__( 'The REST API is restricted to logged-in users on this site.', 'seo-wp' ),
			array( 'status' => 401 )
		);
	}
}

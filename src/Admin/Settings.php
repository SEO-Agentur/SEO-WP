<?php
/**
 * Plugin settings screen.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Crawl\Verification;
use Seooptimierung\Toolkit\Performance\Htaccess;
use Seooptimierung\Toolkit\Performance\ResourceHintScanner;
use Seooptimierung\Toolkit\Performance\SecurityHeaderConfig;
use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Schema\LocalBusinessTypes;
use Seooptimierung\Toolkit\Sitemaps\SitemapNames;
use Seooptimierung\Toolkit\Support\StagingDetector;
use Seooptimierung\Toolkit\Templates\Tokens;

/**
 * Registers the General, Content Types, Sitemaps and Crawl settings tabs,
 * all backed by the single seo_wp_settings option array.
 */
final class Settings {

	/**
	 * Option key holding the plugin's settings array.
	 */
	public const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Top-level admin menu / settings page slug.
	 */
	public const PAGE_SLUG = 'seooptimierung-toolkit';

	/**
	 * Tabs that also get their own entry in the admin menu (the same
	 * screen, opened on that tab): tab slug => menu label. Registered as
	 * PAGE_SLUG . '-' . tab.
	 *
	 * @return array<string,string>
	 */
	private static function dedicated_pages(): array {
		return array(
			'sitemaps' => __( 'Sitemaps', 'seooptimierung-toolkit' ),
			'security' => __( 'Security Headers', 'seooptimierung-toolkit' ),
			'cleanup'  => __( 'WordPress Cleanup', 'seooptimierung-toolkit' ),
		);
	}

	/**
	 * Capability required to view or change settings.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Checkbox fields belonging to each tab, used to correctly turn off
	 * an unchecked box even though unchecked checkboxes are absent from
	 * the submitted form data.
	 *
	 * @var array<string,string[]>
	 */
	private const CHECKBOX_FIELDS_BY_TAB = array(
		'general'     => array( 'breadcrumb_include_category', 'breadcrumb_link_current' ),
		'content'     => array(),
		'sitemaps'    => array( 'disable_core_sitemap', 'enable_news_sitemap', 'sitemap_include_video', 'sitemap_include_images', 'sitemap_include_hreflang' ),
		'crawl'       => array(
			'block_ai_crawlers',
			'enable_llms_txt',
			'enable_llms_full_txt',
			'disable_author_archives',
			'disable_date_archives',
			'staging_noindex',
			'auto_redirect_slug_change',
			'auto_update_internal_links',
		),
		'performance' => array(
			'defer_noncritical_css',
		),
		'cleanup'     => array(
			'disable_emojis',
			'disable_embeds',
			'disable_dashicons',
			'disable_block_library_css',
			'disable_global_styles',
			'remove_asset_versions',
			'remove_recent_comments_style',
			'strip_attachment_links',
			'remove_wp_version',
			'remove_rsd_link',
			'remove_wlwmanifest_link',
			'remove_shortlink',
			'remove_adjacent_posts_links',
			'remove_rss_feed_links',
			'remove_rest_api_links',
			'remove_x_pingback_header',
			'disable_rss_feeds',
			'disable_xmlrpc',
			'disable_self_pingbacks',
			'restrict_rest_api',
			'disable_application_passwords',
			'block_user_enumeration',
			'generic_login_errors',
			'disable_comments',
			'remove_comment_author_links',
			'disable_google_maps',
			'remove_jquery_migrate',
			'remove_dashboard_widgets',
			'disable_wc_cart_fragments',
			'disable_wc_scripts_off_shop',
		),
		'security'    => array(
			'sec_x_content_type_options',
			'sec_x_frame_options',
			'sec_referrer_policy',
			'sec_permissions_policy',
			'sec_hsts',
			'sec_hsts_subdomains',
			'sec_hsts_preload',
			'sec_csp',
			'sec_csp_report_only',
		),
		'server'      => array(
			'force_https',
			'enable_canonical_redirects',
			'enable_cors',
			'enable_modern_mime_types',
			'enable_gzip',
			'enable_browser_caching',
			'enable_brotli',
			'enable_hotlink_protection',
			'disable_directory_browsing',
		),
	);
	// Note: 'enable_indexnow' used to live in the 'crawl' list above; that
	// checkbox moved to its own form on IndexNowPage (Instant Indexing),
	// which saves it through a separate admin-post handler, not this
	// tab-based settings form. Leaving it registered here would zero it
	// out every time the unrelated Crawl tab is saved, since this array
	// is exactly how an *absent* checkbox gets recognized as "unchecked".

	/**
	 * Plain text fields, sanitized with sanitize_text_field().
	 *
	 * @var string[]
	 */
	private const TEXT_FIELDS = array(
		'separator',
		'breadcrumb_separator',
		'breadcrumb_home_label',
		'identity_name',
		'twitter_site',
		'twitter_creator',
		'verification_google',
		'verification_bing',
		'verification_baidu',
		'verification_pinterest',
		'verification_yandex',
		'cors_allowed_origin',
		'local_business_street_address',
		'local_business_locality',
		'local_business_region',
		'local_business_postal_code',
		'local_business_country',
		'local_business_phone',
		'local_business_price_range',
		'local_business_latitude',
		'local_business_longitude',
	);

	/**
	 * Fields holding a non-negative integer, or '' to mean "leave
	 * WordPress' own default alone" — sanitized to either '' or a
	 * clamped digit string.
	 *
	 * @var array<string,array{min:int,max:int}>
	 */
	private const INT_FIELDS = array(
		'post_revisions_limit' => array(
			'min' => 0,
			'max' => 100,
		),
		'autosave_interval'    => array(
			'min' => 10,
			'max' => 600,
		),
		'heartbeat_frequency'  => array(
			'min' => 15,
			'max' => 300,
		),
		'sec_hsts_max_age'     => array(
			'min' => 0,
			'max' => 63072000,
		),
	);

	/**
	 * Registers the admin menu and the settings option.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_icon_style' ) );
		add_action( 'admin_post_seo_wp_apply_htaccess', array( $this, 'handle_apply_htaccess' ) );
		add_action( 'wp_ajax_seo_wp_scan_resource_hints', array( $this, 'handle_scan_resource_hints_ajax' ) );

		// This form submits through WordPress core's own options.php, whose
		// permission check defaults to requiring real manage_options unless
		// told otherwise here. Every other settings screen in this plugin
		// (AiSettings, GoogleIndexingPage, IndexNowPage) instead runs its
		// own admin-post handler checking self::CAPABILITY directly, so
		// they already work for a role granted only that virtual capability
		// via RoleManager. Without this filter, delegating the "Settings"
		// area to a non-administrator role — the exact scenario Role
		// Manager exists for — would let that role reach this page but
		// fail to save it.
		add_filter( 'option_page_capability_' . self::OPTION_KEY, array( $this, 'option_page_capability' ) );
	}

	/**
	 * Tells options.php which capability actually gates this option group.
	 */
	public function option_page_capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Adds the top-level menu and its default (Settings) submenu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'Seooptimierung Toolkit', 'seooptimierung-toolkit' ),
			__( 'Seooptimierung Toolkit', 'seooptimierung-toolkit' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			plugins_url( 'assets/admin/icons/menu-icon.png', \Seooptimierung\Toolkit\FILE ),
			80
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'seooptimierung-toolkit' ),
			__( 'Settings', 'seooptimierung-toolkit' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		foreach ( self::dedicated_pages() as $tab => $label ) {
			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				self::CAPABILITY,
				self::PAGE_SLUG . '-' . $tab,
				array( $this, 'render_page' )
			);
		}
	}

	/**
	 * Registers the settings option with a custom, tab-aware sanitizer.
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_KEY,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Loads the shared admin stylesheet on this plugin's own pages only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION );
		wp_enqueue_script( 'seo-wp-resource-hint-scanner', plugins_url( 'assets/admin/resource-hint-scanner.js', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION, true );

		wp_localize_script(
			'seo-wp-resource-hint-scanner',
			'seoWpResourceHintScanner',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'seo_wp_scan_resource_hints' ),
				'i18n'    => array(
					'scanning'      => __( 'Scanning your home page and a few sample pages…', 'seooptimierung-toolkit' ),
					/* translators: %d: number of pages scanned. */
					'scanned'       => __( 'Scanned %d page(s).', 'seooptimierung-toolkit' ),
					'error'         => __( 'Something went wrong.', 'seooptimierung-toolkit' ),
					'none'          => __( 'No external resources found — nothing to suggest.', 'seooptimierung-toolkit' ),
					'colHost'       => __( 'Origin', 'seooptimierung-toolkit' ),
					'colLoads'      => __( 'What loads from it', 'seooptimierung-toolkit' ),
					'colPages'      => __( 'Pages', 'seooptimierung-toolkit' ),
					'colHint'       => __( 'Suggested hint', 'seooptimierung-toolkit' ),
					'alreadyHinted' => __( 'already in your hints', 'seooptimierung-toolkit' ),
					'addChecked'    => __( 'Add checked to Resource Hints', 'seooptimierung-toolkit' ),
					'advice'        => __( 'preconnect opens the connection early (use it for the few origins that block rendering: stylesheets, scripts, fonts); dns-prefetch only resolves the name and suits images and embeds; preload fetches a specific file (a font) before the browser would find it. Keep the list short — a handful of well-chosen hints beats many.', 'seooptimierung-toolkit' ),
				),
			)
		);
	}

	/**
	 * Attaches the top-level menu icon's hover-color swap as an inline
	 * style on WordPress core's own 'admin-menu' stylesheet — that handle
	 * is already enqueued unconditionally on every wp-admin screen (the
	 * sidebar itself is global), which this plugin's own admin.css is not
	 * (it only loads on this plugin's own pages), so this is the one place
	 * the rule needs to live to actually take effect everywhere the menu
	 * item is visible.
	 */
	public function enqueue_menu_icon_style(): void {
		// WordPress renders a URL icon as an <img> inside .wp-menu-image. Both
		// states are drawn here as a fixed 20px background instead, with that
		// <img> hidden — without an explicit background-size the 256px source
		// would otherwise be painted at its native size over the sidebar.
		$css = sprintf(
			'#toplevel_page_%1$s .wp-menu-image { opacity: 1; background: url(%2$s) center / 20px 20px no-repeat !important; }' .
			'#toplevel_page_%1$s .wp-menu-image img { display: none; }' .
			'#toplevel_page_%1$s:hover .wp-menu-image,' .
			'#toplevel_page_%1$s.wp-has-current-submenu .wp-menu-image,' .
			'#toplevel_page_%1$s.current .wp-menu-image { background-image: url(%3$s) !important; }',
			self::PAGE_SLUG,
			esc_url( plugins_url( 'assets/admin/icons/menu-icon.png', \Seooptimierung\Toolkit\FILE ) ),
			esc_url( plugins_url( 'assets/admin/icons/menu-icon-hover.png', \Seooptimierung\Toolkit\FILE ) )
		);

		wp_add_inline_style( 'admin-menu', $css );
	}

	/**
	 * Handles the "Scan for suggestions" click: fetches the site's own
	 * front page and returns suggested resource-hint lines. Never writes
	 * anything — the browser adds whichever suggestions the admin picks
	 * to the Resource Hints textarea for review before Save.
	 */
	public function handle_scan_resource_hints_ajax(): void {
		check_ajax_referer( 'seo_wp_scan_resource_hints', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$result = ResourceHintScanner::scan();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Merges submitted settings onto the existing option, so tabs not
	 * currently open in the browser keep their stored values.
	 *
	 * @param mixed $input Raw settings submitted with this request.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$existing = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		// Only a form posted to options.php carries untrusted values that need
		// this whitelist. The other screens (Google Indexing, Instant Indexing,
		// AI Settings, Image SEO, ...) save through their own admin-post
		// handlers, which build the complete array themselves and call
		// update_option(): running that through the whitelist silently dropped
		// every key this class doesn't manage — an enabled Google Indexing box
		// came back unchecked after saving.
		if ( 'options.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
			return $input;
		}

		$clean = $existing;

		foreach ( Verification::settings_keys() as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$input[ $key ] = Verification::extract_code( wp_unslash( (string) $input[ $key ] ) );
			}
		}

		foreach ( self::TEXT_FIELDS as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );
			}
		}

		foreach ( $input as $key => $value ) {
			if ( ! is_string( $key ) || ! is_string( $value ) ) {
				continue;
			}
			if ( 0 === strpos( $key, 'title_template' ) || 0 === strpos( $key, 'description_template' ) ) {
				$clean[ $key ] = Tokens::from_ui( sanitize_text_field( wp_unslash( $value ) ) );
			}
		}

		if ( array_key_exists( 'identity_type', $input ) ) {
			$clean['identity_type'] = in_array( $input['identity_type'], array( 'person', 'local_business' ), true )
				? $input['identity_type']
				: 'organization';
		}

		if ( array_key_exists( 'local_business_type', $input ) ) {
			$type                         = sanitize_text_field( wp_unslash( (string) $input['local_business_type'] ) );
			$clean['local_business_type'] = GraphBuilder::is_valid_schema_type( $type ) ? $type : 'LocalBusiness';
		}

		if ( array_key_exists( 'local_business_opening_hours', $input ) ) {
			$clean['local_business_opening_hours'] = sanitize_textarea_field( wp_unslash( (string) $input['local_business_opening_hours'] ) );
		}

		if ( array_key_exists( 'hreflang_x_default', $input ) ) {
			$clean['hreflang_x_default'] = sanitize_text_field( wp_unslash( (string) $input['hreflang_x_default'] ) );
		}

		if ( array_key_exists( 'robots_txt_custom', $input ) ) {
			$clean['robots_txt_custom'] = sanitize_textarea_field( wp_unslash( (string) $input['robots_txt_custom'] ) );
		}

		if ( array_key_exists( 'llms_txt_custom', $input ) ) {
			$clean['llms_txt_custom'] = sanitize_textarea_field( wp_unslash( (string) $input['llms_txt_custom'] ) );
		}

		if ( array_key_exists( 'critical_css', $input ) ) {
			$clean['critical_css'] = sanitize_textarea_field( wp_unslash( (string) $input['critical_css'] ) );
		}

		if ( array_key_exists( 'resource_hints', $input ) ) {
			$clean['resource_hints'] = sanitize_textarea_field( wp_unslash( (string) $input['resource_hints'] ) );
		}

		if ( array_key_exists( 'attachment_handling', $input ) ) {
			$clean['attachment_handling'] = in_array( $input['attachment_handling'], array( 'redirect', 'noindex', 'off' ), true )
				? $input['attachment_handling']
				: 'redirect';
		}

		if ( array_key_exists( 'coexistence_source', $input ) ) {
			$clean['coexistence_source'] = in_array( $input['coexistence_source'], array( 'yoast', 'rank_math' ), true )
				? $input['coexistence_source']
				: '';
		}

		if ( array_key_exists( 'heartbeat_mode', $input ) ) {
			$clean['heartbeat_mode'] = in_array( $input['heartbeat_mode'], array( 'default', 'disable_admin_except_editor', 'disable_frontend', 'disable_everywhere' ), true )
				? $input['heartbeat_mode']
				: 'default';
		}

		if ( array_key_exists( 'www_canonical', $input ) ) {
			$clean['www_canonical'] = in_array( $input['www_canonical'], array( 'off', 'www', 'non_www' ), true )
				? $input['www_canonical']
				: 'off';
		}

		if ( array_key_exists( 'trailing_slash', $input ) ) {
			$clean['trailing_slash'] = in_array( $input['trailing_slash'], array( 'off', 'add', 'remove' ), true )
				? $input['trailing_slash']
				: 'off';
		}

		foreach ( self::INT_FIELDS as $key => $bounds ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$raw           = trim( (string) $input[ $key ] );
			$clean[ $key ] = '' === $raw ? '' : (string) max( $bounds['min'], min( $bounds['max'], (int) $raw ) );
		}

		if ( array_key_exists( 'sec_x_frame_options_value', $input ) ) {
			$clean['sec_x_frame_options_value'] = in_array( $input['sec_x_frame_options_value'], SecurityHeaderConfig::FRAME_OPTIONS, true )
				? $input['sec_x_frame_options_value']
				: 'SAMEORIGIN';
		}

		if ( array_key_exists( 'sec_referrer_policy_value', $input ) ) {
			$clean['sec_referrer_policy_value'] = in_array( $input['sec_referrer_policy_value'], SecurityHeaderConfig::REFERRER_POLICIES, true )
				? $input['sec_referrer_policy_value']
				: 'strict-origin-when-cross-origin';
		}

		// Header values are written straight into the response: control
		// characters are stripped so a pasted value can never inject a
		// second header.
		foreach ( array( 'sec_permissions_policy_value', 'sec_csp_policy' ) as $header_key ) {
			if ( array_key_exists( $header_key, $input ) ) {
				$clean[ $header_key ] = substr( SecurityHeaderConfig::clean_value( wp_unslash( (string) $input[ $header_key ] ) ), 0, 4000 );
			}
		}

		foreach ( $input as $key => $value ) {
			if ( is_string( $key ) && is_string( $value ) && 0 === strpos( $key, 'sitemap_name_' ) ) {
				$stem = SitemapNames::sanitize_stem( wp_unslash( $value ) );
				if ( '' === $stem ) {
					unset( $clean[ $key ] );
				} else {
					$clean[ $key ] = $stem;
				}
			}
		}

		$tab           = isset( $input['_tab'] ) ? (string) $input['_tab'] : '';
		$checkbox_keys = 'sitemaps' === $tab ? $this->sitemap_checkbox_keys() : ( self::CHECKBOX_FIELDS_BY_TAB[ $tab ] ?? array() );

		foreach ( $checkbox_keys as $checkbox_key ) {
			$clean[ $checkbox_key ] = ! empty( $input[ $checkbox_key ] );
		}

		// Browsers only accept HSTS preloading with includeSubDomains and at
		// least a year of max-age; saving it any other way would just be
		// rejected by the preload list, so it is switched off with a notice.
		if ( 'security' === $tab && ! empty( $clean['sec_hsts_preload'] )
			&& ( empty( $clean['sec_hsts_subdomains'] ) || SecurityHeaderConfig::hsts_max_age( $clean ) < SecurityHeaderConfig::HSTS_PRELOAD_MIN_AGE ) ) {
			$clean['sec_hsts_preload'] = false;
			add_settings_error(
				self::OPTION_KEY,
				'seo_wp_hsts_preload',
				__( 'HSTS preload was switched off: it requires "includeSubDomains" and a max-age of at least 31536000 seconds (one year).', 'seooptimierung-toolkit' ),
				'warning'
			);
		}

		return $clean;
	}

	/**
	 * Checkbox keys for the Sitemaps tab: the static core-sitemap toggle
	 * plus one per-post-type and per-taxonomy inclusion toggle, discovered
	 * dynamically so WooCommerce's product/product_cat (and any other
	 * public post type or taxonomy) get one automatically.
	 *
	 * @return string[]
	 */
	private function sitemap_checkbox_keys(): array {
		$keys = self::CHECKBOX_FIELDS_BY_TAB['sitemaps'];

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			$keys[] = "sitemap_include_{$post_type}";
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			$keys[] = "sitemap_include_tax_{$taxonomy}";
		}

		return $keys;
	}

	/**
	 * Renders the settings page: tab navigation plus the active tab's
	 * fields.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs = array(
			'general'     => __( 'General', 'seooptimierung-toolkit' ),
			'content'     => __( 'Content Types', 'seooptimierung-toolkit' ),
			'sitemaps'    => __( 'Sitemaps', 'seooptimierung-toolkit' ),
			'crawl'       => __( 'Crawl', 'seooptimierung-toolkit' ),
			'performance' => __( 'Performance', 'seooptimierung-toolkit' ),
			'cleanup'     => __( 'WordPress Cleanup', 'seooptimierung-toolkit' ),
			'security'    => __( 'Security Headers', 'seooptimierung-toolkit' ),
			'server'      => __( 'Server', 'seooptimierung-toolkit' ),
		);

		$active_tab = $this->active_tab( $tabs );

		echo '<div class="wrap seo-wp-settings">';
		echo '<h1>' . esc_html__( 'Seooptimierung Toolkit Settings', 'seooptimierung-toolkit' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = $slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $this->tab_url( $slug ) ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION_KEY );
		printf( '<input type="hidden" name="%1$s[_tab]" value="%2$s" />', esc_attr( self::OPTION_KEY ), esc_attr( $active_tab ) );

		echo '<table class="form-table" role="presentation">';
		switch ( $active_tab ) {
			case 'content':
				$this->render_content_types_tab();
				break;
			case 'sitemaps':
				$this->render_sitemaps_tab();
				break;
			case 'crawl':
				$this->render_crawl_tab();
				break;
			case 'performance':
				$this->render_performance_tab();
				break;
			case 'cleanup':
				$this->render_cleanup_tab();
				break;
			case 'security':
				$this->render_security_tab();
				break;
			case 'server':
				$this->render_server_tab();
				break;
			default:
				$this->render_general_tab();
				break;
		}
		echo '</table>';

		submit_button();
		echo '</form>';

		if ( 'server' === $active_tab ) {
			$this->render_htaccess_section();
		}

		if ( 'security' === $active_tab ) {
			$this->render_security_check();
		}

		echo '</div>';
	}

	/**
	 * The tab to show: the dedicated menu page being viewed wins, then
	 * the "tab" query argument, then General.
	 *
	 * @param array<string,string> $tabs Tab slug => label.
	 */
	private function active_tab( array $tabs ): string {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.
		foreach ( array_keys( self::dedicated_pages() ) as $tab ) {
			if ( self::PAGE_SLUG . '-' . $tab === $page ) {
				return $tab;
			}
		}

		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.

		return isset( $tabs[ $requested ] ) ? $requested : 'general';
	}

	/**
	 * Admin URL of a tab: its own menu page when it has one, otherwise
	 * the main Settings page with a tab argument.
	 *
	 * @param string $tab Tab slug.
	 */
	private function tab_url( string $tab ): string {
		if ( isset( self::dedicated_pages()[ $tab ] ) ) {
			return add_query_arg( array( 'page' => self::PAGE_SLUG . '-' . $tab ), admin_url( 'admin.php' ) );
		}

		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the General tab: separator, home templates, site identity,
	 * Twitter handles.
	 */
	private function render_general_tab(): void {
		echo '<tr><td colspan="2"><p class="description"><strong>' . esc_html__( 'Coexistence with another SEO plugin', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'Seooptimierung Toolkit is meant to replace Yoast SEO or Rank Math, not run alongside them. If you need to keep one of them active anyway (e.g. for a feature Seooptimierung Toolkit doesn\'t have), set this to stop Seooptimierung Toolkit printing its own title/meta description/canonical/Open Graph/Twitter Card/schema output — leaving all of that to the other plugin, so you never get duplicate tags. The Bulk Editor\'s Title/Description/Canonical columns then read and write that plugin\'s own fields instead of Seooptimierung Toolkit\'s. Everything else (sitemaps, hreflang, redirects, 404 monitor, IndexNow, Link Audit, Duplicate Checker, Role Manager, Performance) is unaffected.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->select_field(
			'coexistence_source',
			__( 'Leave title/meta output to', 'seooptimierung-toolkit' ),
			array(
				''          => __( 'Seooptimierung Toolkit itself (default)', 'seooptimierung-toolkit' ),
				'yoast'     => __( 'Yoast SEO', 'seooptimierung-toolkit' ),
				'rank_math' => __( 'Rank Math', 'seooptimierung-toolkit' ),
			),
			''
		);

		$this->text_field( 'separator', __( 'Separator', 'seooptimierung-toolkit' ), '-' );
		$this->template_field( 'title_template_home', __( 'Home title template', 'seooptimierung-toolkit' ), '%sitename%' );
		$this->template_field( 'description_template_home', __( 'Home description template', 'seooptimierung-toolkit' ), '' );
		$this->select_field(
			'identity_type',
			__( 'Site represents', 'seooptimierung-toolkit' ),
			array(
				'organization'   => __( 'An organization', 'seooptimierung-toolkit' ),
				'person'         => __( 'A person', 'seooptimierung-toolkit' ),
				'local_business' => __( 'A local business', 'seooptimierung-toolkit' ),
			),
			'organization'
		);
		$this->text_field( 'identity_name', __( 'Organization/person/business name', 'seooptimierung-toolkit' ), (string) get_bloginfo( 'name' ) );
		$this->text_field( 'twitter_site', __( 'Twitter/X @handle for the site', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'twitter_creator', __( 'Default Twitter/X @handle for authors', 'seooptimierung-toolkit' ), '' );

		$this->section_heading(
			__( 'Breadcrumbs', 'seooptimierung-toolkit' ),
			__( 'Show the trail from the home page to the current page with the "Breadcrumbs" block, the [seooptimierung_breadcrumbs] shortcode, or seooptimierung_breadcrumbs() in your theme. The same trail is written into the structured data (BreadcrumbList).', 'seooptimierung-toolkit' )
		);
		$this->text_field( 'breadcrumb_home_label', __( 'Label of the first crumb', 'seooptimierung-toolkit' ), (string) get_bloginfo( 'name' ) );
		$this->text_field( 'breadcrumb_separator', __( 'Separator', 'seooptimierung-toolkit' ), '›' );
		$this->checkbox_field( 'breadcrumb_include_category', __( 'For posts and products, include the category (deepest one and its parents)', 'seooptimierung-toolkit' ), true );
		$this->checkbox_field( 'breadcrumb_link_current', __( 'Link the current page in the trail', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Local business details', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'only used when "Site represents" above is set to "A local business".', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->business_type_field();
		$this->text_field( 'local_business_street_address', __( 'Street address', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_locality', __( 'City', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_region', __( 'State/region', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_postal_code', __( 'Postal code', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_country', __( 'Country (ISO 3166-1 alpha-2, e.g. DE, CH, US)', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_phone', __( 'Telephone', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_price_range', __( 'Price range (e.g. $$ or €€)', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_latitude', __( 'Latitude', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'local_business_longitude', __( 'Longitude', 'seooptimierung-toolkit' ), '' );

		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Opening hours — one line per rule: comma-separated two-letter days, a space, then HH:MM-HH:MM. Example: "Mo,Tu,We,Th,Fr 09:00-18:00" and "Sa 10:00-14:00" on two lines.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->textarea_field( 'local_business_opening_hours', __( 'Opening hours', 'seooptimierung-toolkit' ) );
	}

	/**
	 * Renders the Content Types tab: title/description templates for
	 * every public post type and taxonomy, discovered dynamically.
	 */
	private function render_content_types_tab(): void {
		$this->render_content_types_overview();

		echo '<tr><td colspan="2"><hr /></td></tr>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$this->template_field(
				"title_template_{$post_type->name}",
				/* translators: %s: post type label. */
				sprintf( __( '%s title template', 'seooptimierung-toolkit' ), $post_type->labels->singular_name ),
				'%title% %sep% %sitename%'
			);
			$this->template_field(
				"description_template_{$post_type->name}",
				/* translators: %s: post type label. */
				sprintf( __( '%s description template', 'seooptimierung-toolkit' ), $post_type->labels->singular_name ),
				'%excerpt%'
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$this->template_field(
				"title_template_term_{$taxonomy->name}",
				/* translators: %s: taxonomy label. */
				sprintf( __( '%s title template', 'seooptimierung-toolkit' ), $taxonomy->labels->singular_name ),
				'%category% %sep% %sitename%'
			);
		}
	}

	/**
	 * Renders a compact, read-only summary of every currently configured
	 * title/description template across every level (post types, taxonomy
	 * terms), so the full picture is visible without scrolling through the
	 * editable fields below.
	 */
	private function render_content_types_overview(): void {
		echo '<tr><td colspan="2"><table class="widefat striped seo-wp-content-types-overview">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Type', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Title template', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Description template', 'seooptimierung-toolkit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$this->render_overview_row(
				$post_type->labels->name,
				$this->overview_value( "title_template_{$post_type->name}", '%title% %sep% %sitename%' ),
				$this->overview_value( "description_template_{$post_type->name}", '%excerpt%' )
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$this->render_overview_row(
				$taxonomy->labels->name,
				$this->overview_value( "title_template_term_{$taxonomy->name}", '%category% %sep% %sitename%' ),
				'—'
			);
		}

		echo '</tbody></table></td></tr>';
	}

	/**
	 * Reads a template setting and converts it to the %token% syntax shown
	 * in the UI, falling back to the built-in default when never saved.
	 *
	 * @param string $key      Settings key.
	 * @param string $fallback Default shown when the setting has never been saved.
	 */
	private function overview_value( string $key, string $fallback ): string {
		$stored = $this->option( $key );

		return '' !== $stored ? Tokens::to_ui( $stored ) : $fallback;
	}

	/**
	 * Renders one overview row.
	 *
	 * @param string $label                 Post type/taxonomy label.
	 * @param string $title_template        Title template, in %token% syntax.
	 * @param string $description_template Description template, in %token% syntax, or '—'.
	 */
	private function render_overview_row( string $label, string $title_template, string $description_template ): void {
		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td></tr>',
			esc_html( $label ),
			esc_html( $title_template ),
			esc_html( $description_template )
		);
	}

	/**
	 * Renders the Sitemaps tab: one row per sitemap with an on/off switch,
	 * an editable file name and a link, so every post type, taxonomy,
	 * the image/video sitemaps and the news sitemap can be switched and
	 * named individually.
	 */
	private function render_sitemaps_tab(): void {
		echo '<tr><td colspan="2"><p class="description">';
		printf(
			/* translators: 1: link to the sitemap index, 2: link to the legacy sitemap.xml alias. */
			esc_html__( 'Sitemap index: %1$s (also reachable as %2$s). Submit the index to Google Search Console and Bing Webmaster Tools — it lists every sitemap switched on below.', 'seooptimierung-toolkit' ),
			'<a href="' . esc_url( SitemapNames::index_url() ) . '" target="_blank" rel="noopener"><code>' . esc_html( SitemapNames::index_url() ) . '</code></a>',
			'<a href="' . esc_url( home_url( '/sitemap.xml' ) ) . '" target="_blank" rel="noopener"><code>/sitemap.xml</code></a>'
		);
		echo '</p></td></tr>';

		$this->checkbox_field(
			'disable_core_sitemap',
			__( 'Disable WordPress core\'s own /wp-sitemap.xml (this plugin\'s sitemaps replace it)', 'seooptimierung-toolkit' ),
			true
		);

		$this->checkbox_field(
			'sitemap_include_hreflang',
			__( 'Include hreflang alternates (xhtml:link) in sitemaps', 'seooptimierung-toolkit' ),
			true
		);
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'When WPML, Polylang or slim-hreflang provides alternate-language URLs, this plugin\'s sitemaps normally list them as xhtml:link entries alongside the per-page <link rel="alternate"> tags in the document head. Turn this off to have hreflang live only on the pages themselves — some setups prefer one single source of truth rather than two places search engines could theoretically disagree on.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		echo '<tr><td colspan="2"><hr />';
		echo '<table class="widefat striped seo-wp-sitemap-table"><thead><tr>';
		echo '<th style="width:70px;">' . esc_html__( 'On', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Sitemap', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'File name', 'seooptimierung-toolkit' ) . '</th>';
		echo '</tr></thead><tbody>';

		// The index itself: always on, fixed name.
		printf(
			'<tr><td>&#10003;</td><td><strong>%1$s</strong></td><td><code>index_sitemap.xml</code></td></tr>',
			esc_html__( 'Sitemap index', 'seooptimierung-toolkit' )
		);

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		foreach ( SitemapNames::entries() as $entry ) {
			$include_key = $this->sitemap_include_key( $entry['kind'], $entry['slug'] );
			$custom      = isset( $settings[ $entry['option_key'] ] ) ? (string) $settings[ $entry['option_key'] ] : '';

			printf(
				'<tr><td><input type="checkbox" id="%1$s" name="%2$s[%3$s]" value="1" %4$s /></td><td><label for="%1$s">%5$s</label></td><td><code>sitemap_</code><input type="text" name="%2$s[%6$s]" value="%7$s" placeholder="%8$s" class="regular-text" style="width:14em;" /><code>.xml</code> <a href="%9$s" target="_blank" rel="noopener">%10$s</a></td></tr>',
				esc_attr( $include_key ),
				esc_attr( self::OPTION_KEY ),
				esc_attr( $include_key ),
				checked( $entry['enabled'], true, false ),
				esc_html( $this->sitemap_row_label( $entry ) ),
				esc_attr( $entry['option_key'] ),
				esc_attr( $custom ),
				esc_attr( SitemapNames::default_stem( $entry['kind'], $entry['slug'] ) ),
				esc_url( SitemapNames::url( $entry['stem'] ) ),
				esc_html__( 'open', 'seooptimierung-toolkit' )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">';
		echo esc_html__( 'Leave a file name empty to use its default (shown greyed out). Names may contain lowercase letters, digits, "-" and "_"; a name that clashes with another sitemap is replaced by the default. Pages beyond the first one get a number appended automatically (sitemap_posts_2.xml).', 'seooptimierung-toolkit' );
		echo '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Images: switching the image sitemap on moves the image entries out of the page/post sitemaps into sitemap_images.xml; switching it off keeps them inside the page/post sitemaps. Videos: the video sitemap lists self-hosted <video poster> elements and YouTube embeds, but only with a real thumbnail (Vimeo currently doesn\'t qualify); switched off, no video entries are written anywhere.', 'seooptimierung-toolkit' );
		echo '</p>';
		echo '<p class="description">';
		echo esc_html__( 'Google News: a separate sitemap listing only article-like content (non-hierarchical public post types) published in the last 48 hours, capped at 1000 URLs. It disappears from the index automatically whenever nothing currently qualifies — Google News enrollment itself is a separate application.', 'seooptimierung-toolkit' );
		echo '</p>';
		echo '</td></tr>';
	}

	/**
	 * Checkbox settings key that switches one sitemap on or off.
	 *
	 * @param string $kind One of the SitemapNames::KIND_* constants.
	 * @param string $slug Post type or taxonomy slug ('' for images/video/news).
	 */
	private function sitemap_include_key( string $kind, string $slug ): string {
		switch ( $kind ) {
			case SitemapNames::KIND_POST_TYPE:
				return 'sitemap_include_' . $slug;
			case SitemapNames::KIND_TAXONOMY:
				return 'sitemap_include_tax_' . $slug;
			case SitemapNames::KIND_NEWS:
				return 'enable_news_sitemap';
			default:
				return 'sitemap_include_' . $kind;
		}
	}

	/**
	 * Human label for a sitemap row.
	 *
	 * @param array{kind:string,slug:string,label:string,stem:string,enabled:bool,option_key:string} $entry Sitemap entry.
	 */
	private function sitemap_row_label( array $entry ): string {
		switch ( $entry['kind'] ) {
			case SitemapNames::KIND_POST_TYPE:
				/* translators: %s: post type label, e.g. "Products". */
				return sprintf( __( '%s (content type)', 'seooptimierung-toolkit' ), $entry['label'] );
			case SitemapNames::KIND_TAXONOMY:
				/* translators: %s: taxonomy label, e.g. "Product categories". */
				return sprintf( __( '%s (taxonomy)', 'seooptimierung-toolkit' ), $entry['label'] );
			default:
				return $entry['label'];
		}
	}

	/**
	 * Renders a live status box: whether this site's own host looks like
	 * staging/development (Support\StagingDetector), and whether it's
	 * actually indexable right now — checking both this plugin's own
	 * staging_noindex override and WordPress core's own Settings >
	 * Reading > "Discourage search engines" toggle, since either alone
	 * noindexes the whole site and it's easy to forget one is on.
	 */
	private function render_staging_status(): void {
		$host               = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$looks_like_staging = StagingDetector::looks_like_staging_host( $host );
		$staging_override   = ! empty( $this->option_raw( 'staging_noindex' ) );
		$core_discourages   = '0' === (string) get_option( 'blog_public', '1' );
		$indexable          = ! $staging_override && ! $core_discourages;

		echo '<tr><td colspan="2">';
		echo '<table class="widefat striped seo-wp-staging-status">';
		echo '<tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s <code>%3$s</code></td></tr>',
			esc_html__( 'Detected environment', 'seooptimierung-toolkit' ),
			$looks_like_staging
				? '<span style="color:#b32d2e;">&#10003; ' . esc_html__( 'Looks like staging/development', 'seooptimierung-toolkit' ) . '</span>'
				: '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Looks like production', 'seooptimierung-toolkit' ) . '</span>',
			esc_html( $host )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td>%2$s</td></tr>',
			esc_html__( 'Currently indexable by search engines', 'seooptimierung-toolkit' ),
			$indexable
				? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Yes', 'seooptimierung-toolkit' ) . '</span>'
				: '<span style="color:#b32d2e;">&#10007; ' . esc_html(
					sprintf(
					/* translators: %s: reason the site is noindexed. */
						__( 'No — noindexed (%s)', 'seooptimierung-toolkit' ),
						$this->noindex_reason( $staging_override, $core_discourages )
					)
				) . '</span>'
		);

		echo '</tbody></table>';
		echo '</td></tr>';
	}

	/**
	 * Explains which of the two independent noindex sources is active,
	 * since a site owner fixing one without noticing the other would
	 * otherwise stay noindexed and not know why.
	 *
	 * @param bool $staging_override Whether staging_noindex is on.
	 * @param bool $core_discourages Whether WP core's "Discourage search engines" is on.
	 */
	private function noindex_reason( bool $staging_override, bool $core_discourages ): string {
		if ( $staging_override && $core_discourages ) {
			return __( 'this plugin\'s staging override, and WordPress\' own "Discourage search engines" setting', 'seooptimierung-toolkit' );
		}

		if ( $staging_override ) {
			return __( 'this plugin\'s staging override, above', 'seooptimierung-toolkit' );
		}

		return __( 'WordPress\' own Settings > Reading > "Discourage search engines"', 'seooptimierung-toolkit' );
	}

	/**
	 * Reads a single settings value without the StringTranslation
	 * pass-through text_field()/option() apply — used where the raw
	 * stored value (not a translatable template) is what's needed.
	 *
	 * @param string $key Settings key.
	 */
	private function option_raw( string $key ) {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) && isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Renders the Crawl tab: robots.txt, llms.txt, verification,
	 * archives, IndexNow.
	 */
	private function render_crawl_tab(): void {
		$this->render_staging_status();
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'A staging/development site is also automatically flagged with a dismissible admin notice suggesting this same setting — this checkbox is the same override, just reachable without waiting for that notice.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->checkbox_field( 'staging_noindex', __( 'Noindex this entire site (staging/development override) — overrides every other robots setting on every page', 'seooptimierung-toolkit' ), false );

		$this->textarea_field( 'robots_txt_custom', __( 'Custom robots.txt rules', 'seooptimierung-toolkit' ) );
		$this->checkbox_field( 'block_ai_crawlers', __( 'Block known AI training crawlers', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'enable_llms_txt', __( 'Enable /llms.txt (links plus each page\'s meta description)', 'seooptimierung-toolkit' ), true );
		$this->checkbox_field( 'enable_llms_full_txt', __( 'Enable /llms-full.txt (additionally includes each page\'s body text)', 'seooptimierung-toolkit' ), false );
		$this->textarea_field( 'llms_txt_custom', __( 'Custom llms.txt content (appended after the generated list)', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_google', __( 'Google verification code', 'seooptimierung-toolkit' ), __( 'Code or the whole meta tag', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_bing', __( 'Bing verification code', 'seooptimierung-toolkit' ), __( 'Code or the whole meta tag', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_baidu', __( 'Baidu verification code', 'seooptimierung-toolkit' ), __( 'Code or the whole meta tag', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_pinterest', __( 'Pinterest verification code', 'seooptimierung-toolkit' ), __( 'Code or the whole meta tag', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_yandex', __( 'Yandex verification code', 'seooptimierung-toolkit' ), __( 'Code or the whole meta tag', 'seooptimierung-toolkit' ) );
		$this->checkbox_field( 'auto_redirect_slug_change', __( 'When a published page\'s address changes, redirect the old address to the new one (301)', 'seooptimierung-toolkit' ), true );
		$this->checkbox_field( 'auto_update_internal_links', __( '...and change links to the old address in your content and menus to the new one', 'seooptimierung-toolkit' ), true );
		$this->checkbox_field( 'disable_author_archives', __( 'Disable author archives', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_date_archives', __( 'Disable date archives', 'seooptimierung-toolkit' ), false );
		$this->select_field(
			'attachment_handling',
			__( 'Attachment pages', 'seooptimierung-toolkit' ),
			array(
				'redirect' => __( 'Redirect to the parent post (recommended)', 'seooptimierung-toolkit' ),
				'noindex'  => __( 'Keep accessible, but noindex them', 'seooptimierung-toolkit' ),
				'off'      => __( 'Leave to WordPress core', 'seooptimierung-toolkit' ),
			),
			'redirect'
		);
		echo '<tr><th scope="row">' . esc_html__( 'IndexNow (Bing, Yandex, Seznam, Naver)', 'seooptimierung-toolkit' ) . '</th><td><p class="description">';
		printf(
			/* translators: %s: link to the Instant Indexing admin page. */
			esc_html__( 'Moved to the %s page — enable it, optionally set a custom API key, and submit URLs manually or in one click there.', 'seooptimierung-toolkit' ),
			sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'admin.php?page=seooptimierung-instant-indexing' ) ), esc_html__( 'Instant Indexing', 'seooptimierung-toolkit' ) )
		);
		echo '</p></td></tr>';
		$this->select_field(
			'hreflang_x_default',
			__( 'x-default hreflang', 'seooptimierung-toolkit' ),
			$this->x_default_options(),
			'default'
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Only takes effect when WPML, Polylang, or a compatible plugin (e.g. slim-hreflang) actually provides alternate-language URLs — with none active, no hreflang or x-default tags are output at all.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
	}

	/**
	 * Renders the "Business type" dropdown: every Schema.org LocalBusiness
	 * subtype, grouped. A type saved earlier that isn't in the list (it
	 * used to be a free-text field) stays selectable, so nothing is lost.
	 */
	private function business_type_field(): void {
		$current = $this->option( 'local_business_type' );
		$current = '' === $current ? 'LocalBusiness' : $current;
		$known   = LocalBusinessTypes::all();

		printf( '<tr><th scope="row"><label for="local_business_type">%1$s</label></th><td><select id="local_business_type" name="%2$s[local_business_type]">', esc_html__( 'Business type', 'seooptimierung-toolkit' ), esc_attr( self::OPTION_KEY ) );

		if ( ! isset( $known[ $current ] ) ) {
			printf( '<option value="%1$s" selected="selected">%1$s</option>', esc_attr( $current ) );
		}

		foreach ( LocalBusinessTypes::groups() as $group => $types ) {
			printf( '<optgroup label="%s">', esc_attr( $group ) );
			foreach ( $types as $type => $label ) {
				printf(
					'<option value="%1$s" %2$s>%3$s (%1$s)</option>',
					esc_attr( $type ),
					selected( $current, $type, false ),
					esc_html( $label )
				);
			}
			echo '</optgroup>';
		}

		echo '</select><p class="description">' . esc_html__( 'The Schema.org type written into your structured data (the name in brackets is the exact value). Pick the most specific one that fits; "Local business (generic)" if none does.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
	}

	/**
	 * Choices for the x-default hreflang setting: the translation
	 * plugin's default language, off, or one specific active language.
	 *
	 * @return array<string,string>
	 */
	private function x_default_options(): array {
		$options = array(
			'default' => __( 'WPML/Polylang\'s default language', 'seooptimierung-toolkit' ),
			'off'     => __( 'Off', 'seooptimierung-toolkit' ),
		);

		$languages = array();

		if ( function_exists( 'pll_languages_list' ) ) {
			$slugs = (array) pll_languages_list( array( 'fields' => 'slug' ) );
			$names = (array) pll_languages_list( array( 'fields' => 'name' ) );
			foreach ( $slugs as $index => $slug ) {
				$languages[ (string) $slug ] = (string) ( $names[ $index ] ?? $slug );
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		if ( has_filter( 'wpml_active_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $language ) {
				$languages[ (string) $code ] = (string) ( is_array( $language ) ? ( $language['native_name'] ?? $code ) : $code );
			}
		}

		foreach ( $languages as $code => $name ) {
			/* translators: 1: language name, 2: language code. */
			$options[ $code ] = sprintf( __( 'Always %1$s (%2$s)', 'seooptimierung-toolkit' ), $name, $code );
		}

		// A language code saved earlier stays selectable even while the
		// translation plugin that listed it is inactive.
		$stored = $this->option( 'hreflang_x_default' );
		if ( '' !== $stored && ! isset( $options[ $stored ] ) ) {
			$options[ $stored ] = $stored;
		}

		return $options;
	}

	/**
	 * Renders the Performance tab: manually-supplied critical CSS and an
	 * independent toggle to load every other stylesheet non-blocking.
	 */
	private function render_performance_tab(): void {
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'This plugin cannot auto-detect critical CSS — that requires actually rendering the page (real layout, real box positions), which no CSS parsing pass can do. Paste critical CSS from a tool of your choice, or write it by hand.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->textarea_field( 'critical_css', __( 'Critical CSS (inlined in <head>)', 'seooptimierung-toolkit' ) );

		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'Independent of the above: without critical CSS in place, deferring every other stylesheet will show a flash of unstyled content until they finish loading — enable this once you have critical CSS covering what visitors see immediately, or if you\'ve decided that tradeoff is acceptable for your site.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->checkbox_field( 'defer_noncritical_css', __( 'Load all other stylesheets non-blocking', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Resource hints', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'one per line: preconnect/dns-prefetch/preload/prefetch/prerender, a URL, and optional as=…, type=… and crossorigin — e.g. the two lines below cover a typical Google Fonts setup.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		printf(
			'<tr><td colspan="2"><pre style="margin:0 0 8px;">%s</pre></td></tr>',
			esc_html( "preconnect https://fonts.googleapis.com\npreconnect https://fonts.gstatic.com crossorigin\npreload https://example.com/font.woff2 as=font type=font/woff2 crossorigin" )
		);
		$this->textarea_field( 'resource_hints', __( 'Resource hints (output early in <head>)', 'seooptimierung-toolkit' ) );

		echo '<tr><td colspan="2">';
		echo '<button type="button" class="button seo-wp-scan-resource-hints">' . esc_html__( 'Scan site for suggestions', 'seooptimierung-toolkit' ) . '</button> ';
		echo '<span class="seo-wp-resource-hint-scan-status"></span>';
		echo '<p class="description">' . esc_html__( 'Loads your home page and the newest post of a few content types, plus the stylesheets they use, and lists every external origin they pull scripts, styles, fonts, images, embeds and media from — with how often, on how many pages, and whether a hint already exists. Pick which suggested hints to add; nothing is inserted or saved automatically.', 'seooptimierung-toolkit' ) . '</p>';
		echo '<div class="seo-wp-resource-hint-suggestions"></div>';
		echo '</td></tr>';

		echo '<tr><td colspan="2"><hr /><p class="description">';
		printf(
			/* translators: %s: link to the WordPress Cleanup page. */
			esc_html__( 'Emoji, embeds, Dashicons, revisions, Heartbeat, comments and the other "unused WordPress features" switches now have their own page: %s.', 'seooptimierung-toolkit' ),
			'<a href="' . esc_url( $this->tab_url( 'cleanup' ) ) . '">' . esc_html__( 'WordPress Cleanup', 'seooptimierung-toolkit' ) . '</a>'
		);
		echo '</p></td></tr>';
	}

	/**
	 * Renders one checkbox followed by a help line saying when the
	 * feature is still needed.
	 *
	 * @param string $key   Settings key.
	 * @param string $label Checkbox label.
	 * @param string $help  "Only needed if ..." explanation.
	 */
	private function checkbox_with_help( string $key, string $label, string $help ): void {
		$this->checkbox_field( $key, $label, false );
		echo '<tr><td colspan="2"><p class="description">' . esc_html( $help ) . '</p></td></tr>';
	}

	/**
	 * Renders a section heading row with an optional intro line.
	 *
	 * @param string $title Section title.
	 * @param string $intro Optional intro text.
	 */
	private function section_heading( string $title, string $intro = '' ): void {
		echo '<tr><td colspan="2"><hr /><h3 style="margin:1em 0 .3em;">' . esc_html( $title ) . '</h3>';
		if ( '' !== $intro ) {
			echo '<p class="description">' . esc_html( $intro ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Renders the WordPress Cleanup tab: everything WordPress loads or
	 * exposes by default that many sites never use. Nothing is switched
	 * off by default; each switch says when the feature is still needed.
	 */
	private function render_cleanup_tab(): void {
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'WordPress ships with features that many sites never use but that still add markup, requests or attack surface. Every switch below is off by default — turn on only what your site genuinely does without, and read the "only needed if" note first.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->section_heading( __( 'Page source (<head> and headers)', 'seooptimierung-toolkit' ), __( 'Invisible clutter that crawlers and visitors never need.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_wp_version', __( 'Remove the WordPress version (generator tag)', 'seooptimierung-toolkit' ), __( 'Harmless to remove. The version can still leak through "?ver=" asset URLs unless you also strip those below.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_rsd_link', __( 'Remove the RSD (Really Simple Discovery) link', 'seooptimierung-toolkit' ), __( 'Only needed if you publish with an old desktop blogging client or XML-RPC based tool.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_wlwmanifest_link', __( 'Remove the wlwmanifest (Windows Live Writer) link', 'seooptimierung-toolkit' ), __( 'Windows Live Writer is discontinued; there is practically no case where this is still needed.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_shortlink', __( 'Remove the shortlink (<head> tag and Link header)', 'seooptimierung-toolkit' ), __( 'Only needed if you or a tool relies on the "?p=123" short URL of posts.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_adjacent_posts_links', __( 'Remove rel="prev"/"next" links to neighbouring posts', 'seooptimierung-toolkit' ), __( 'Search engines and browsers no longer use these for ordinary posts.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_rss_feed_links', __( 'Remove RSS feed auto-discovery links', 'seooptimierung-toolkit' ), __( 'Only needed if visitors subscribe by pasting your site address into a feed reader; the feeds themselves keep working.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_rest_api_links', __( 'Remove REST API discovery links (<head> and HTTP headers)', 'seooptimierung-toolkit' ), __( 'Only needed if an app or tool finds your REST API by discovery; the API itself stays reachable.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_x_pingback_header', __( 'Remove the X-Pingback header', 'seooptimierung-toolkit' ), __( 'Only needed if other blogs should be able to send you pingbacks.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_embeds', __( 'Disable embeds (oEmbed discovery, the oEmbed JSON endpoint and wp-embed.js)', 'seooptimierung-toolkit' ), __( 'Only needed if other sites embed your posts as preview cards, or you paste links to your own posts into the editor and expect a card.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_recent_comments_style', __( 'Remove the inline style of the "Recent Comments" widget', 'seooptimierung-toolkit' ), __( 'Only matters if you use that widget and rely on its default spacing.', 'seooptimierung-toolkit' ) );

		$this->section_heading( __( 'Scripts and styles loaded on every page', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_emojis', __( 'Disable emoji scripts and styles', 'seooptimierung-toolkit' ), __( 'Modern browsers draw emoji natively; only very old browsers lose the emoji images.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_dashicons', __( 'Disable Dashicons for visitors who aren\'t logged in', 'seooptimierung-toolkit' ), __( 'Only needed if your theme or a plugin shows Dashicons icons on the public site.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_block_library_css', __( 'Remove the block editor\'s front-end stylesheets (wp-block-library)', 'seooptimierung-toolkit' ), __( 'Only for classic themes whose content does not use block styling. Block themes and block-built pages need these styles — test before enabling.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_global_styles', __( 'Remove inline "global styles" and SVG duotone filters', 'seooptimierung-toolkit' ), __( 'Only for classic themes without theme.json. Block themes build their whole look from these styles — leave off there.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_jquery_migrate', __( 'Remove jQuery Migrate', 'seooptimierung-toolkit' ), __( 'Only needed if an old theme or plugin still uses jQuery functions that were removed from current jQuery. If something on the page breaks, switch it back off.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_asset_versions', __( 'Remove "?ver=" from script and style URLs', 'seooptimierung-toolkit' ), __( 'Some caches and CDNs then store assets more aggressively, but browsers also no longer re-download a file automatically after a plugin or theme update — purge your cache after updates.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_google_maps', __( 'Block Google Maps scripts from loading', 'seooptimierung-toolkit' ), __( 'Only needed if a theme or plugin shows a Google map. Use it when a plugin loads the Maps API on pages that have no map.', 'seooptimierung-toolkit' ) );

		$this->section_heading( __( 'Images', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'strip_attachment_links', __( 'Don\'t link inserted images to their attachment page', 'seooptimierung-toolkit' ), __( 'Stops images in content from linking to a thin attachment page. Only needed if you actually want visitors to land on attachment pages.', 'seooptimierung-toolkit' ) );

		$this->section_heading( __( 'Features and endpoints', 'seooptimierung-toolkit' ), __( 'Interfaces WordPress exposes by default; several are common attack targets.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_xmlrpc', __( 'Disable XML-RPC', 'seooptimierung-toolkit' ), __( 'Only needed for Jetpack, some old mobile/desktop publishing apps and pingbacks.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_self_pingbacks', __( 'Disable self-pingbacks', 'seooptimierung-toolkit' ), __( 'Linking to your own posts no longer pings them. There is rarely a reason to want those pings.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_rss_feeds', __( 'Disable RSS feeds entirely (visitors get a message instead)', 'seooptimierung-toolkit' ), __( 'Only needed if you want people or aggregators to subscribe to your content.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'restrict_rest_api', __( 'Restrict the REST API to logged-in users', 'seooptimierung-toolkit' ), __( 'Careful: many themes, page builders and the block editor itself rely on the REST API, including for visitors who aren\'t logged in — test thoroughly before relying on this in production. This also blocks the slim-hreflang companion plugin\'s multisite manifest route, so hreflang sync between your sites stops unless you exempt that route yourself.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_application_passwords', __( 'Disable application passwords', 'seooptimierung-toolkit' ), __( 'Only needed if an app, script or integration logs in to the REST API with an application password (e.g. a headless front end or an automation tool).', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'block_user_enumeration', __( 'Block username enumeration (?author=1 and the public REST user list)', 'seooptimierung-toolkit' ), __( 'Only needed if a front end or app reads author data from the public REST API. Author archive pages keep working at their normal URLs.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'generic_login_errors', __( 'Show one neutral login error message', 'seooptimierung-toolkit' ), __( 'Replaces "unknown username" / "wrong password" with a single message so the login form doesn\'t reveal which usernames exist.', 'seooptimierung-toolkit' ) );

		$this->section_heading( __( 'Comments', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'disable_comments', __( 'Disable comments sitewide (closes comments and pings, hides the Comments admin menu)', 'seooptimierung-toolkit' ), __( 'Only needed if your site has a discussion. Existing comments are kept in the database.', 'seooptimierung-toolkit' ) );
		$this->checkbox_with_help( 'remove_comment_author_links', __( 'Show comment author names as plain text, without a link to their URL', 'seooptimierung-toolkit' ), __( 'Takes away the main incentive for link-spam comments; commenters\' links are only ever useful on a very open community blog.', 'seooptimierung-toolkit' ) );

		$this->section_heading( __( 'Database and admin load', 'seooptimierung-toolkit' ) );
		$this->number_field( 'post_revisions_limit', __( 'Post revisions to keep', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default (unlimited). 0 disables revisions entirely — only do that if nobody ever needs to roll back an edit.', 'seooptimierung-toolkit' ), 0, 100 );
		$this->number_field( 'autosave_interval', __( 'Autosave interval (seconds)', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default (60 seconds).', 'seooptimierung-toolkit' ), 10, 600 );
		$this->select_field(
			'heartbeat_mode',
			__( 'Heartbeat API', 'seooptimierung-toolkit' ),
			array(
				'default'                     => __( 'Leave as-is', 'seooptimierung-toolkit' ),
				'disable_admin_except_editor' => __( 'Disable everywhere in wp-admin except the post editor', 'seooptimierung-toolkit' ),
				'disable_frontend'            => __( 'Disable on the public site', 'seooptimierung-toolkit' ),
				'disable_everywhere'          => __( 'Disable everywhere', 'seooptimierung-toolkit' ),
			),
			'default'
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'The Heartbeat polls admin-ajax.php on a timer for post locks, autosave and the dashboard widgets. Only needed in the editor: switching it off elsewhere saves server load, switching it off there too means no post-lock warning when two people edit the same post.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->number_field( 'heartbeat_frequency', __( 'Heartbeat frequency (seconds)', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default. Ignored when Heartbeat is fully disabled above.', 'seooptimierung-toolkit' ), 15, 300 );
		$this->checkbox_with_help( 'remove_dashboard_widgets', __( 'Remove the WordPress news, events and Quick Draft boxes from the dashboard', 'seooptimierung-toolkit' ), __( 'Only affects the admin dashboard; the boxes load external content on every dashboard visit.', 'seooptimierung-toolkit' ) );

		if ( class_exists( 'WooCommerce' ) ) {
			$this->section_heading( __( 'WooCommerce', 'seooptimierung-toolkit' ) );
			$this->checkbox_with_help( 'disable_wc_cart_fragments', __( 'Disable cart fragments (the wc-ajax=get_refreshed_fragments request)', 'seooptimierung-toolkit' ), __( 'Only needed if a mini-cart or cart counter must update live on every page without a reload.', 'seooptimierung-toolkit' ) );
			$this->checkbox_with_help( 'disable_wc_scripts_off_shop', __( 'Only load WooCommerce scripts/styles on shop, cart, checkout and account pages', 'seooptimierung-toolkit' ), __( 'Only needed if you use WooCommerce blocks or shortcodes (e.g. add-to-cart buttons) on other pages.', 'seooptimierung-toolkit' ) );
		}
	}

	/**
	 * Renders the Security Headers tab: one block per response header
	 * with its own switch and options, plus a live check of what the
	 * site really sends.
	 */
	private function render_security_tab(): void {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$legacy   = ! empty( $settings['enable_security_headers'] );

		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'Response headers that tell browsers how to treat your pages. Each one can be switched on individually. Use "Check headers now" below the Save button to see what your site actually sends — including headers sent twice because a security plugin or the web server sends the same ones.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->section_heading( 'X-Content-Type-Options' );
		$this->checkbox_field( 'sec_x_content_type_options', __( 'Send X-Content-Type-Options: nosniff', 'seooptimierung-toolkit' ), $legacy );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Stops browsers from guessing a file\'s type against the declared Content-Type. Safe to enable on practically every site.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->section_heading( 'Referrer-Policy' );
		$this->checkbox_field( 'sec_referrer_policy', __( 'Send the Referrer-Policy header', 'seooptimierung-toolkit' ), $legacy );
		$this->select_field(
			'sec_referrer_policy_value',
			__( 'Policy', 'seooptimierung-toolkit' ),
			array_combine( SecurityHeaderConfig::REFERRER_POLICIES, SecurityHeaderConfig::REFERRER_POLICIES ),
			'strict-origin-when-cross-origin'
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'How much of the page address is passed on when a visitor follows a link. "strict-origin-when-cross-origin" is the current browser default and a sensible choice; analytics or affiliate partners that need the full address on cross-site links may need a looser value.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->section_heading( 'X-Frame-Options' );
		$this->checkbox_field( 'sec_x_frame_options', __( 'Send the X-Frame-Options header', 'seooptimierung-toolkit' ), $legacy );
		$this->select_field(
			'sec_x_frame_options_value',
			__( 'Value', 'seooptimierung-toolkit' ),
			array(
				'SAMEORIGIN' => __( 'SAMEORIGIN (recommended)', 'seooptimierung-toolkit' ),
				'DENY'       => __( 'DENY', 'seooptimierung-toolkit' ),
			),
			'SAMEORIGIN'
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Protects against clickjacking. SAMEORIGIN lets your own site frame itself; DENY blocks all framing, which also stops your own page builder\'s or customizer\'s preview from working on the front end.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->section_heading( 'Permissions-Policy' );
		$this->checkbox_field( 'sec_permissions_policy', __( 'Send the Permissions-Policy header', 'seooptimierung-toolkit' ), $legacy );
		printf(
			'<tr><th scope="row"><label for="sec_permissions_policy_value">%1$s</label></th><td><input type="text" id="sec_permissions_policy_value" name="%2$s[sec_permissions_policy_value]" value="%3$s" class="large-text code" /></td></tr>',
			esc_html__( 'Policy', 'seooptimierung-toolkit' ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( array_key_exists( 'sec_permissions_policy_value', $settings ) ? (string) $settings['sec_permissions_policy_value'] : SecurityHeaderConfig::DEFAULT_PERMISSIONS_POLICY )
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Switches off browser features for your pages. The default turns off geolocation, camera and microphone — remove an entry if your site uses it (e.g. a store locator needs geolocation).', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->section_heading( 'HSTS (Strict-Transport-Security)' );
		$this->checkbox_field( 'sec_hsts', __( 'Send the Strict-Transport-Security header (only sent over HTTPS)', 'seooptimierung-toolkit' ), ! empty( $settings['enable_hsts'] ) );
		printf(
			'<tr><th scope="row"><label for="sec_hsts_max_age">%1$s</label></th><td><input type="number" id="sec_hsts_max_age" name="%2$s[sec_hsts_max_age]" value="%3$s" min="0" max="63072000" class="regular-text" /><p class="description">%4$s</p></td></tr>',
			esc_html__( 'max-age (seconds)', 'seooptimierung-toolkit' ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( (string) SecurityHeaderConfig::hsts_max_age( $settings ) ),
			esc_html__( 'How long browsers remember to use HTTPS only. 31536000 = one year (recommended once HTTPS works everywhere).', 'seooptimierung-toolkit' )
		);
		$this->checkbox_field( 'sec_hsts_subdomains', __( 'Include subdomains (includeSubDomains)', 'seooptimierung-toolkit' ), ! empty( $settings['hsts_include_subdomains'] ) );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Applies HSTS to every subdomain. Only enable it if every subdomain serves HTTPS.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->checkbox_field( 'sec_hsts_preload', __( 'Allow preloading (preload)', 'seooptimierung-toolkit' ), false );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Marks the domain for the browsers\' built-in HSTS list; needs includeSubDomains and a max-age of at least one year, and is hard to undo — submit the domain at hstspreload.org afterwards. HSTS itself is cached by browsers for the whole max-age, so make sure HTTPS works sitewide first.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->section_heading( 'Content-Security-Policy' );
		$this->checkbox_field( 'sec_csp', __( 'Send the Content-Security-Policy header (public pages only, never wp-admin or the login screen)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'sec_csp_report_only', __( 'Report-only mode (recommended while tuning)', 'seooptimierung-toolkit' ), true );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'In report-only mode the browser only logs violations in its developer console and blocks nothing. Switch it off once you have confirmed that nothing on your site is reported.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		printf(
			'<tr><th scope="row"><label for="sec_csp_policy">%1$s</label></th><td><textarea id="sec_csp_policy" name="%2$s[sec_csp_policy]" rows="7" class="large-text code">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Policy', 'seooptimierung-toolkit' ),
			esc_attr( self::OPTION_KEY ),
			esc_textarea( array_key_exists( 'sec_csp_policy', $settings ) && '' !== (string) $settings['sec_csp_policy'] ? (string) $settings['sec_csp_policy'] : SecurityHeaderConfig::DEFAULT_CSP ),
			esc_html__( 'The default suits a typical WordPress site (inline scripts/styles allowed, https images and embeds). Add the hosts you really load from, e.g. fonts.googleapis.com in style-src and fonts.gstatic.com in font-src for Google Fonts, or your analytics and video providers.', 'seooptimierung-toolkit' )
		);
	}

	/**
	 * Fetches the home page and shows which security headers actually
	 * arrive — run on demand from a nonce-protected link, never on a
	 * normal page load.
	 */
	private function render_security_check(): void {
		$check_url = wp_nonce_url( add_query_arg( 'seo_wp_check_headers', '1', $this->tab_url( 'security' ) ), 'seo_wp_check_headers' );

		echo '<p><a class="button" href="' . esc_url( $check_url ) . '">' . esc_html__( 'Check headers now', 'seooptimierung-toolkit' ) . '</a></p>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce is verified right below, together with the capability check already done by render_page().
		if ( ! isset( $_GET['seo_wp_check_headers'] ) || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'seo_wp_check_headers' ) ) {
			return;
		}

		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 10,
				'redirection' => 3,
			)
		);

		if ( is_wp_error( $response ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $response->get_error_message() ) . '</p></div>';
			return;
		}

		$received = array();
		foreach ( SecurityHeaderConfig::CHECKED_HEADERS as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( '' !== $value && array() !== $value ) {
				$received[ strtolower( $name ) ] = $value;
			}
		}

		echo '<table class="widefat striped" style="max-width:900px;"><thead><tr><th>' . esc_html__( 'Header', 'seooptimierung-toolkit' ) . '</th><th>' . esc_html__( 'Status', 'seooptimierung-toolkit' ) . '</th><th>' . esc_html__( 'Value sent', 'seooptimierung-toolkit' ) . '</th></tr></thead><tbody>';
		$settings = get_option( self::OPTION_KEY, array() );
		$expected = SecurityHeaderConfig::headers( is_array( $settings ) ? $settings : array(), 0 === strpos( home_url( '/' ), 'https://' ), true );

		foreach ( SecurityHeaderConfig::check_report( $received, $expected ) as $name => $row ) {
			switch ( $row['state'] ) {
				case 'ok':
					$status = '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'sent, as configured', 'seooptimierung-toolkit' ) . '</span>';
					break;
				case 'duplicate':
					$status = '<span style="color:#b32d2e;">&#9888; ' . esc_html__( 'sent more than once — another plugin or the server sends it too', 'seooptimierung-toolkit' ) . '</span>';
					break;
				case 'overridden':
					$status = '<span style="color:#b32d2e;">&#9888; ' . esc_html__( 'arrives with a different value than configured — a CDN, proxy or the web server replaces it', 'seooptimierung-toolkit' ) . '</span>';
					break;
				case 'missing':
					$status = '<span style="color:#b32d2e;">&#9888; ' . esc_html__( 'configured, but not arriving — a cache or the server strips it', 'seooptimierung-toolkit' ) . '</span>';
					break;
				case 'foreign':
					$status = '<span style="color:#996800;">&#9432; ' . esc_html__( 'sent, but not by this plugin (another plugin, the server or a CDN)', 'seooptimierung-toolkit' ) . '</span>';
					break;
				default:
					$status = '<span style="color:#646970;">&ndash; ' . esc_html__( 'not configured, not sent', 'seooptimierung-toolkit' ) . '</span>';
			}
			printf( '<tr><td><code>%1$s</code></td><td>%2$s</td><td><code>%3$s</code></td></tr>', esc_html( $name ), $status, esc_html( $row['value'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $status is assembled above from escaped strings and fixed markup.
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'The check requests your home page from the server itself; a CDN or caching layer in front of the site may add or remove headers that this request does or doesn\'t see. Only one of the two CSP rows is expected to be sent.', 'seooptimierung-toolkit' ) . '</p>';
	}

	/**
	 * Renders the Server tab: redirects/canonicalization, response
	 * headers, and — further down, outside the main settings form, see
	 * render_htaccess_section() — the .htaccess-backed toggles.
	 */
	private function render_server_tab(): void {
		echo '<tr><td colspan="2"><p class="description"><strong>' . esc_html__( 'Redirects & URL normalization', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		$this->checkbox_field( 'force_https', __( 'Redirect HTTP to HTTPS', 'seooptimierung-toolkit' ), false );
		$this->select_field(
			'www_canonical',
			__( 'WWW ↔ non-WWW', 'seooptimierung-toolkit' ),
			array(
				'off'     => __( 'Leave as-is', 'seooptimierung-toolkit' ),
				'www'     => __( 'Redirect to www.', 'seooptimierung-toolkit' ),
				'non_www' => __( 'Redirect to the bare domain (non-www)', 'seooptimierung-toolkit' ),
			),
			'off'
		);
		$this->select_field(
			'trailing_slash',
			__( 'Trailing slash', 'seooptimierung-toolkit' ),
			array(
				'off'    => __( 'Leave as-is', 'seooptimierung-toolkit' ),
				'add'    => __( 'Force a trailing slash', 'seooptimierung-toolkit' ),
				'remove' => __( 'Remove the trailing slash', 'seooptimierung-toolkit' ),
			),
			'off'
		);
		$this->checkbox_field( 'enable_canonical_redirects', __( 'Collapse duplicate slashes in the URL path', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Response headers', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		echo '<tr><td colspan="2"><p class="description">';
		printf(
			/* translators: %s: link to the Security Headers page. */
			esc_html__( 'X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, HSTS and the Content-Security-Policy are configured on their own page: %s.', 'seooptimierung-toolkit' ),
			'<a href="' . esc_url( $this->tab_url( 'security' ) ) . '">' . esc_html__( 'Security Headers', 'seooptimierung-toolkit' ) . '</a>'
		);
		echo '</p></td></tr>';
		$this->checkbox_field( 'enable_cors', __( 'Send Access-Control-Allow-Origin (CORS)', 'seooptimierung-toolkit' ), false );
		$this->text_field( 'cors_allowed_origin', __( 'Allowed origin', 'seooptimierung-toolkit' ), __( 'e.g. https://example.com — left blank, no CORS header is sent at all', 'seooptimierung-toolkit' ) );
		$this->checkbox_field( 'enable_modern_mime_types', __( 'Allow uploading WebP, AVIF, WOFF/WOFF2 and JSON files', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Gzip, Brotli, browser caching, hotlink protection & directory browsing', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'these need actual web-server configuration, not just a PHP hook — see below the Save button for how they\'re applied.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->checkbox_field( 'enable_gzip', __( 'Gzip compression', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'enable_brotli', __( 'Brotli compression (only takes effect if the server supports it)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'enable_browser_caching', __( 'Browser caching (Expires / Cache-Control) for static assets', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'enable_hotlink_protection', __( 'Hotlink protection for images', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_directory_browsing', __( 'Disable directory browsing (Options -Indexes)', 'seooptimierung-toolkit' ), false );
	}

	/**
	 * Renders the .htaccess apply/preview section below the Server tab's
	 * form — deliberately its own form/action, not folded into the
	 * regular settings save: writing to a file on disk is different
	 * enough from saving an option that it shouldn't happen silently as a
	 * side effect of an unrelated field edit on this tab.
	 */
	private function render_htaccess_section(): void {
		$htaccess = new Htaccess();

		echo '<h2>' . esc_html__( 'Apply to .htaccess', 'seooptimierung-toolkit' ) . '</h2>';

		if ( ! $htaccess->server_supports_htaccess() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This server doesn\'t look like Apache or LiteSpeed, so .htaccess has no effect here (this is normal on Nginx). Paste the rules below into your server\'s own configuration and reload it instead — this plugin can\'t do that part for you.', 'seooptimierung-toolkit' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Writes the rules below into a marked block in this site\'s .htaccess (only that block is ever touched), using the checkboxes above — save them first, then apply.', 'seooptimierung-toolkit' ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner reflecting a prior action's redirect, not a state-changing action itself.
		if ( isset( $_GET['htaccess_applied'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '.htaccess updated.', 'seooptimierung-toolkit' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		if ( isset( $_GET['htaccess_error'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner, value only ever originates from this class's own redirect.
				esc_html( sanitize_text_field( wp_unslash( (string) $_GET['htaccess_error'] ) ) )
			);
		}

		$rules = $htaccess->build_rules();
		printf(
			'<textarea readonly rows="12" class="large-text code">%s</textarea>',
			esc_textarea( '' !== $rules ? $rules : __( '(No .htaccess-backed toggle is currently enabled — save the checkboxes above first.)', 'seooptimierung-toolkit' ) )
		);

		if ( $htaccess->server_supports_htaccess() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="seo_wp_apply_htaccess" />';
			wp_nonce_field( 'seo_wp_apply_htaccess', 'seo_wp_apply_htaccess_nonce' );
			submit_button( __( 'Apply to .htaccess', 'seooptimierung-toolkit' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Handles the "Apply to .htaccess" button.
	 */
	public function handle_apply_htaccess(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) );
		}

		check_admin_referer( 'seo_wp_apply_htaccess', 'seo_wp_apply_htaccess_nonce' );

		$result = ( new Htaccess() )->write();

		$args = array(
			'page' => self::PAGE_SLUG,
			'tab'  => 'server',
		);

		if ( is_wp_error( $result ) ) {
			$args['htaccess_error'] = $result->get_error_message();
		} else {
			$args['htaccess_applied'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders a single-line text field row.
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $placeholder Placeholder shown when empty.
	 */
	private function text_field( string $key, string $label, string $placeholder ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $this->option( $key ) ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Renders a title/description template field, converting the stored
	 * internal {token} syntax to the %token% syntax shown in the UI.
	 *
	 * @param string $key                Settings key.
	 * @param string $label              Field label.
	 * @param string $default_placeholder Placeholder template shown when empty.
	 */
	private function template_field( string $key, string $label, string $default_placeholder ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="text" id="%1$s" name="%3$s[%4$s]" value="%5$s" placeholder="%6$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( Tokens::to_ui( $this->option( $key ) ) ),
			esc_attr( $default_placeholder )
		);
	}

	/**
	 * Renders a multi-line textarea field row.
	 *
	 * @param string $key   Settings key.
	 * @param string $label Field label.
	 */
	private function textarea_field( string $key, string $label ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%3$s[%4$s]" rows="5" class="large-text code">%5$s</textarea></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_textarea( $this->option( $key ) )
		);
	}

	/**
	 * Renders a checkbox field row.
	 *
	 * @param string $key     Settings key.
	 * @param string $label   Field label.
	 * @param bool   $fallback Value used when the setting has never been saved.
	 */
	private function checkbox_field( string $key, string $label, bool $fallback ): void {
		$settings = get_option( self::OPTION_KEY, array() );
		$checked  = array_key_exists( $key, $settings ) ? ! empty( $settings[ $key ] ) : $fallback;

		printf(
			'<tr><th scope="row">%2$s</th><td><label for="%1$s"><input type="checkbox" id="%1$s" name="%3$s[%4$s]" value="1" %5$s /> %2$s</label></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			checked( $checked, true, false )
		);
	}

	/**
	 * Renders a number field row that's allowed to be left empty (meaning
	 * "leave WordPress' own default alone" — see Settings::INT_FIELDS).
	 *
	 * @param string $key         Settings key.
	 * @param string $label       Field label.
	 * @param string $description Help text shown under the field.
	 * @param int    $min         Minimum accepted value.
	 * @param int    $max         Maximum accepted value.
	 */
	private function number_field( string $key, string $label, string $description, int $min, int $max ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="%3$s[%4$s]" value="%5$s" min="%6$d" max="%7$d" class="small-text" /><p class="description">%8$s</p></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( $this->option( $key ) ),
			absint( $min ),
			absint( $max ),
			esc_html( $description )
		);
	}

	/**
	 * Renders a select field row.
	 *
	 * @param string               $key     Settings key.
	 * @param string               $label   Field label.
	 * @param array<string,string> $options Value => label.
	 * @param string               $fallback Value used when the setting has never been saved.
	 */
	private function select_field( string $key, string $label, array $options, string $fallback ): void {
		$value = $this->option( $key );
		$value = '' === $value ? $fallback : $value;

		printf( '<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><select id="%1$s" name="%3$s[%4$s]">', esc_attr( $key ), esc_html( $label ), esc_attr( self::OPTION_KEY ), esc_attr( $key ) );
		foreach ( $options as $option_value => $option_label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option_value ), selected( $value, $option_value, false ), esc_html( $option_label ) );
		}
		echo '</select></td></tr>';
	}

	/**
	 * Reads a single settings value.
	 *
	 * @param string $key Settings key.
	 */
	private function option( string $key ): string {
		$settings = get_option( self::OPTION_KEY, array() );

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}
}

<?php
/**
 * Plugin settings screen.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Performance\Htaccess;
use Seooptimierung\Toolkit\Performance\ResourceHintScanner;
use Seooptimierung\Toolkit\Schema\GraphBuilder;
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
		'general'     => array(),
		'content'     => array(),
		'sitemaps'    => array( 'disable_core_sitemap', 'enable_news_sitemap', 'sitemap_include_video', 'sitemap_include_hreflang' ),
		'crawl'       => array(
			'block_ai_crawlers',
			'enable_llms_txt',
			'enable_llms_full_txt',
			'disable_author_archives',
			'disable_date_archives',
			'strip_attachment_links',
			'staging_noindex',
		),
		'performance' => array(
			'defer_noncritical_css',
			'disable_emojis',
			'disable_embeds',
			'disable_dashicons',
			'disable_comments',
			'remove_comment_author_links',
			'disable_google_maps',
			'disable_wc_cart_fragments',
			'disable_wc_scripts_off_shop',
			'disable_xmlrpc',
			'remove_jquery_migrate',
			'remove_wp_version',
			'remove_rsd_link',
			'remove_wlwmanifest_link',
			'remove_shortlink',
			'remove_adjacent_posts_links',
			'disable_rss_feeds',
			'remove_rss_feed_links',
			'disable_self_pingbacks',
			'restrict_rest_api',
			'remove_rest_api_links',
		),
		'server'      => array(
			'force_https',
			'enable_canonical_redirects',
			'enable_security_headers',
			'enable_cors',
			'enable_modern_mime_types',
			'enable_hsts',
			'hsts_include_subdomains',
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
	);

	/**
	 * Registers the admin menu and the settings option.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
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
			'dashicons-search',
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
					'scanning' => __( 'Scanning your homepage…', 'seooptimierung-toolkit' ),
					'error'    => __( 'Something went wrong.', 'seooptimierung-toolkit' ),
					'none'     => __( 'No external resources found — nothing to suggest.', 'seooptimierung-toolkit' ),
				),
			)
		);
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

		wp_send_json_success( array( 'lines' => $result ) );
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

		$clean = $existing;

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

		$tab           = isset( $input['_tab'] ) ? (string) $input['_tab'] : '';
		$checkbox_keys = 'sitemaps' === $tab ? $this->sitemap_checkbox_keys() : ( self::CHECKBOX_FIELDS_BY_TAB[ $tab ] ?? array() );

		foreach ( $checkbox_keys as $checkbox_key ) {
			$clean[ $checkbox_key ] = ! empty( $input[ $checkbox_key ] );
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
			'server'      => __( 'Server', 'seooptimierung-toolkit' ),
		);

		$active_tab = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.
			: 'general';

		echo '<div class="wrap seo-wp-settings">';
		echo '<h1>' . esc_html__( 'Seooptimierung Toolkit Settings', 'seooptimierung-toolkit' ) . '</h1>';
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = $slug === $active_tab ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => $slug,
						),
						admin_url( 'admin.php' )
					)
				),
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

		echo '</div>';
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

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Local business details', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'only used when "Site represents" above is set to "A local business".', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->text_field( 'local_business_type', __( 'Business type', 'seooptimierung-toolkit' ), 'LocalBusiness' );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Any Schema.org business type works, e.g. Restaurant, Store, ProfessionalService, MedicalBusiness, LegalService, RealEstateAgent, Attorney, AccountingService — LocalBusiness itself if none of the more specific ones fit.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
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
	 * Renders the Sitemaps tab.
	 */
	private function render_sitemaps_tab(): void {
		$this->checkbox_field(
			'disable_core_sitemap',
			__( 'Disable WordPress core\'s own /wp-sitemap.xml', 'seooptimierung-toolkit' ),
			true
		);

		$this->checkbox_field(
			'enable_news_sitemap',
			__( 'Enable Google News sitemap', 'seooptimierung-toolkit' ),
			true
		);
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'Follows Google\'s News sitemap guidelines: a separate /news-sitemap.xml listing only article-like content (non-hierarchical public post types) published in the last 48 hours, capped at 1000 URLs. It disappears from the sitemap index automatically whenever nothing currently qualifies. Turn this off if the site doesn\'t publish news content — Google News enrollment itself is a separate application, this only controls whether the plugin generates the file.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->checkbox_field(
			'sitemap_include_video',
			__( 'Include video:video entries in sitemaps', 'seooptimierung-toolkit' ),
			true
		);
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'Adds video:video entries for self-hosted <video poster> elements and YouTube embeds found in a post\'s content. An entry is only added when a real thumbnail is available, so not every embedded video qualifies (e.g. Vimeo currently doesn\'t).', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		$this->checkbox_field(
			'sitemap_include_hreflang',
			__( 'Include hreflang alternates (xhtml:link) in sitemaps', 'seooptimierung-toolkit' ),
			true
		);
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'When WPML, Polylang or slim-hreflang provides alternate-language URLs, this plugin\'s sitemaps normally list them as xhtml:link entries alongside the per-page <link rel="alternate"> tags in the document head. Turn this off to have hreflang live only on the pages themselves — some setups prefer one single source of truth rather than two places search engines could theoretically disagree on.', 'seooptimierung-toolkit' );
		echo '</p></td></tr>';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$this->checkbox_field(
				"sitemap_include_{$post_type->name}",
				/* translators: %s: post type label, e.g. "Products". */
				sprintf( __( 'Include %s in the XML sitemap', 'seooptimierung-toolkit' ), $post_type->labels->name ),
				true
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$this->checkbox_field(
				"sitemap_include_tax_{$taxonomy->name}",
				/* translators: %s: taxonomy label, e.g. "Product categories". */
				sprintf( __( 'Include %s in the XML sitemap', 'seooptimierung-toolkit' ), $taxonomy->labels->name ),
				true
			);
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
		$this->checkbox_field( 'enable_llms_txt', __( 'Enable /llms.txt', 'seooptimierung-toolkit' ), true );
		$this->checkbox_field( 'enable_llms_full_txt', __( 'Enable /llms-full.txt', 'seooptimierung-toolkit' ), false );
		$this->textarea_field( 'llms_txt_custom', __( 'Custom llms.txt content (appended after the generated list)', 'seooptimierung-toolkit' ) );
		$this->text_field( 'verification_google', __( 'Google verification code', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'verification_bing', __( 'Bing verification code', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'verification_baidu', __( 'Baidu verification code', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'verification_pinterest', __( 'Pinterest verification code', 'seooptimierung-toolkit' ), '' );
		$this->text_field( 'verification_yandex', __( 'Yandex verification code', 'seooptimierung-toolkit' ), '' );
		$this->checkbox_field( 'disable_author_archives', __( 'Disable author archives', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_date_archives', __( 'Disable date archives', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field(
			'strip_attachment_links',
			__( 'Don\'t link inserted images to their attachment page', 'seooptimierung-toolkit' ),
			false
		);
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
			array(
				'default' => __( 'WPML/Polylang\'s default language', 'seooptimierung-toolkit' ),
				'off'     => __( 'Off', 'seooptimierung-toolkit' ),
			),
			'default'
		);
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Only takes effect when WPML, Polylang, or a compatible plugin (e.g. slim-hreflang) actually provides alternate-language URLs — with none active, no hreflang or x-default tags are output at all.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
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
		echo '<button type="button" class="button seo-wp-scan-resource-hints">' . esc_html__( 'Scan homepage for suggestions', 'seooptimierung-toolkit' ) . '</button> ';
		echo '<span class="seo-wp-resource-hint-scan-status"></span>';
		echo '<p class="description">' . esc_html__( 'Fetches your homepage and lists external script/stylesheet origins worth a hint — pick which ones to add below, nothing is inserted automatically.', 'seooptimierung-toolkit' ) . '</p>';
		echo '<ul class="seo-wp-resource-hint-suggestions"></ul>';
		echo '</td></tr>';

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Unused scripts and assets', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'every toggle below is off by default; only turn on what this site genuinely doesn\'t use.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->checkbox_field( 'disable_emojis', __( 'Disable emoji scripts and styles', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_embeds', __( 'Disable embeds (oEmbed discovery and the wp-embed script)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_dashicons', __( 'Disable Dashicons for visitors who aren\'t logged in', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Revisions and autosave', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		$this->number_field( 'post_revisions_limit', __( 'Post revisions to keep', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default (unlimited). 0 disables revisions entirely.', 'seooptimierung-toolkit' ), 0, 100 );
		$this->number_field( 'autosave_interval', __( 'Autosave interval (seconds)', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default (60 seconds).', 'seooptimierung-toolkit' ), 10, 600 );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Heartbeat API', 'seooptimierung-toolkit' ) . '</strong> — ' . esc_html__( 'polls admin-ajax.php on a timer for post locks, autosave and the site health widget; can add up noticeably on busy admin sessions.', 'seooptimierung-toolkit' ) . '</p></td></tr>';

		$this->select_field(
			'heartbeat_mode',
			__( 'Heartbeat', 'seooptimierung-toolkit' ),
			array(
				'default'                     => __( 'Leave as-is', 'seooptimierung-toolkit' ),
				'disable_admin_except_editor' => __( 'Disable everywhere in wp-admin except the post editor', 'seooptimierung-toolkit' ),
				'disable_frontend'            => __( 'Disable on the public site', 'seooptimierung-toolkit' ),
				'disable_everywhere'          => __( 'Disable everywhere', 'seooptimierung-toolkit' ),
			),
			'default'
		);
		$this->number_field( 'heartbeat_frequency', __( 'Heartbeat frequency (seconds)', 'seooptimierung-toolkit' ), __( 'Leave empty for WordPress\' own default. Ignored when Heartbeat is fully disabled above.', 'seooptimierung-toolkit' ), 15, 300 );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Comments', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		$this->checkbox_field( 'disable_comments', __( 'Disable comments sitewide (closes comments/pings, hides the Comments admin menu)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_comment_author_links', __( 'Show comment author names as plain text, without a link to their URL', 'seooptimierung-toolkit' ), false );

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Third-party embeds', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		$this->checkbox_field( 'disable_google_maps', __( 'Block Google Maps scripts from loading', 'seooptimierung-toolkit' ), false );

		if ( class_exists( 'WooCommerce' ) ) {
			echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'WooCommerce', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';
			$this->checkbox_field( 'disable_wc_cart_fragments', __( 'Disable cart fragments (the wc-ajax=get_refreshed_fragments request)', 'seooptimierung-toolkit' ), false );
			$this->checkbox_field( 'disable_wc_scripts_off_shop', __( 'Only load WooCommerce scripts/styles on shop, cart, checkout and account pages', 'seooptimierung-toolkit' ), false );
		}

		echo '<tr><td colspan="2"><hr /><p class="description"><strong>' . esc_html__( 'Miscellaneous', 'seooptimierung-toolkit' ) . '</strong></p></td></tr>';

		$this->checkbox_field( 'disable_xmlrpc', __( 'Disable XML-RPC', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_jquery_migrate', __( 'Remove jQuery Migrate', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_wp_version', __( 'Remove the WordPress version number from the page source', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_rsd_link', __( 'Remove the RSD (Really Simple Discovery) link', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_wlwmanifest_link', __( 'Remove the wlwmanifest (Windows Live Writer) link', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_shortlink', __( 'Remove the shortlink', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_adjacent_posts_links', __( 'Remove rel="prev"/"next" adjacent-post links', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_rss_feeds', __( 'Disable RSS feeds entirely (visitors are shown a message instead)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'remove_rss_feed_links', __( 'Remove RSS feed auto-discovery links from <head>', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'disable_self_pingbacks', __( 'Disable self-pingbacks (linking to your own posts no longer pings them)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'restrict_rest_api', __( 'Restrict the REST API to logged-in users', 'seooptimierung-toolkit' ), false );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Careful: many themes, page builders and the block editor itself rely on the REST API, including for visitors who aren\'t logged in — test thoroughly before relying on this in production. This also blocks the slim-hreflang companion plugin\'s own multisite manifest route: its cross-site sync authenticates with its own secret, never a logged-in WordPress session, so enabling this breaks hreflang sync between your sites unless you exempt that route yourself (e.g. at the web server).', 'seooptimierung-toolkit' ) . '</p></td></tr>';
		$this->checkbox_field( 'remove_rest_api_links', __( 'Remove REST API discovery links from <head> and HTTP headers', 'seooptimierung-toolkit' ), false );
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

		$this->checkbox_field( 'enable_security_headers', __( 'Send security headers (X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'enable_hsts', __( 'Send Strict-Transport-Security (HSTS)', 'seooptimierung-toolkit' ), false );
		$this->checkbox_field( 'hsts_include_subdomains', __( 'Include subdomains in HSTS', 'seooptimierung-toolkit' ), false );
		echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'HSTS is only ever sent over an already-HTTPS connection — enabling it tells browsers to always use HTTPS for this domain from now on, which is hard to undo quickly (browsers cache it for the configured lifetime), so make sure HTTPS is fully working sitewide first.', 'seooptimierung-toolkit' ) . '</p></td></tr>';
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

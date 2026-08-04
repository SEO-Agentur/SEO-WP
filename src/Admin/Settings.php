<?php
/**
 * Plugin settings screen.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Templates\Tokens;

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
	public const PAGE_SLUG = 'seo-wp';

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
		'sitemaps'    => array( 'disable_core_sitemap' ),
		'crawl'       => array(
			'block_ai_crawlers',
			'enable_llms_txt',
			'enable_llms_full_txt',
			'disable_author_archives',
			'disable_date_archives',
			'strip_attachment_links',
			'enable_indexnow',
		),
		'performance' => array( 'defer_noncritical_css' ),
	);

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
	);

	/**
	 * Registers the admin menu and the settings option.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the top-level menu and its default (Settings) submenu.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'SEO-WP', 'seo-wp' ),
			__( 'SEO-WP', 'seo-wp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-search',
			80
		);

		add_submenu_page(
			self::PAGE_SLUG,
			__( 'Settings', 'seo-wp' ),
			__( 'Settings', 'seo-wp' ),
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

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
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
			$clean['identity_type'] = 'person' === $input['identity_type'] ? 'person' : 'organization';
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

		if ( array_key_exists( 'attachment_handling', $input ) ) {
			$clean['attachment_handling'] = in_array( $input['attachment_handling'], array( 'redirect', 'noindex', 'off' ), true )
				? $input['attachment_handling']
				: 'redirect';
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
			'general'     => __( 'General', 'seo-wp' ),
			'content'     => __( 'Content Types', 'seo-wp' ),
			'sitemaps'    => __( 'Sitemaps', 'seo-wp' ),
			'crawl'       => __( 'Crawl', 'seo-wp' ),
			'performance' => __( 'Performance', 'seo-wp' ),
		);

		$active_tab = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.
			? sanitize_key( wp_unslash( $_GET['tab'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector, not a state-changing action.
			: 'general';

		echo '<div class="wrap seo-wp-settings">';
		echo '<h1>' . esc_html__( 'SEO-WP Settings', 'seo-wp' ) . '</h1>';
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
			default:
				$this->render_general_tab();
				break;
		}
		echo '</table>';

		submit_button();
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders the General tab: separator, home templates, site identity,
	 * Twitter handles.
	 */
	private function render_general_tab(): void {
		$this->text_field( 'separator', __( 'Separator', 'seo-wp' ), '-' );
		$this->template_field( 'title_template_home', __( 'Home title template', 'seo-wp' ), '%sitename%' );
		$this->template_field( 'description_template_home', __( 'Home description template', 'seo-wp' ), '' );
		$this->select_field(
			'identity_type',
			__( 'Site represents', 'seo-wp' ),
			array(
				'organization' => __( 'An organization', 'seo-wp' ),
				'person'       => __( 'A person', 'seo-wp' ),
			),
			'organization'
		);
		$this->text_field( 'identity_name', __( 'Organization/person name', 'seo-wp' ), (string) get_bloginfo( 'name' ) );
		$this->text_field( 'twitter_site', __( 'Twitter/X @handle for the site', 'seo-wp' ), '' );
		$this->text_field( 'twitter_creator', __( 'Default Twitter/X @handle for authors', 'seo-wp' ), '' );
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
				sprintf( __( '%s title template', 'seo-wp' ), $post_type->labels->singular_name ),
				'%title% %sep% %sitename%'
			);
			$this->template_field(
				"description_template_{$post_type->name}",
				/* translators: %s: post type label. */
				sprintf( __( '%s description template', 'seo-wp' ), $post_type->labels->singular_name ),
				'%excerpt%'
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$this->template_field(
				"title_template_term_{$taxonomy->name}",
				/* translators: %s: taxonomy label. */
				sprintf( __( '%s title template', 'seo-wp' ), $taxonomy->labels->singular_name ),
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
		echo '<th>' . esc_html__( 'Type', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Title template', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Description template', 'seo-wp' ) . '</th>';
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
			__( 'Disable WordPress core\'s own /wp-sitemap.xml', 'seo-wp' ),
			true
		);

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			$this->checkbox_field(
				"sitemap_include_{$post_type->name}",
				/* translators: %s: post type label, e.g. "Products". */
				sprintf( __( 'Include %s in the XML sitemap', 'seo-wp' ), $post_type->labels->name ),
				true
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$this->checkbox_field(
				"sitemap_include_tax_{$taxonomy->name}",
				/* translators: %s: taxonomy label, e.g. "Product categories". */
				sprintf( __( 'Include %s in the XML sitemap', 'seo-wp' ), $taxonomy->labels->name ),
				true
			);
		}
	}

	/**
	 * Renders the Crawl tab: robots.txt, llms.txt, verification,
	 * archives, IndexNow.
	 */
	private function render_crawl_tab(): void {
		$this->textarea_field( 'robots_txt_custom', __( 'Custom robots.txt rules', 'seo-wp' ) );
		$this->checkbox_field( 'block_ai_crawlers', __( 'Block known AI training crawlers', 'seo-wp' ), false );
		$this->checkbox_field( 'enable_llms_txt', __( 'Enable /llms.txt', 'seo-wp' ), true );
		$this->checkbox_field( 'enable_llms_full_txt', __( 'Enable /llms-full.txt', 'seo-wp' ), false );
		$this->textarea_field( 'llms_txt_custom', __( 'Custom llms.txt content (appended after the generated list)', 'seo-wp' ) );
		$this->text_field( 'verification_google', __( 'Google verification code', 'seo-wp' ), '' );
		$this->text_field( 'verification_bing', __( 'Bing verification code', 'seo-wp' ), '' );
		$this->text_field( 'verification_baidu', __( 'Baidu verification code', 'seo-wp' ), '' );
		$this->text_field( 'verification_pinterest', __( 'Pinterest verification code', 'seo-wp' ), '' );
		$this->text_field( 'verification_yandex', __( 'Yandex verification code', 'seo-wp' ), '' );
		$this->checkbox_field( 'disable_author_archives', __( 'Disable author archives', 'seo-wp' ), false );
		$this->checkbox_field( 'disable_date_archives', __( 'Disable date archives', 'seo-wp' ), false );
		$this->checkbox_field(
			'strip_attachment_links',
			__( 'Don\'t link inserted images to their attachment page', 'seo-wp' ),
			false
		);
		$this->select_field(
			'attachment_handling',
			__( 'Attachment pages', 'seo-wp' ),
			array(
				'redirect' => __( 'Redirect to the parent post (recommended)', 'seo-wp' ),
				'noindex'  => __( 'Keep accessible, but noindex them', 'seo-wp' ),
				'off'      => __( 'Leave to WordPress core', 'seo-wp' ),
			),
			'redirect'
		);
		$this->checkbox_field( 'enable_indexnow', __( 'Enable IndexNow (Bing, Yandex, Seznam, Naver)', 'seo-wp' ), true );
		$this->select_field(
			'hreflang_x_default',
			__( 'x-default hreflang', 'seo-wp' ),
			array(
				'default' => __( 'WPML\'s default language', 'seo-wp' ),
				'off'     => __( 'Off', 'seo-wp' ),
			),
			'default'
		);
	}

	/**
	 * Renders the Performance tab: manually-supplied critical CSS and an
	 * independent toggle to load every other stylesheet non-blocking.
	 */
	private function render_performance_tab(): void {
		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'This plugin cannot auto-detect critical CSS — that requires actually rendering the page (real layout, real box positions), which no CSS parsing pass can do. Paste critical CSS from a tool of your choice, or write it by hand.', 'seo-wp' );
		echo '</p></td></tr>';

		$this->textarea_field( 'critical_css', __( 'Critical CSS (inlined in <head>)', 'seo-wp' ) );

		echo '<tr><td colspan="2"><p class="description">';
		echo esc_html__( 'Independent of the above: without critical CSS in place, deferring every other stylesheet will show a flash of unstyled content until they finish loading — enable this once you have critical CSS covering what visitors see immediately, or if you\'ve decided that tradeoff is acceptable for your site.', 'seo-wp' );
		echo '</p></td></tr>';

		$this->checkbox_field( 'defer_noncritical_css', __( 'Load all other stylesheets non-blocking', 'seo-wp' ), false );
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

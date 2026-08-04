<?php
/**
 * Admin screen for bring-your-own-key AI provider settings.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

/**
 * Optional API keys for Anthropic (Claude), OpenAI and DeepL, used by the
 * Bulk Editor's AI-suggestion and translation actions. Bring-your-own-key
 * throughout: this plugin never proxies requests through its own servers
 * or adds any markup — a site owner's key is billed directly by that
 * provider. Keys are never echoed back into the page after saving (only
 * a "saved" placeholder is shown); an empty submission always keeps the
 * existing key rather than clearing it, since these fields are otherwise
 * indistinguishable from "no key yet".
 */
final class AiSettings {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-ai';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Capability required to view or manage this page.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Nonce action/field name.
	 */
	private const NONCE_ACTION = 'seo_wp_ai_settings';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_ai_settings_nonce';

	/**
	 * Settings keys that hold a secret (never echoed back).
	 *
	 * @var string[]
	 */
	private const SECRET_KEYS = array( 'ai_anthropic_key', 'ai_openai_key', 'ai_deepl_key' );

	/**
	 * Settings keys that hold a plain, non-secret text override.
	 *
	 * @var string[]
	 */
	private const TEXT_KEYS = array( 'ai_anthropic_model', 'ai_openai_model' );

	/**
	 * Registers the submenu page and its save handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_ai_settings_save', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the AI Settings submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'AI Settings', 'seo-wp' ),
			__( 'AI Settings', 'seo-wp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the shared admin stylesheet on this page only.
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
	 * Renders the settings form.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		echo '<div class="wrap seo-wp-ai-settings">';
		echo '<h1>' . esc_html__( 'SEO-WP AI Settings', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Bring your own API key for each provider you want to use in the Bulk Editor. Nothing here is called automatically — only when you explicitly click an AI action. Your key is billed directly by the provider; this plugin never proxies requests or adds a markup.', 'seo-wp' ) . '</p>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner, not a state-changing action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'seo-wp' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_ai_settings_save" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<h2>' . esc_html__( 'Anthropic (Claude)', 'seo-wp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->secret_field( 'ai_anthropic_key', __( 'API key', 'seo-wp' ), $settings );
		$this->text_field( 'ai_anthropic_model', __( 'Model override', 'seo-wp' ), 'claude-haiku-4-5-20251001', $settings );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'OpenAI', 'seo-wp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->secret_field( 'ai_openai_key', __( 'API key', 'seo-wp' ), $settings );
		$this->text_field( 'ai_openai_model', __( 'Model override', 'seo-wp' ), 'gpt-4o-mini', $settings );
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'DeepL', 'seo-wp' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$this->secret_field( 'ai_deepl_key', __( 'API key', 'seo-wp' ), $settings );
		echo '</tbody></table>';

		submit_button( __( 'Save settings', 'seo-wp' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Renders a secret field: never shows the stored value, only whether
	 * one is already saved.
	 *
	 * @param string              $key      Settings key.
	 * @param string              $label    Field label.
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function secret_field( string $key, string $label, array $settings ): void {
		$has_value   = ! empty( $settings[ $key ] );
		$placeholder = $has_value
			? __( '•••••••• (saved — leave blank to keep)', 'seo-wp' )
			: __( 'Not set', 'seo-wp' );

		printf(
			'<tr><th scope="row"><label for="seo_wp_%1$s">%2$s</label></th><td><input type="password" id="seo_wp_%1$s" name="%1$s" value="" autocomplete="off" placeholder="%3$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Renders a plain text override field.
	 *
	 * @param string              $key      Settings key.
	 * @param string              $label    Field label.
	 * @param string              $default_value Placeholder shown when empty.
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function text_field( string $key, string $label, string $default_value, array $settings ): void {
		$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

		printf(
			'<tr><th scope="row"><label for="seo_wp_%1$s">%2$s</label></th><td><input type="text" id="seo_wp_%1$s" name="%1$s" value="%3$s" placeholder="%4$s" class="regular-text" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( $value ),
			esc_attr( $default_value )
		);
	}

	/**
	 * Handles the settings form submission.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		foreach ( self::SECRET_KEYS as $key ) {
			$submitted = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : '';
			if ( '' !== $submitted ) {
				$settings[ $key ] = $submitted;
			}
		}

		foreach ( self::TEXT_KEYS as $key ) {
			$settings[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		}

		update_option( self::OPTION_KEY, $settings, 'yes' );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

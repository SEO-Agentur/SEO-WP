<?php
/**
 * Admin screen for Google Instant Indexing settings and history.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Crawl\GoogleIndexing;

/**
 * A settings form (enable toggle, service account key, eligible post
 * types) plus the submission history log, on one page — mirroring
 * Diagnostics' log display, but with its own settings section since this
 * feature needs a large, sensitive text field that doesn't belong on the
 * general Settings screen. Deliberately kept on its own submenu page,
 * separate from IndexNowPage's "Instant Indexing" screen: Google's
 * Indexing API and the IndexNow protocol (Bing/Yandex/Seznam/Naver) are
 * unrelated services with unrelated credentials, and conflating them
 * under one page is what "Instant Indexing" used to do before this split.
 */
final class GoogleIndexingPage {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-google-indexing';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Capability required to view or manage this page.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Nonce action/field name for the settings form.
	 */
	private const NONCE_ACTION = 'seo_wp_google_indexing';

	/**
	 * Nonce field name for the settings form.
	 */
	private const NONCE_NAME = 'seo_wp_google_indexing_nonce';

	/**
	 * Nonce action/field name for the clear-log form.
	 */
	private const CLEAR_NONCE_ACTION = 'seo_wp_google_indexing_clear';

	/**
	 * Nonce field name for the clear-log form.
	 */
	private const CLEAR_NONCE_NAME = 'seo_wp_google_indexing_clear_nonce';

	/**
	 * Registers the submenu page and its form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_google_indexing_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_seo_wp_google_indexing_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the Instant Indexing submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Google Indexing API', 'seo-wp' ),
			__( 'Google Indexing API', 'seo-wp' ),
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
	 * Renders the settings form and the submission history.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );

		echo '<div class="wrap seo-wp-google-indexing">';
		echo '<h1>' . esc_html__( 'SEO-WP Google Indexing API', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pings Google\'s Indexing API on publish, update and delete using a Google Cloud service account. Google officially scopes this API to job posting and livestream pages, but accepts submissions for any URL. For Bing, Yandex, Seznam and Naver, see the separate Instant Indexing (IndexNow) page instead — Google does not participate in IndexNow.', 'seo-wp' ) . '</p>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner, not a state-changing action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'seo-wp' ) . '</p></div>';
		}

		$this->render_settings_form( $settings );
		$this->render_history();

		echo '</div>';
	}

	/**
	 * Renders the settings form.
	 *
	 * @param array<string,mixed> $settings Current plugin settings.
	 */
	private function render_settings_form( array $settings ): void {
		$enabled    = ! empty( $settings['enable_google_indexing'] );
		$has_key    = ! empty( $settings['google_indexing_key'] );
		$key_hint   = $has_key
			? __( '•••••••• (a key is saved — paste a new one to replace it, or leave blank to keep it)', 'seo-wp' )
			: __( 'Paste the full contents of the service account key file downloaded from Google Cloud Console.', 'seo-wp' );
		$post_types = isset( $settings['google_indexing_post_types'] ) && is_array( $settings['google_indexing_post_types'] )
			? $settings['google_indexing_post_types']
			: array( 'post', 'page' );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_google_indexing_save" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="enable_google_indexing" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Enable', 'seo-wp' ),
			checked( $enabled, true, false ),
			esc_html__( 'Enable Google Instant Indexing', 'seo-wp' )
		);

		printf(
			'<tr><th scope="row"><label for="seo_wp_google_indexing_key">%1$s</label></th><td><textarea id="seo_wp_google_indexing_key" name="google_indexing_key" rows="8" class="large-text code" placeholder="%2$s" autocomplete="off"></textarea><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Service account JSON key', 'seo-wp' ),
			esc_attr( $key_hint ),
			esc_html__( 'Create a service account with the "Owner" role on a project with the Indexing API enabled, add its email as a delegated owner in Search Console, then paste its JSON key here. Never echoed back once saved — leave the field blank to keep the existing key.', 'seo-wp' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'seo-wp' ) . '</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			printf(
				'<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" name="google_indexing_post_types[]" value="%1$s" %2$s /> %3$s</label>',
				esc_attr( $post_type->name ),
				checked( in_array( $post_type->name, $post_types, true ), true, false ),
				esc_html( $post_type->labels->name )
			);
		}
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'seo-wp' ) );
		echo '</form>';
	}

	/**
	 * Renders the submission history table and its clear-log form.
	 */
	private function render_history(): void {
		$entries = array_reverse( GoogleIndexing::log_entries() );

		echo '<h2>' . esc_html__( 'Submission history', 'seo-wp' ) . '</h2>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No submissions yet.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_google_indexing_clear" />';
		wp_nonce_field( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );
		submit_button( __( 'Clear history', 'seo-wp' ), 'delete', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped seo-wp-google-indexing-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			printf(
				'<tr><td><code>%1$s</code></td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( (string) ( $entry['url'] ?? '' ) ),
				esc_html( (string) ( $entry['type'] ?? '' ) ),
				esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) )
			);
		}

		echo '</tbody></table>';
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
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['enable_google_indexing'] = ! empty( $_POST['enable_google_indexing'] );

		// A blank submission keeps the existing key rather than clearing it —
		// the field is write-only (never re-populated with the stored value,
		// see render_settings_form()), so "blank" is indistinguishable from
		// "didn't mean to change it" and must not be read as "remove the key".
		$submitted_key = isset( $_POST['google_indexing_key'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['google_indexing_key'] ) ) : '';
		if ( '' !== $submitted_key ) {
			$settings['google_indexing_key'] = $submitted_key;
		}

		$submitted_types                        = isset( $_POST['google_indexing_post_types'] ) && is_array( $_POST['google_indexing_post_types'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['google_indexing_post_types'] ) )
			: array();
		$valid_types                            = get_post_types( array( 'public' => true ), 'names' );
		$settings['google_indexing_post_types'] = array_values( array_intersect( $valid_types, $submitted_types ) );

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

	/**
	 * Handles the clear-history form submission.
	 */
	public function handle_clear(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );

		GoogleIndexing::clear_log();

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}

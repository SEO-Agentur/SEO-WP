<?php
/**
 * Admin display for the recorded error log.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Support\ErrorLog;

/**
 * Surfaces ErrorLog entries: a dismissible admin notice site-wide when
 * something was recorded recently, and a dedicated page listing every
 * entry with a way to clear them.
 */
final class Diagnostics {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-diagnostics';

	/**
	 * Capability required to view or clear diagnostics.
	 */
	private const CAPABILITY = 'seo_wp_view_diagnostics';

	/**
	 * Nonce action/field name for the clear-log action.
	 */
	private const NONCE_ACTION = 'seo_wp_diagnostics_clear';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_diagnostics_nonce';

	/**
	 * Registers the submenu page, the admin notice and the clear-log
	 * handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
		add_action( 'admin_post_seo_wp_diagnostics_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the Diagnostics submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Diagnostics', 'seo-wp' ),
			__( 'Diagnostics', 'seo-wp' ),
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
	 * Shows a dismissible notice on every admin screen when something was
	 * recorded in the last day, linking to the Diagnostics page.
	 */
	public function maybe_render_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || ! ErrorLog::has_recent() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'SEO-WP recorded one or more issues recently (import mapping or a database check).', 'seo-wp' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'View details', 'seo-wp' )
		);
	}

	/**
	 * Renders the Diagnostics page: a table of every recorded entry, most
	 * recent first, and a button to clear the log.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$entries = array_reverse( ErrorLog::all() );

		echo '<div class="wrap seo-wp-diagnostics">';
		echo '<h1>' . esc_html__( 'SEO-WP Diagnostics', 'seo-wp' ) . '</h1>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No issues recorded.', 'seo-wp' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_diagnostics_clear" />';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		submit_button( __( 'Clear log', 'seo-wp' ), 'delete', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped seo-wp-diagnostics-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Where', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$this->render_row( $entry );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders one log entry row.
	 *
	 * @param array<string,mixed> $entry Log entry.
	 */
	private function render_row( array $entry ): void {
		$time    = isset( $entry['time'] ) ? (int) $entry['time'] : 0;
		$context = isset( $entry['context'] ) ? (string) $entry['context'] : '';
		$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
		$data    = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();

		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td>%s%s</td></tr>',
			esc_html( $time > 0 ? gmdate( 'Y-m-d H:i:s', $time ) : '' ),
			esc_html( $context ),
			esc_html( $message ),
			$this->render_entry_data( $data ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_entry_data() escapes every value it outputs.
		);
	}

	/**
	 * Renders any extra detail an entry carries (a caught exception's
	 * class/location, or captured stray AJAX output) in a collapsible
	 * block, so it's available without cluttering rows that have none.
	 *
	 * @param array<string,mixed> $data Extra detail from the log entry.
	 */
	private function render_entry_data( array $data ): string {
		if ( array() === $data ) {
			return '';
		}

		$lines = '';
		foreach ( $data as $key => $value ) {
			$lines .= sprintf(
				'<div><strong>%s:</strong> <code>%s</code></div>',
				esc_html( (string) $key ),
				esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) )
			);
		}

		return sprintf(
			'<details class="seo-wp-diagnostics-detail"><summary>%s</summary>%s</details>',
			esc_html__( 'Details', 'seo-wp' ),
			$lines // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_html()/esc_html__() above.
		);
	}

	/**
	 * Handles the clear-log form submission.
	 */
	public function handle_clear(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		ErrorLog::clear();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}

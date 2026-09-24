<?php
/**
 * Admin display for the 404 monitor log.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Crawl\NotFoundMonitor;

/**
 * Lists every recorded 404 hit, most recent first, with a one-click link
 * into RedirectsPage prefilled with that path, a per-row dismiss action,
 * and a way to clear the whole log.
 */
final class NotFoundMonitorPage {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seooptimierung-404-monitor';

	/**
	 * Capability required to view or manage the log.
	 */
	private const CAPABILITY = 'seo_wp_view_404_monitor';

	/**
	 * Nonce action prefix for the per-row dismiss action.
	 */
	private const NONCE_ACTION = 'seo_wp_404_monitor';

	/**
	 * Nonce action for the clear-log action.
	 */
	private const CLEAR_NONCE_ACTION = 'seo_wp_404_monitor_clear';

	/**
	 * Nonce field name for the clear-log action.
	 */
	private const CLEAR_NONCE_NAME = 'seo_wp_404_monitor_clear_nonce';

	/**
	 * Registers the submenu page and its form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_404_monitor_forget', array( $this, 'handle_forget' ) );
		add_action( 'admin_post_seo_wp_404_monitor_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the 404 Monitor submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( '404 Monitor', 'seooptimierung-toolkit' ),
			__( '404 Monitor', 'seooptimierung-toolkit' ),
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

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION );
	}

	/**
	 * Renders the 404 monitor page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$entries = NotFoundMonitor::all();
		uasort( $entries, static fn ( array $a, array $b ): int => $b['last_seen'] <=> $a['last_seen'] );

		echo '<div class="wrap seo-wp-404-monitor">';
		echo '<h1>' . esc_html__( 'Seooptimierung Toolkit 404 Monitor', 'seooptimierung-toolkit' ) . '</h1>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No 404s recorded.', 'seooptimierung-toolkit' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_404_monitor_clear" />';
		wp_nonce_field( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );
		submit_button( __( 'Clear log', 'seooptimierung-toolkit' ), 'delete', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped seo-wp-404-monitor-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Hits', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Referrer', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Last seen', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'seooptimierung-toolkit' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $path => $entry ) {
			$this->render_row( (string) $path, $entry );
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	/**
	 * Renders one log entry row.
	 *
	 * @param string                                                       $path  Request path.
	 * @param array{hits:int,first_seen:int,last_seen:int,referrer:string} $entry Log entry.
	 */
	private function render_row( string $path, array $entry ): void {
		$redirect_url = add_query_arg(
			array(
				'page'   => 'seooptimierung-redirects',
				'source' => $path,
			),
			admin_url( 'admin.php' )
		);

		$forget_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'seo_wp_404_monitor_forget',
					'path'   => $path,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_ACTION . '_forget_' . md5( $path )
		);

		echo '<tr>';
		printf( '<td><code>%s</code></td>', esc_html( $path ) );
		printf( '<td>%d</td>', (int) $entry['hits'] );
		printf( '<td>%s</td>', esc_html( '' !== $entry['referrer'] ? $entry['referrer'] : '—' ) );
		printf( '<td>%s</td>', esc_html( gmdate( 'Y-m-d H:i:s', (int) $entry['last_seen'] ) ) );
		printf(
			'<td><a href="%1$s">%2$s</a> | <a href="%3$s">%4$s</a></td>',
			esc_url( $redirect_url ),
			esc_html__( 'Create redirect', 'seooptimierung-toolkit' ),
			esc_url( $forget_url ),
			esc_html__( 'Dismiss', 'seooptimierung-toolkit' )
		);
		echo '</tr>';
	}

	/**
	 * Handles a per-row dismiss click.
	 */
	public function handle_forget(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) );
		}

		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '';
		check_admin_referer( self::NONCE_ACTION . '_forget_' . md5( $path ) );

		NotFoundMonitor::forget( $path );

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles the clear-log form submission.
	 */
	public function handle_clear(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) );
		}

		check_admin_referer( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );

		NotFoundMonitor::clear();

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}

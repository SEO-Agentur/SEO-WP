<?php
/**
 * Site-wide noindex enforcement for staging/development environments.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

use SeoWp\Support\StagingDetector;

/**
 * Two independent pieces: an absolute, site-wide noindex/nofollow that
 * overrides every other robots setting when explicitly turned on
 * (staging_noindex), and a dismissible admin notice suggesting exactly
 * that when the site's own URL looks like a staging/dev environment
 * (Support\StagingDetector). Detection only ever suggests — nothing here
 * ever flips the setting on by itself.
 */
final class StagingProtection {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Settings key for the site-wide noindex override.
	 */
	private const NOINDEX_SETTING_KEY = 'staging_noindex';

	/**
	 * Option key tracking whether the detection notice was dismissed.
	 */
	private const NOTICE_DISMISSED_OPTION = 'seo_wp_staging_notice_dismissed';

	/**
	 * Capability required to see the notice or act on it.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Registers the robots override (unconditionally — must apply on
	 * every front-end request) and the admin-only notice/actions.
	 */
	public function register(): void {
		add_filter( 'wp_robots', array( $this, 'maybe_force_noindex' ), 999 );

		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
			add_action( 'admin_post_seo_wp_enable_staging_noindex', array( $this, 'handle_enable' ) );
			add_action( 'admin_post_seo_wp_dismiss_staging_notice', array( $this, 'handle_dismiss' ) );
		}
	}

	/**
	 * Forces noindex/nofollow on every page when staging_noindex is
	 * enabled, overriding whatever every other module (or a per-post
	 * override) already decided — an absolute site-wide guard, not just
	 * another default.
	 *
	 * @param array<string,bool> $robots Directives collected so far by WordPress core and this plugin's own filters.
	 * @return array<string,bool>
	 */
	public function maybe_force_noindex( array $robots ): array {
		if ( ! $this->enabled() ) {
			return $robots;
		}

		$robots['noindex']  = true;
		$robots['nofollow'] = true;

		return $robots;
	}

	/**
	 * Whether the site-wide staging noindex override is turned on.
	 */
	private function enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) && ! empty( $settings[ self::NOINDEX_SETTING_KEY ] );
	}

	/**
	 * Shows a dismissible notice suggesting site-wide noindex when the
	 * site's own URL looks like staging, the override isn't already on,
	 * and the notice hasn't been dismissed.
	 */
	public function maybe_render_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) || $this->enabled() || get_option( self::NOTICE_DISMISSED_OPTION, false ) ) {
			return;
		}

		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! StagingDetector::looks_like_staging_host( $host ) ) {
			return;
		}

		$enable_url  = wp_nonce_url(
			admin_url( 'admin-post.php?action=seo_wp_enable_staging_noindex' ),
			'seo_wp_enable_staging_noindex'
		);
		$dismiss_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=seo_wp_dismiss_staging_notice' ),
			'seo_wp_dismiss_staging_notice'
		);

		echo '<div class="notice notice-warning is-dismissible">';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: detected hostname. */
					__( 'SEO-WP: "%s" looks like a staging or development site. If it is, search engines could index it and compete with your real site.', 'seo-wp' ),
					$host
				)
			)
		);
		printf(
			'<p><a href="%1$s" class="button button-primary">%2$s</a> <a href="%3$s" class="button">%4$s</a></p>',
			esc_url( $enable_url ),
			esc_html__( 'Noindex this entire site now', 'seo-wp' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Dismiss (this is fine)', 'seo-wp' )
		);
		echo '</div>';
	}

	/**
	 * Handles the one-click "Noindex this entire site now" action.
	 */
	public function handle_enable(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( 'seo_wp_enable_staging_noindex' );

		$settings                              = get_option( self::OPTION_KEY, array() );
		$settings                              = is_array( $settings ) ? $settings : array();
		$settings[ self::NOINDEX_SETTING_KEY ] = true;
		update_option( self::OPTION_KEY, $settings, 'yes' );

		wp_safe_redirect( self::redirect_target() );
		exit;
	}

	/**
	 * Handles the "Dismiss" action.
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( 'seo_wp_dismiss_staging_notice' );

		update_option( self::NOTICE_DISMISSED_OPTION, true, false );

		wp_safe_redirect( self::redirect_target() );
		exit;
	}

	/**
	 * Where to send the admin back to after either action: wherever they
	 * came from, or the dashboard when that isn't available.
	 */
	private static function redirect_target(): string {
		$referer = wp_get_referer();

		return is_string( $referer ) && '' !== $referer ? $referer : admin_url();
	}
}

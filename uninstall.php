<?php
/**
 * Fired when the plugin is uninstalled via the WordPress admin.
 *
 * Removes the plugin's own options. Post/term meta written by this
 * plugin (title/description/robots/canonical/social/FAQ overrides) is
 * intentionally left in place, matching the non-destructive stance
 * described in the plugin's requirements — reinstalling the plugin
 * should not lose per-object SEO data.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seo_wp_settings' );
delete_option( 'seo_wp_secrets' );
delete_option( 'seo_wp_indexnow_key' );
delete_option( 'seo_wp_indexnow_log' );
delete_option( 'seo_wp_import_log' );
delete_option( 'seo_wp_error_log' );
delete_option( 'seo_wp_role_caps' );
delete_option( 'seo_wp_duplicate_scan' );
delete_option( 'seo_wp_link_audit_scan' );
delete_option( 'seo_wp_link_queue' );
delete_option( 'seo_wp_broken_links' );
delete_option( 'seo_wp_link_audit_job' );
delete_option( 'seo_wp_google_indexing_token' );
delete_option( 'seo_wp_google_indexing_log' );
delete_option( 'seo_wp_404_log' );
delete_option( 'seo_wp_redirects' );
delete_option( 'seo_wp_staging_notice_dismissed' );
delete_option( 'seo_wp_rewrite_version' );

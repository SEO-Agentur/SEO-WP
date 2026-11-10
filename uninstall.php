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
delete_option( 'seo_wp_image_alt_scan' );
delete_option( 'seo_wp_slug_log' );
delete_option( 'seo_wp_asset_skip' );

// The files the asset optimizer generated in the uploads folder.
$seo_wp_uploads = wp_upload_dir( null, false );
if ( empty( $seo_wp_uploads['error'] ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	if ( WP_Filesystem() ) {
		global $wp_filesystem;

		$seo_wp_cache_dir = trailingslashit( $seo_wp_uploads['basedir'] ) . 'seooptimierung-cache';
		if ( $wp_filesystem->is_dir( $seo_wp_cache_dir ) ) {
			$wp_filesystem->delete( $seo_wp_cache_dir, true );
		}
	}
}

// The block this plugin keeps in .htaccess (compression, caching, security headers): emptied, as core does with its own.
$seo_wp_htaccess = ABSPATH . '.htaccess';
if ( file_exists( $seo_wp_htaccess ) && is_writable( $seo_wp_htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- permission pre-check for insert_with_markers(), which writes the file directly as core does.
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	insert_with_markers( $seo_wp_htaccess, 'Seooptimierung Toolkit Performance', array() );
}

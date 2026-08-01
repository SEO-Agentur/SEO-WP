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
 * @package SeoWp
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seo_wp_settings' );
delete_option( 'seo_wp_indexnow_key' );
delete_option( 'seo_wp_import_log' );

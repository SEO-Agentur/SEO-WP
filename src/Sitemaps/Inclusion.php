<?php
/**
 * Per-post-type / per-taxonomy sitemap inclusion settings.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

/**
 * Reads the "sitemap_include_{type}" / "sitemap_include_tax_{taxonomy}"
 * settings that let a post type or taxonomy be excluded from the XML
 * sitemap. WooCommerce's product and product_cat are just public post
 * types/taxonomies to WordPress, so they get a toggle automatically,
 * same as any other. Defaults to included, matching this plugin's
 * behaviour before these settings existed.
 */
final class Inclusion {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Whether a post type should appear in the sitemap.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function post_type( string $post_type ): bool {
		return self::included( "sitemap_include_{$post_type}" );
	}

	/**
	 * Whether a taxonomy should appear in the sitemap.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function taxonomy( string $taxonomy ): bool {
		return self::included( "sitemap_include_tax_{$taxonomy}" );
	}

	/**
	 * Whether video:video entries should be added to sitemaps.
	 */
	public static function video_sitemap(): bool {
		return self::included( 'sitemap_include_video' );
	}

	/**
	 * Whether xhtml:link hreflang alternates should be added to sitemap
	 * entries. Off doesn't mean "no hreflang" — it means the per-page
	 * <link rel="alternate"> tags (Wpml\Hreflang, or slim-hreflang taking
	 * over via the compatibility contract) are the only place they're
	 * emitted, instead of also duplicating them into every sitemap.
	 */
	public static function hreflang_in_sitemap(): bool {
		return self::included( 'sitemap_include_hreflang' );
	}

	/**
	 * Reads a single inclusion setting, defaulting to true when unset.
	 *
	 * @param string $key Settings key.
	 */
	private static function included( string $key ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! array_key_exists( $key, $settings ) || ! empty( $settings[ $key ] );
	}
}

<?php
/**
 * Optional "run alongside Yoast/Rank Math instead of replacing them" mode.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

/**
 * Off by default — this plugin is normally meant to replace Yoast/Rank
 * Math, not run next to them (see the FAQ). When explicitly turned on,
 * every module that would otherwise print its own title/meta
 * description/canonical/Open Graph/Twitter Card/JSON-LD output checks
 * CoexistenceMode::active() first and stays silent, leaving that output
 * entirely to whichever plugin is configured here — printing both would
 * mean literal duplicate <meta>/<script> tags, not just a value
 * disagreement. The Bulk Editor's Title/Description/Canonical columns
 * read from and write to that plugin's own postmeta keys instead of
 * this plugin's, via meta_key(), so editing there still does something
 * real. Everything else this plugin does (sitemaps, hreflang, redirects,
 * 404 monitor, IndexNow, Link Audit, Duplicate Checker, Role Manager,
 * Performance) is unaffected — none of it duplicates what Yoast/Rank
 * Math already do.
 */
final class CoexistenceMode {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Settings key holding the configured source ('' | 'yoast' | 'rank_math').
	 */
	private const SETTING_KEY = 'coexistence_source';

	/**
	 * The other plugin's own postmeta key for each field this plugin
	 * would otherwise own.
	 *
	 * @var array<string,array<string,string>>
	 */
	private const META_KEYS = array(
		'yoast'     => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'canonical'   => '_yoast_wpseo_canonical',
		),
		'rank_math' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'canonical'   => 'rank_math_canonical_url',
		),
	);

	/**
	 * The configured source, or '' when coexistence mode is off (the
	 * default) or set to something unrecognised.
	 */
	public static function source(): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$source   = is_array( $settings ) && isset( $settings[ self::SETTING_KEY ] ) ? (string) $settings[ self::SETTING_KEY ] : '';

		return isset( self::META_KEYS[ $source ] ) ? $source : '';
	}

	/**
	 * Whether coexistence mode is on.
	 */
	public static function active(): bool {
		return '' !== self::source();
	}

	/**
	 * The configured source plugin's own postmeta key for a field, or
	 * null when coexistence mode is off or the field has no counterpart
	 * (e.g. this plugin's Open Graph/Twitter/schema fields don't need
	 * one — those modules simply stay silent, there's nowhere else for
	 * the Bulk Editor to redirect them to).
	 *
	 * @param string $field 'title', 'description' or 'canonical'.
	 */
	public static function meta_key( string $field ): ?string {
		$source = self::source();

		return '' === $source ? null : ( self::META_KEYS[ $source ][ $field ] ?? null );
	}
}

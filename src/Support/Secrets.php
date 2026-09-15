<?php
/**
 * Dedicated, non-autoloaded storage for API keys and other credentials.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

/**
 * SEO-WP's general settings (`seo_wp_settings`) are deliberately
 * autoloaded — that option is read on nearly every request, so keeping it
 * in memory avoids a query per read (see Plugin::activate()). Secrets
 * (AI provider keys, the Google service-account key) don't belong in
 * that option: autoloading pulls them into memory on every request
 * whether or not that specific feature is even used on it, unnecessarily
 * widening the blast radius of anything that can read PHP memory.
 *
 * This class stores them instead in their own option, explicitly
 * `autoload => false`, so they're only loaded on the (comparatively rare)
 * request that actually needs one. `get()` falls back to the legacy
 * location inside `seo_wp_settings` for a value saved by an older version
 * of the plugin before this class existed — read-only, never written
 * back automatically. The next time that field is saved through the
 * admin UI, it moves to the new option for good (see AiSettings::
 * handle_save() / GoogleIndexingPage::handle_save(), which write via
 * set() and stop writing the key into seo_wp_settings).
 */
final class Secrets {

	/**
	 * Option key the secrets are stored under.
	 */
	private const OPTION_KEY = 'seo_wp_secrets';

	/**
	 * Legacy option key secrets used to live inside, before this class
	 * existed — kept here only as a one-way read fallback for sites
	 * upgrading from an older version.
	 */
	private const LEGACY_OPTION_KEY = 'seo_wp_settings';

	/**
	 * Reads a stored secret, or '' when unset in both the current and
	 * legacy storage location.
	 *
	 * @param string $key Secret key, e.g. 'ai_anthropic_key'.
	 */
	public static function get( string $key ): string {
		$secrets = get_option( self::OPTION_KEY, array() );
		$secrets = is_array( $secrets ) ? $secrets : array();

		if ( isset( $secrets[ $key ] ) && '' !== $secrets[ $key ] ) {
			return (string) $secrets[ $key ];
		}

		$legacy = get_option( self::LEGACY_OPTION_KEY, array() );
		$legacy = is_array( $legacy ) ? $legacy : array();

		return isset( $legacy[ $key ] ) ? (string) $legacy[ $key ] : '';
	}

	/**
	 * Whether a secret has any stored value, current or legacy — for a
	 * settings screen's "a key is already saved" hint, without needing
	 * the caller to read the value itself.
	 *
	 * @param string $key Secret key.
	 */
	public static function has( string $key ): bool {
		return '' !== self::get( $key );
	}

	/**
	 * Stores a secret in the dedicated, non-autoloaded option. Never
	 * writes back to the legacy location.
	 *
	 * @param string $key   Secret key.
	 * @param string $value Value to store.
	 */
	public static function set( string $key, string $value ): void {
		$secrets         = get_option( self::OPTION_KEY, array() );
		$secrets         = is_array( $secrets ) ? $secrets : array();
		$secrets[ $key ] = $value;

		update_option( self::OPTION_KEY, $secrets, false );
	}
}

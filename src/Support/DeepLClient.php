<?php
/**
 * Minimal HTTP client for the DeepL translation API.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

use WP_Error;

/**
 * Translates plain text via DeepL. Bring-your-own-key, same as AiClient —
 * never called unless a key is configured and an admin explicitly
 * triggers a translation.
 */
final class DeepLClient {

	/**
	 * Free-tier API endpoint.
	 */
	private const FREE_ENDPOINT = 'https://api-free.deepl.com/v2/translate';

	/**
	 * Pro (paid) tier API endpoint.
	 */
	private const PRO_ENDPOINT = 'https://api.deepl.com/v2/translate';

	/**
	 * Plain language codes DeepL requires a regional variant for — most
	 * codes (DE, FR, ES, ...) work as a bare uppercase ISO 639-1 code, but
	 * DeepL rejects a bare "EN" or "PT" as a *target* language. Only the
	 * two most common, well-documented cases; an unmapped code is passed
	 * through uppercased as-is, and a genuinely unsupported one simply
	 * surfaces as a WP_Error from the API rather than failing silently.
	 *
	 * @var array<string,string>
	 */
	private const REGIONAL_VARIANTS = array(
		'EN' => 'EN-GB',
		'PT' => 'PT-PT',
	);

	/**
	 * Translates one string.
	 *
	 * @param string $api_key     DeepL API key.
	 * @param string $text        Text to translate.
	 * @param string $target_lang Target language code — a plain ISO 639-1 code (e.g. 'de', 'fr') or an already-regional one (e.g. 'EN-GB') both work.
	 * @return string|WP_Error Translated text, or a WP_Error describing what went wrong.
	 */
	public static function translate( string $api_key, string $text, string $target_lang ) {
		$response = wp_remote_post(
			self::endpoint_for_key( $api_key ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'DeepL-Auth-Key ' . $api_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'text'        => $text,
					'target_lang' => self::normalize_target_lang( $target_lang ),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['message'] ) && ! isset( $body['translations'] ) ) {
			return new WP_Error( 'seo_wp_deepl_error', (string) $body['message'] );
		}

		$translated = $body['translations'][0]['text'] ?? null;
		if ( ! is_string( $translated ) || '' === $translated ) {
			return new WP_Error( 'seo_wp_deepl_error', __( 'DeepL returned no translation.', 'seo-wp' ) );
		}

		return $translated;
	}

	/**
	 * DeepL free-tier keys always end in ":fx" and must use the separate
	 * free-tier endpoint; anything else is a paid-tier key.
	 *
	 * @param string $api_key DeepL API key.
	 */
	public static function endpoint_for_key( string $api_key ): string {
		return self::is_free_tier_key( $api_key ) ? self::FREE_ENDPOINT : self::PRO_ENDPOINT;
	}

	/**
	 * Whether an API key is a free-tier key. Pure string logic,
	 * independent of WordPress, for easy unit testing.
	 *
	 * @param string $api_key DeepL API key.
	 */
	public static function is_free_tier_key( string $api_key ): bool {
		return (bool) preg_match( '/:fx$/', $api_key );
	}

	/**
	 * Uppercases a language code and applies the regional-variant mapping
	 * DeepL requires for a handful of target languages. Pure string
	 * logic, independent of WordPress, for easy unit testing.
	 *
	 * @param string $code Language code, e.g. 'de', 'EN', 'en-gb'.
	 */
	public static function normalize_target_lang( string $code ): string {
		$upper = strtoupper( $code );

		return self::REGIONAL_VARIANTS[ $upper ] ?? $upper;
	}
}

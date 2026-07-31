<?php
/**
 * Shared URL normalisation helpers.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

/**
 * Normalises trailing slashes and compares permalinks for equality. Also
 * the single place that routes a permalink through WPML's own filter
 * instead of hand-building translated URLs.
 */
final class Url {

	/**
	 * Normalises the path portion of a URL to either have or not have a
	 * trailing slash, leaving scheme, host, query and fragment untouched.
	 *
	 * @param string $url             URL to normalise.
	 * @param bool   $trailing_slash  Whether the path should end with a slash.
	 */
	public static function with_trailing_slash( string $url, bool $trailing_slash ): string {
		$parts = wp_parse_url( $url );

		if ( false === $parts || ! isset( $parts['path'] ) ) {
			$parts = array( 'path' => $url );
		}

		$path = $parts['path'];

		if ( '' === $path ) {
			$path = '/';
		}

		$path = $trailing_slash ? rtrim( $path, '/' ) . '/' : rtrim( $path, '/' );

		if ( '' === $path ) {
			$path = '/';
		}

		return self::rebuild( $parts, $path );
	}

	/**
	 * Compares two URLs for equality, ignoring scheme and a trailing slash
	 * difference on the path.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 */
	public static function equals( string $a, string $b ): bool {
		return self::comparable( $a ) === self::comparable( $b );
	}

	/**
	 * Routes a permalink through WPML's wpml_permalink filter instead of
	 * building a translated URL by hand. A no-op when WPML (or any other
	 * listener) is not present.
	 *
	 * @param string      $url       Permalink to translate.
	 * @param string|null $lang_code Language code, or null for the current language.
	 */
	public static function wpml_permalink( string $url, ?string $lang_code = null ): string {
		if ( ! function_exists( 'apply_filters' ) ) {
			return $url;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- this is WPML's own filter, not one of ours.
		return (string) apply_filters( 'wpml_permalink', $url, $lang_code, false );
	}

	/**
	 * Builds a comparable key for a URL: lowercased host, no scheme, no
	 * trailing slash on the path.
	 *
	 * @param string $url URL to normalise for comparison.
	 */
	private static function comparable( string $url ): string {
		$parts = wp_parse_url( $url );

		if ( false === $parts ) {
			return rtrim( $url, '/' );
		}

		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$path  = rtrim( $parts['path'] ?? '', '/' );
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return $host . $path . $query;
	}

	/**
	 * Rebuilds a URL from parsed components with a replacement path.
	 *
	 * @param array<string,string> $parts Components as returned by wp_parse_url().
	 * @param string               $path  Replacement path.
	 */
	private static function rebuild( array $parts, string $path ): string {
		$url = '';

		if ( isset( $parts['scheme'] ) ) {
			$url .= $parts['scheme'] . '://';
		}

		if ( isset( $parts['host'] ) ) {
			$url .= $parts['host'];
		}

		if ( isset( $parts['port'] ) ) {
			$url .= ':' . $parts['port'];
		}

		$url .= $path;

		if ( isset( $parts['query'] ) ) {
			$url .= '?' . $parts['query'];
		}

		if ( isset( $parts['fragment'] ) ) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}
}

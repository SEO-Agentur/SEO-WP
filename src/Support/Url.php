<?php
/**
 * Shared URL normalisation helpers.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

/**
 * Normalises trailing slashes and compares permalinks for equality. Also
 * the single place that routes a permalink through WPML's own filter
 * instead of hand-building translated URLs.
 */
final class Url {

	/**
	 * Builds the current request's front-end URL from the main query,
	 * without relying on any particular request type. Falls back to the
	 * site's home URL when called outside of a parsed request (e.g. before
	 * the main query has run).
	 */
	public static function current(): string {
		global $wp;

		if ( isset( $wp ) && is_object( $wp ) && isset( $wp->request ) ) {
			return home_url( add_query_arg( array(), $wp->request ) );
		}

		return home_url( '/' );
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
}

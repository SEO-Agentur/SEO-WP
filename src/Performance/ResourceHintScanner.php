<?php
/**
 * Scans the site's own rendered front page for external resource origins
 * worth a dns-prefetch/preconnect hint.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Performance;

/**
 * The Performance tab's "Resource hints" field is free text an admin
 * fills in by hand — this doesn't replace that, it just suggests what to
 * put there. Fetches the site's own front page (a real page load, not
 * WordPress's own script/style registries, which are only ever populated
 * for the specific request being rendered — a wp-admin request never has
 * the front end's actual enqueues loaded) and extracts every external
 * host referenced by a <script src> or <link href>, counted by how many
 * distinct resources come from it. A host with more than one resource is
 * suggested as preconnect (worth the extra connection setup); a host
 * with exactly one is suggested as the cheaper dns-prefetch. Never
 * applied automatically — only ever a suggestion an admin reviews and
 * adds to the resource hints field by hand.
 */
final class ResourceHintScanner {

	/**
	 * Fetches the site's front page and returns suggested hint lines,
	 * already in the exact syntax the "Resource hints" field expects.
	 *
	 * @return string[]|\WP_Error Suggested lines, or a WP_Error if the page couldn't be fetched.
	 */
	public static function scan() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			return new \WP_Error(
				'seo_wp_resource_hint_scan_failed',
				sprintf(
				/* translators: %d: HTTP status code. */
					__( 'The homepage returned HTTP %d.', 'seooptimierung-toolkit' ),
					$code
				)
			);
		}

		$html     = (string) wp_remote_retrieve_body( $response );
		$own_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return self::suggest_hints( self::external_host_counts( $html, $own_host ) );
	}

	/**
	 * Extracts every external host referenced by a <script src="..."> or
	 * <link href="..."> tag, with a count of how many distinct resource
	 * URLs came from it. Pure string logic, independent of WordPress, for
	 * easy unit testing.
	 *
	 * @param string $html     HTML to scan.
	 * @param string $own_host This site's own host, excluded from results.
	 * @return array<string,int> Host => distinct resource URL count, sorted by count descending.
	 */
	public static function external_host_counts( string $html, string $own_host ): array {
		$urls = array();

		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}
		if ( preg_match_all( '/<link[^>]+href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			$urls = array_merge( $urls, $matches[1] );
		}

		$own_host = strtolower( $own_host );
		$by_host  = array();

		foreach ( array_unique( $urls ) as $url ) {
			$host = strtolower( (string) parse_url( trim( $url ), PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.
			if ( '' === $host || $host === $own_host ) {
				continue;
			}

			$by_host[ $host ] = ( $by_host[ $host ] ?? 0 ) + 1;
		}

		arsort( $by_host );

		return $by_host;
	}

	/**
	 * Turns host => resource-count pairs into ready-to-paste hint lines:
	 * "preconnect" for a host with more than one resource, "dns-prefetch"
	 * for a host with exactly one.
	 *
	 * @param array<string,int> $host_counts Host => distinct resource URL count.
	 * @return string[]
	 */
	public static function suggest_hints( array $host_counts ): array {
		$lines = array();

		foreach ( $host_counts as $host => $count ) {
			$type    = $count > 1 ? 'preconnect' : 'dns-prefetch';
			$lines[] = $type . ' https://' . $host;
		}

		return $lines;
	}
}

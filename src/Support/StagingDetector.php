<?php
/**
 * Recognises common staging/development URL patterns.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

/**
 * A staging site accidentally left indexable is a classic, expensive SEO
 * mistake — duplicate content competing with the real site, or worse,
 * outranking it. This only ever *suggests* turning on site-wide noindex
 * (see Crawl\StagingProtection); it never flips anything on its own.
 * Deliberately a pattern match against well-known conventions, not a
 * hard guarantee — a site on an unusual domain won't be caught, and
 * that's fine, this is a helpful nudge, not a security boundary.
 */
final class StagingDetector {

	/**
	 * Host patterns strongly associated with a non-production
	 * environment. Anchored where it matters (e.g. "dev." as a
	 * subdomain, not any host merely containing "dev").
	 *
	 * @var string[]
	 */
	private const HOST_PATTERNS = array(
		'/^staging\./',
		'/\.staging\./',
		'/^staging-/',
		'/-staging\./',
		'/^stage\./',
		'/^dev\./',
		'/\.dev\./',
		'/^test\./',
		'/\.test$/',
		'/\.local$/',
		'/^localhost$/',
		'/\.localhost$/',
		'/\.wpengine\.com$/',
		'/\.kinsta\.cloud$/',
		'/\.flywheelsites\.com$/',
	);

	/**
	 * Whether a host looks like a staging/development environment. Pure
	 * string logic, independent of WordPress, for easy unit testing.
	 *
	 * @param string $host Hostname to check (no scheme/path).
	 */
	public static function looks_like_staging_host( string $host ): bool {
		$host = strtolower( trim( $host ) );

		if ( '' === $host ) {
			return false;
		}

		if ( self::is_ip_address( $host ) ) {
			return true;
		}

		foreach ( self::HOST_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $host ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a host is a raw IPv4 or IPv6 address rather than a domain
	 * name — sites are essentially never legitimately served to the
	 * public on a bare IP, but temporary/staging setups often are.
	 *
	 * @param string $host Hostname to check.
	 */
	public static function is_ip_address( string $host ): bool {
		return false !== filter_var( $host, FILTER_VALIDATE_IP );
	}
}

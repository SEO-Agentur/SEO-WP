<?php
/**
 * Records 404 hits so they can be turned into redirects.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

use SeoWp\Support\Url;

/**
 * A capped, path-keyed log of every URL that produced a 404 on the front
 * end, with a hit count and last-seen time — so a site owner can spot
 * broken incoming links and turn the worst offenders into redirects via
 * Crawl\Redirects, instead of losing that traffic silently.
 */
final class NotFoundMonitor {

	/**
	 * Option key the log is stored under.
	 */
	private const OPTION_KEY = 'seo_wp_404_log';

	/**
	 * Distinct URLs are dropped, least-recently-seen first, once the log
	 * exceeds this size.
	 */
	private const MAX_ENTRIES = 500;

	/**
	 * How far over MAX_ENTRIES the log is allowed to grow before it's
	 * actually trimmed. Trimming re-sorts the whole log by last_seen, an
	 * O(n log n) pass — without a buffer, that sort would run on every
	 * single insert once at capacity, letting anonymous, high-cardinality
	 * 404 traffic (a different bogus path each request) force a full sort
	 * on every hit. Batching the trim to once every BUFFER inserts past
	 * capacity keeps the log size bounded (never more than MAX_ENTRIES +
	 * BUFFER entries) while cutting how often that sort actually runs.
	 */
	private const TRIM_BUFFER = 50;

	/**
	 * Request paths longer than this are truncated before being used as a
	 * log key or stored — an attacker-chosen, arbitrarily long request
	 * path shouldn't be able to bloat a single option row.
	 */
	private const MAX_PATH_LENGTH = 500;

	/**
	 * Registers the template_redirect recorder. Runs after Crawl\Redirects
	 * (priority 1), so a URL that already has a matching redirect rule
	 * never reaches is_404() in the first place and is never logged here.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_record' ), 20 );
	}

	/**
	 * Records the current request path when it produced a 404.
	 */
	public function maybe_record(): void {
		if ( ! is_404() ) {
			return;
		}

		$path = self::current_path();
		if ( '' === $path ) {
			return;
		}
		if ( strlen( $path ) > self::MAX_PATH_LENGTH ) {
			$path = substr( $path, 0, self::MAX_PATH_LENGTH );
		}

		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_REFERER'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() is called here.
		if ( strlen( $referrer ) > self::MAX_PATH_LENGTH ) {
			$referrer = substr( $referrer, 0, self::MAX_PATH_LENGTH );
		}

		$log = self::all();

		if ( isset( $log[ $path ] ) ) {
			$log[ $path ]['hits']      = (int) $log[ $path ]['hits'] + 1;
			$log[ $path ]['last_seen'] = time();
			if ( '' !== $referrer ) {
				$log[ $path ]['referrer'] = $referrer;
			}
		} else {
			$log[ $path ] = array(
				'hits'       => 1,
				'first_seen' => time(),
				'last_seen'  => time(),
				'referrer'   => $referrer,
			);
		}

		if ( count( $log ) > self::MAX_ENTRIES + self::TRIM_BUFFER ) {
			uasort( $log, static fn ( array $a, array $b ): int => $a['last_seen'] <=> $b['last_seen'] );
			$log = array_slice( $log, -self::MAX_ENTRIES, null, true );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Every recorded entry, keyed by request path.
	 *
	 * @return array<string,array{hits:int,first_seen:int,last_seen:int,referrer:string}>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION_KEY, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Removes a single entry, e.g. once it's been turned into a redirect.
	 *
	 * @param string $path Request path, as stored (see current_path()).
	 */
	public static function forget( string $path ): void {
		$log = self::all();
		unset( $log[ $path ] );
		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Clears the entire log.
	 */
	public static function clear(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * The current request's path, normalised via normalize_path().
	 */
	public static function current_path(): string {
		return self::normalize_path( Url::current() );
	}

	/**
	 * Normalises a URL or bare path to a site-relative path with no
	 * scheme/host, no query string, a single leading slash, and no
	 * trailing slash (except the root itself). Crawl\Redirects normalises
	 * both the stored source path and the current request through this
	 * same method, so the two always compare on equal footing.
	 *
	 * @param string $url URL or path to normalise.
	 */
	public static function normalize_path( string $url ): string {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		return '/' . trim( $path, '/' );
	}
}

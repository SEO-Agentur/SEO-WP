<?php
/**
 * Runtime HTTP redirects and response headers: HTTPS/WWW/trailing-slash
 * canonicalization, security headers, CORS, HSTS and modern upload MIME
 * types.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Performance;

/**
 * Everything here is pure PHP and works on any web server. The five
 * server-level features that genuinely can't be done reliably from PHP
 * alone (Gzip, Brotli, browser-cache Expires headers, hotlink protection,
 * disabling directory browsing) live in Htaccess.php instead. Every
 * feature here is opt-in and off by default.
 */
final class ServerHeaders {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the redirect check, the response headers and the upload
	 * MIME type filter.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 0 );
		add_action( 'send_headers', array( $this, 'maybe_send_headers' ) );
		add_filter( 'upload_mimes', array( $this, 'maybe_add_modern_mime_types' ) );
	}

	/**
	 * The plugin settings array.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a given boolean setting is turned on (off by default).
	 *
	 * @param string $key Settings key.
	 */
	private function enabled( string $key ): bool {
		return ! empty( $this->settings()[ $key ] );
	}

	/**
	 * Applies the HTTPS, WWW, trailing-slash and canonical-normalization
	 * corrections to the current URL and, if anything changed, issues one
	 * combined 301 — never on wp-admin, AJAX, cron or a non-GET request,
	 * where a redirect would break rather than help.
	 */
	public function maybe_redirect(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( 'GET' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET' ) ) {
			return;
		}

		$current = $this->current_url();
		if ( '' === $current ) {
			return;
		}

		$target = $this->apply_https( $current );
		$target = $this->apply_www( $target );
		$target = $this->apply_trailing_slash( $target );
		$target = $this->apply_canonical_normalization( $target );

		if ( $target !== $current ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * The current request's URL, trusting the Host header only when it
	 * looks like a hostname — otherwise falling back to home_url()'s own
	 * host, since this value is used to build a redirect target.
	 */
	private function current_url(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '';
		if ( 1 !== preg_match( '/^[a-zA-Z0-9.-]+(:\d+)?$/', $host ) ) {
			$parsed = wp_parse_url( home_url() );
			$host   = is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
		}
		if ( '' === $host ) {
			return '';
		}

		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$scheme = is_ssl() ? 'https' : 'http';

		return $scheme . '://' . $host . $uri;
	}

	/**
	 * Forces the URL's scheme to https when configured.
	 *
	 * @param string $url URL to correct.
	 */
	private function apply_https( string $url ): string {
		return $this->enabled( 'force_https' ) ? set_url_scheme( $url, 'https' ) : $url;
	}

	/**
	 * Adds or strips a "www." host prefix per the configured mode.
	 *
	 * @param string $url URL to correct.
	 */
	private function apply_www( string $url ): string {
		$mode = $this->www_mode();
		if ( 'off' === $mode ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return $url;
		}

		$has_www = 0 === stripos( $parts['host'], 'www.' );
		if ( 'www' === $mode && ! $has_www ) {
			$parts['host'] = 'www.' . $parts['host'];
		} elseif ( 'non_www' === $mode && $has_www ) {
			$parts['host'] = substr( $parts['host'], 4 );
		} else {
			return $url;
		}

		return $this->rebuild_url( $parts );
	}

	/**
	 * The configured WWW mode, defaulting to 'off' for any unrecognized
	 * value.
	 */
	private function www_mode(): string {
		$value = isset( $this->settings()['www_canonical'] ) ? (string) $this->settings()['www_canonical'] : 'off';

		return in_array( $value, array( 'www', 'non_www' ), true ) ? $value : 'off';
	}

	/**
	 * Adds or removes a trailing slash, skipping anything that looks like
	 * a real file (a "." in its last path segment) — a trailing slash on
	 * /sitemap.xml or /{indexnow-key}.txt would otherwise break this
	 * plugin's own sitemaps and IndexNow key file, among other things.
	 *
	 * @param string $url URL to correct.
	 */
	private function apply_trailing_slash( string $url ): string {
		$mode = $this->trailing_slash_mode();
		if ( 'off' === $mode ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) || '' === $parts['path'] ) {
			return $url;
		}

		$path         = $parts['path'];
		$slash_pos    = strrpos( $path, '/' );
		$last_segment = false !== $slash_pos ? substr( $path, $slash_pos ) : $path;
		if ( false !== strpos( $last_segment, '.' ) ) {
			return $url;
		}

		if ( 'add' === $mode && '/' !== substr( $path, -1 ) ) {
			$parts['path'] = $path . '/';
		} elseif ( 'remove' === $mode && '/' !== $path && '/' === substr( $path, -1 ) ) {
			$parts['path'] = rtrim( $path, '/' );
		} else {
			return $url;
		}

		return $this->rebuild_url( $parts );
	}

	/**
	 * The configured trailing-slash mode, defaulting to 'off' for any
	 * unrecognized value.
	 */
	private function trailing_slash_mode(): string {
		$value = isset( $this->settings()['trailing_slash'] ) ? (string) $this->settings()['trailing_slash'] : 'off';

		return in_array( $value, array( 'add', 'remove' ), true ) ? $value : 'off';
	}

	/**
	 * Collapses repeated slashes in the path — a light complement to
	 * WordPress core's own redirect_canonical(), which this doesn't
	 * replace or duplicate.
	 *
	 * @param string $url URL to correct.
	 */
	private function apply_canonical_normalization( string $url ): string {
		if ( ! $this->enabled( 'enable_canonical_redirects' ) ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['path'] ) ) {
			return $url;
		}

		$normalized = (string) preg_replace( '#/{2,}#', '/', $parts['path'] );
		if ( $normalized === $parts['path'] ) {
			return $url;
		}

		$parts['path'] = $normalized;

		return $this->rebuild_url( $parts );
	}

	/**
	 * Reassembles a URL from its wp_parse_url()-shaped components.
	 *
	 * @param array<string,mixed> $parts wp_parse_url()-shaped URL components.
	 */
	private function rebuild_url( array $parts ): string {
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		return $scheme . $host . $port . $path . $query;
	}

	/**
	 * Sends the configured security headers, HSTS and CORS headers.
	 */
	public function maybe_send_headers(): void {
		if ( $this->enabled( 'enable_security_headers' ) ) {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			header( 'Permissions-Policy: geolocation=(), camera=(), microphone=()' );
		}

		if ( $this->enabled( 'enable_hsts' ) && is_ssl() ) {
			$directive = 'max-age=31536000';
			if ( $this->enabled( 'hsts_include_subdomains' ) ) {
				$directive .= '; includeSubDomains';
			}
			header( 'Strict-Transport-Security: ' . $directive );
		}

		if ( $this->enabled( 'enable_cors' ) ) {
			$origin = $this->cors_origin();
			// Fails closed rather than defaulting to a wildcard: enabling
			// CORS with the origin field left blank sends no CORS headers
			// at all, instead of silently becoming the most permissive
			// possible policy ("*"). An explicit origin must be configured.
			if ( '' !== $origin ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Vary: Origin' );
			}
		}
	}

	/**
	 * The configured CORS origin, or '' when none is set.
	 */
	private function cors_origin(): string {
		return isset( $this->settings()['cors_allowed_origin'] ) ? trim( (string) $this->settings()['cors_allowed_origin'] ) : '';
	}

	/**
	 * Adds a handful of common upload MIME types WordPress core doesn't
	 * allow by default.
	 *
	 * @param array<string,string> $mimes Extension => MIME type map, as passed by the filter.
	 * @return array<string,string>
	 */
	public function maybe_add_modern_mime_types( array $mimes ): array {
		if ( ! $this->enabled( 'enable_modern_mime_types' ) ) {
			return $mimes;
		}

		return array_merge(
			$mimes,
			array(
				'webp'  => 'image/webp',
				'avif'  => 'image/avif',
				'woff'  => 'font/woff',
				'woff2' => 'font/woff2',
				'json'  => 'application/json',
				'svg'   => 'image/svg+xml',
			)
		);
	}
}

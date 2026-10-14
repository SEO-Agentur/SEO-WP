<?php
/**
 * Security response header configuration (pure logic, no WordPress calls).
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Performance;

/**
 * Turns the saved "Security" tab settings into the exact header lines to
 * send. Kept free of WordPress calls so every branch is unit-testable:
 * ServerHeaders passes in the settings array and the two request facts
 * (HTTPS, front end) and just emits whatever comes back.
 *
 * Sites that saved the older single "Send security headers" / "Send HSTS"
 * checkboxes keep working unchanged: until the Security tab itself has
 * been saved once, each individual header falls back to that legacy flag.
 */
final class SecurityHeaderConfig {

	/**
	 * Valid Referrer-Policy tokens.
	 *
	 * @var string[]
	 */
	public const REFERRER_POLICIES = array(
		'no-referrer',
		'no-referrer-when-downgrade',
		'origin',
		'origin-when-cross-origin',
		'same-origin',
		'strict-origin',
		'strict-origin-when-cross-origin',
		'unsafe-url',
	);

	/**
	 * Valid X-Frame-Options values.
	 *
	 * @var string[]
	 */
	public const FRAME_OPTIONS = array( 'SAMEORIGIN', 'DENY' );

	/**
	 * Default Permissions-Policy.
	 */
	public const DEFAULT_PERMISSIONS_POLICY = 'geolocation=(), camera=(), microphone=()';

	/**
	 * Default Content-Security-Policy: allows what a typical WordPress
	 * front end needs (inline scripts/styles, https images and embeds)
	 * while still blocking plugins/objects and foreign framing. Shipped
	 * in Report-Only mode so nothing breaks until the owner has looked at
	 * the browser console and tightened or loosened it.
	 */
	public const DEFAULT_CSP = "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; media-src 'self' https:; frame-src 'self' https:; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";

	/**
	 * One year in seconds: the minimum max-age browsers require for HSTS
	 * preload.
	 */
	public const HSTS_PRELOAD_MIN_AGE = 31536000;

	/**
	 * Default HSTS max-age (one year).
	 */
	public const HSTS_DEFAULT_MAX_AGE = 31536000;

	/**
	 * Header name => value for every header that should be sent.
	 *
	 * @param array<string,mixed> $settings The plugin settings array.
	 * @param bool                $is_ssl   Whether the current request is HTTPS (HSTS is only ever sent over HTTPS).
	 * @param bool                $frontend Whether this is a public front-end request (the CSP is never sent on wp-admin/login screens).
	 * @return array<string,string>
	 */
	public static function headers( array $settings, bool $is_ssl, bool $frontend ): array {
		$out = array();

		if ( self::flag( $settings, 'sec_x_content_type_options', 'enable_security_headers' ) ) {
			$out['X-Content-Type-Options'] = 'nosniff';
		}

		if ( self::flag( $settings, 'sec_x_frame_options', 'enable_security_headers' ) ) {
			$out['X-Frame-Options'] = self::choice( $settings, 'sec_x_frame_options_value', self::FRAME_OPTIONS, 'SAMEORIGIN' );
		}

		if ( self::flag( $settings, 'sec_referrer_policy', 'enable_security_headers' ) ) {
			$out['Referrer-Policy'] = self::choice( $settings, 'sec_referrer_policy_value', self::REFERRER_POLICIES, 'strict-origin-when-cross-origin' );
		}

		if ( self::flag( $settings, 'sec_permissions_policy', 'enable_security_headers' ) ) {
			$policy = self::clean_value( (string) ( $settings['sec_permissions_policy_value'] ?? '' ) );
			if ( '' !== $policy ) {
				$out['Permissions-Policy'] = $policy;
			} elseif ( ! array_key_exists( 'sec_permissions_policy_value', $settings ) ) {
				$out['Permissions-Policy'] = self::DEFAULT_PERMISSIONS_POLICY;
			}
		}

		if ( $is_ssl && self::flag( $settings, 'sec_hsts', 'enable_hsts' ) ) {
			$out['Strict-Transport-Security'] = self::hsts_value( $settings );
		}

		if ( $frontend && ! empty( $settings['sec_csp'] ) ) {
			$policy = self::clean_value( (string) ( $settings['sec_csp_policy'] ?? '' ) );
			if ( '' === $policy ) {
				$policy = self::DEFAULT_CSP;
			}

			$report_only = ! array_key_exists( 'sec_csp_report_only', $settings ) || ! empty( $settings['sec_csp_report_only'] );
			$name        = $report_only ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

			$out[ $name ] = $policy;
		}

		return $out;
	}

	/**
	 * Header names shown in the live check, in display order.
	 *
	 * @var string[]
	 */
	public const CHECKED_HEADERS = array(
		'X-Content-Type-Options',
		'X-Frame-Options',
		'Referrer-Policy',
		'Permissions-Policy',
		'Strict-Transport-Security',
		'Content-Security-Policy',
		'Content-Security-Policy-Report-Only',
	);

	/**
	 * Interprets the response headers of a real request: per checked
	 * header whether it arrived, with what value, and whether it arrived
	 * more than once (another plugin or the web server sending it too).
	 * When the headers this plugin is configured to send are passed in,
	 * a value that differs from the configured one is reported too —
	 * something in front of WordPress (a CDN, reverse proxy or the web
	 * server) is replacing it.
	 *
	 * States: "ok" (arrived, as configured), "duplicate" (arrived more than
	 * once), "overridden" (arrived with a different value than configured),
	 * "missing" (configured but did not arrive), "foreign" (arrived but is
	 * not configured here), "absent" (neither configured nor sent). Without
	 * $expected only "ok", "duplicate" and "missing" (= not sent) are used.
	 *
	 * @param array<string,string|string[]> $received Response headers keyed by lowercase name.
	 * @param array<string,string>|null     $expected Headers this plugin is configured to send (name => value), or null to skip the comparison.
	 * @return array<string,array{state:string,value:string}> Header => state and received value.
	 */
	public static function check_report( array $received, ?array $expected = null ): array {
		$report = array();

		foreach ( self::CHECKED_HEADERS as $name ) {
			$raw      = $received[ strtolower( $name ) ] ?? null;
			$wanted   = null !== $expected ? ( $expected[ $name ] ?? null ) : null;
			$compared = null !== $expected;

			if ( null === $raw || array() === $raw || '' === $raw ) {
				$report[ $name ] = array(
					'state' => $compared && null === $wanted ? 'absent' : 'missing',
					'value' => '',
				);
				continue;
			}

			$values = is_array( $raw ) ? array_values( array_map( 'strval', $raw ) ) : array( (string) $raw );
			$count  = count( $values );

			// Several response headers of the same name are folded into one
			// comma-separated string by WordPress' HTTP API. Content-Security-Policy
			// and Permissions-Policy use commas inside a single value, so only
			// the single-value headers are split.
			if ( 1 === $count && ! in_array( $name, array( 'Content-Security-Policy', 'Content-Security-Policy-Report-Only', 'Permissions-Policy' ), true ) ) {
				$count = count( array_filter( array_map( 'trim', explode( ',', $values[0] ) ), 'strlen' ) );
			}

			$value = implode( ', ', $values );
			$state = 'ok';

			if ( $count > 1 ) {
				$state = 'duplicate';
			} elseif ( $compared && null === $wanted ) {
				$state = 'foreign';
			} elseif ( $compared && self::normalise( $wanted ) !== self::normalise( $value ) ) {
				$state = 'overridden';
			}

			$report[ $name ] = array(
				'state' => $state,
				'value' => $value,
			);
		}

		return $report;
	}

	/**
	 * Comparable form of a header value: lowercase, single spaces, no
	 * space around commas/semicolons.
	 *
	 * @param string $value Header value.
	 */
	private static function normalise( string $value ): string {
		$value = strtolower( self::clean_value( $value ) );

		return (string) preg_replace( '/\s*([,;])\s*/', '$1', $value );
	}

	/**
	 * The Strict-Transport-Security value for the given settings.
	 *
	 * @param array<string,mixed> $settings The plugin settings array.
	 */
	public static function hsts_value( array $settings ): string {
		$max_age = self::hsts_max_age( $settings );
		$value   = 'max-age=' . $max_age;

		$subdomains = array_key_exists( 'sec_hsts_subdomains', $settings )
			? ! empty( $settings['sec_hsts_subdomains'] )
			: ! empty( $settings['hsts_include_subdomains'] );

		if ( $subdomains ) {
			$value .= '; includeSubDomains';
		}

		// The browsers' preload list only accepts a policy with includeSubDomains and a
		// max-age of at least a year; anything less would be rejected there anyway.
		if ( $subdomains && $max_age >= self::HSTS_PRELOAD_MIN_AGE && ! empty( $settings['sec_hsts_preload'] ) ) {
			$value .= '; preload';
		}

		return $value;
	}

	/**
	 * The configured HSTS max-age in seconds.
	 *
	 * @param array<string,mixed> $settings The plugin settings array.
	 */
	public static function hsts_max_age( array $settings ): int {
		if ( ! isset( $settings['sec_hsts_max_age'] ) || '' === (string) $settings['sec_hsts_max_age'] ) {
			return self::HSTS_DEFAULT_MAX_AGE;
		}

		return max( 0, min( 63072000, (int) $settings['sec_hsts_max_age'] ) );
	}

	/**
	 * Strips control characters (header injection) and collapses runs of
	 * whitespace to one space.
	 *
	 * @param string $value Raw value.
	 */
	public static function clean_value( string $value ): string {
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $value );

		return trim( (string) preg_replace( '/\s+/', ' ', $value ) );
	}

	/**
	 * Whether one header is on: its own checkbox once the Security tab
	 * has been saved, the older combined checkbox before that.
	 *
	 * @param array<string,mixed> $settings    The plugin settings array.
	 * @param string              $key         Per-header settings key.
	 * @param string              $legacy_key  Older combined settings key.
	 */
	private static function flag( array $settings, string $key, string $legacy_key ): bool {
		if ( array_key_exists( $key, $settings ) ) {
			return ! empty( $settings[ $key ] );
		}

		return ! empty( $settings[ $legacy_key ] );
	}

	/**
	 * A stored value restricted to an allow-list.
	 *
	 * @param array<string,mixed> $settings The plugin settings array.
	 * @param string              $key      Settings key.
	 * @param string[]            $allowed  Accepted values.
	 * @param string              $fallback Value used when unset or not allowed.
	 */
	private static function choice( array $settings, string $key, array $allowed, string $fallback ): string {
		$value = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}

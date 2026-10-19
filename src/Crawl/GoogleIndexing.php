<?php
/**
 * Google Indexing API pings on publish/update/delete.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

use Seooptimierung\Toolkit\Schema\EmbeddedSchema;
use Seooptimierung\Toolkit\Meta\Robots;
use Seooptimierung\Toolkit\Support\ErrorLog;
use Seooptimierung\Toolkit\Support\Secrets;
use WP_Post;

/**
 * Submits URLs to Google's Indexing API (https://developers.google.com/
 * search/apis/indexing-api) using a Google Cloud service account: builds
 * and signs the RS256 JWT assertion, exchanges it for a short-lived OAuth2
 * access token (cached until near expiry), and pings the publish endpoint
 * on publish, update and delete of any configured, indexable post type.
 * Google officially scopes this API to JobPosting/BroadcastEvent pages,
 * but accepts submissions for any URL; like IndexNow, submission is
 * fire-and-forget so a wp-admin save is never slowed down by it.
 */
final class GoogleIndexing {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option key the cached access token is stored under.
	 */
	private const TOKEN_OPTION = 'seo_wp_google_indexing_token';

	/**
	 * Option key the submission history is stored under.
	 */
	private const LOG_OPTION = 'seo_wp_google_indexing_log';

	/**
	 * Oldest log entries are dropped once the log exceeds this size.
	 */
	private const MAX_LOG_ENTRIES = 200;

	/**
	 * Google's OAuth2 token endpoint.
	 */
	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/**
	 * The Indexing API's publish endpoint.
	 */
	private const PUBLISH_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/**
	 * OAuth2 scope requested for the service account token.
	 */
	private const SCOPE = 'https://www.googleapis.com/auth/indexing';

	/**
	 * Registers the publish/update/delete pings.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'maybe_ping_on_publish' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'ping_before_delete' ) );
	}

	/**
	 * Pings URL_UPDATED on every save that leaves a post published.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status (unused).
	 * @param WP_Post $post       The post being saved.
	 */
	public function maybe_ping_on_publish( string $new_status, string $old_status, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $old_status is part of the transition_post_status hook signature.
		if ( 'publish' !== $new_status || ! $this->is_eligible( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit( $permalink, 'URL_UPDATED' );
		}
	}

	/**
	 * Pings URL_DELETED just before a published post is deleted.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function ping_before_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->is_eligible( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit( $permalink, 'URL_DELETED' );
		}
	}

	/**
	 * Whether a post should trigger a ping: the feature is enabled and
	 * configured, the post type is one of the configured ones, and the
	 * post isn't marked noindex.
	 *
	 * @param WP_Post $post Post to check.
	 */
	private function is_eligible( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || ! $this->enabled() ) {
			return false;
		}

		if ( ! in_array( $post->post_type, $this->configured_post_types(), true ) ) {
			return false;
		}

		if ( '1' === get_post_meta( $post->ID, Robots::META_NOINDEX, true ) ) {
			return false;
		}

		// Google supports this API only for job postings and live streams. By
		// default only such pages are submitted; switching that off sends every
		// page of the configured post types (unsupported by Google).
		$settings = get_option( 'seo_wp_settings', array() );
		if ( is_array( $settings ) && array_key_exists( 'google_indexing_only_eligible', $settings ) && empty( $settings['google_indexing_only_eligible'] ) ) {
			return true;
		}

		/**
		 * Filters whether a page counts as a job posting or live stream page
		 * for the Google Indexing API — for markup this plugin does not know
		 * about (a theme's own JobPosting output, say).
		 *
		 * @param bool    $eligible Whether the page is supported, as far as this plugin can tell.
		 * @param WP_Post $post     The page.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		return (bool) apply_filters( 'seooptimierung/indexing/google_eligible', EmbeddedSchema::is_indexing_api_page( $post ), $post );
	}

	/**
	 * Whether Google Indexing is enabled and has a usable service account
	 * key configured.
	 */
	private function enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! empty( $settings['enable_google_indexing'] ) && array() !== $this->service_account_key();
	}

	/**
	 * Post types the admin has enabled for submission, defaulting to
	 * posts and pages.
	 *
	 * @return string[]
	 */
	private function configured_post_types(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		return isset( $settings['google_indexing_post_types'] ) && is_array( $settings['google_indexing_post_types'] )
			? $settings['google_indexing_post_types']
			: array( 'post', 'page' );
	}

	/**
	 * Submits a URL, fire-and-forget so the current request is never
	 * slowed down by it.
	 *
	 * @param string $url  URL to submit.
	 * @param string $type 'URL_UPDATED' or 'URL_DELETED'.
	 */
	private function submit( string $url, string $type ): void {
		$token = $this->access_token();
		if ( '' === $token ) {
			return;
		}

		wp_remote_post(
			self::PUBLISH_ENDPOINT,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				),
				'body'     => wp_json_encode(
					array(
						'url'  => $url,
						'type' => $type,
					)
				),
			)
		);

		self::log( $url, $type );
	}

	/**
	 * Resolves a usable OAuth2 access token, reusing the cached one until
	 * it's within 30 seconds of expiry.
	 */
	private function access_token(): string {
		$cached = get_option( self::TOKEN_OPTION, array() );
		if ( is_array( $cached ) && isset( $cached['access_token'], $cached['expires_at'] ) && (int) $cached['expires_at'] > time() + 30 ) {
			return (string) $cached['access_token'];
		}

		$key          = $this->service_account_key();
		$client_email = isset( $key['client_email'] ) ? (string) $key['client_email'] : '';
		$private_key  = isset( $key['private_key'] ) ? (string) $key['private_key'] : '';

		if ( '' === $client_email || '' === $private_key ) {
			return '';
		}

		$jwt = self::build_jwt( $client_email, $private_key );
		if ( '' === $jwt ) {
			ErrorLog::record( 'google-indexing:jwt', 'Failed to sign the JWT assertion; the private key may be invalid.' );
			return '';
		}

		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => 5,
				'body'    => array(
					'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
					'assertion'  => $jwt,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			ErrorLog::record( 'google-indexing:token', $response->get_error_message() );
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['access_token'] ) ) {
			ErrorLog::record(
				'google-indexing:token',
				isset( $body['error_description'] ) ? (string) $body['error_description'] : 'Token endpoint returned no access_token.'
			);
			return '';
		}

		$access_token = (string) $body['access_token'];
		$expires_in   = isset( $body['expires_in'] ) ? (int) $body['expires_in'] : 3600;

		update_option(
			self::TOKEN_OPTION,
			array(
				'access_token' => $access_token,
				'expires_at'   => time() + $expires_in,
			),
			false
		);

		return $access_token;
	}

	/**
	 * Reads and JSON-decodes the configured service account key.
	 *
	 * @return array<string,mixed>
	 */
	private function service_account_key(): array {
		$raw = Secrets::get( 'google_indexing_key' );

		if ( '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Builds and signs an RS256 JWT assertion for the service account
	 * "JWT profile for OAuth 2.0" flow (RFC 7523), pure logic independent
	 * of WordPress beyond the openssl_* extension, for easy unit testing.
	 *
	 * @param string $client_email Service account email (the JWT issuer).
	 * @param string $private_key  Service account PEM-encoded RSA private key.
	 */
	public static function build_jwt( string $client_email, string $private_key ): string {
		$now = time();

		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);
		$claims = array(
			'iss'   => $client_email,
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_ENDPOINT,
			'iat'   => $now,
			'exp'   => $now + HOUR_IN_SECONDS,
		);

		// PHP's openssl_* functions accept not just an inline PEM string but
		// also a file://(or other stream-wrapper, notably phar://) path as
		// this argument. Rejecting anything that doesn't look like an
		// actual PEM key before it ever reaches openssl_pkey_get_private()
		// stops a pasted "private_key" value from being interpreted as a
		// path instead of key material.
		if ( 1 !== preg_match( '/^-----BEGIN [A-Z ]*PRIVATE KEY-----/', trim( $private_key ) ) ) {
			return '';
		}

		$signing_input = self::base64url( (string) wp_json_encode( $header ) ) . '.' . self::base64url( (string) wp_json_encode( $claims ) );

		$key_resource = openssl_pkey_get_private( $private_key );
		if ( false === $key_resource ) {
			return '';
		}

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $key_resource, OPENSSL_ALGO_SHA256 );

		return $signed ? $signing_input . '.' . self::base64url( $signature ) : '';
	}

	/**
	 * Base64url-encodes a string per RFC 4648 §5 (no padding).
	 *
	 * @param string $data Raw data to encode.
	 */
	private static function base64url( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding is part of the JWT spec (RFC 7519), not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Records a submission in the capped history log.
	 *
	 * @param string $url  URL submitted.
	 * @param string $type 'URL_UPDATED' or 'URL_DELETED'.
	 */
	private static function log( string $url, string $type ): void {
		$entries   = self::log_entries();
		$entries[] = array(
			'time' => time(),
			'url'  => $url,
			'type' => $type,
		);

		if ( count( $entries ) > self::MAX_LOG_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $entries, false );
	}

	/**
	 * Every recorded submission, oldest first.
	 *
	 * @return array<int,array{time:int,url:string,type:string}>
	 */
	public static function log_entries(): array {
		$entries = get_option( self::LOG_OPTION, array() );

		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Clears the submission history log.
	 */
	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}
}

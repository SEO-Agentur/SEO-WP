<?php
/**
 * IndexNow key file and publish/update/delete pings.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

use Seooptimierung\Toolkit\Meta\Robots;
use WP_Post;
use WP_Post_Type;

/**
 * Implements the IndexNow protocol (supported by Bing, Yandex, Seznam and
 * Naver — Google does not participate): serves the required key
 * verification file at /{key}.txt, pings the shared endpoint on publish,
 * update and delete of any public, indexable post without hardcoding a
 * post type name, and additionally exposes submit_urls() so the admin
 * screen (Seooptimierung\Toolkit\Admin\IndexNowPage) can trigger a manual or one-click bulk
 * submission for arbitrary URLs, post types and taxonomies. Not part of
 * the classic sitemap ping (Google/Bing retired those endpoints); this is
 * a separate, still-active protocol.
 */
final class IndexNow {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option key holding the auto-generated IndexNow key (used unless a
	 * custom key is set in the settings array).
	 */
	private const KEY_OPTION = 'seo_wp_indexnow_key';

	/**
	 * Option key holding the submission history log.
	 */
	private const LOG_OPTION = 'seo_wp_indexnow_log';

	/**
	 * Oldest log entries are dropped once the log exceeds this size.
	 */
	private const MAX_LOG_ENTRIES = 200;

	/**
	 * Shared IndexNow endpoint; fans out to every participating engine.
	 */
	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Maximum URLs accepted in a single IndexNow request; larger lists are
	 * split into chunks of this size.
	 */
	private const MAX_URLS_PER_REQUEST = 10000;

	/**
	 * Registers the key file endpoint and the publish/update/delete pings.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		// Priority 1: see the identical comment in Sitemaps\Router::register()
		// — core's redirect_canonical() runs at the default priority (10)
		// and, registered earlier in the boot sequence, wins any tie there,
		// 301-redirecting this virtual route before this callback ever
		// runs. Search engines verifying this key file don't follow
		// redirects, so this isn't just cosmetic — it silently breaks
		// IndexNow verification entirely on a site with no matching rule
		// already resolved by the time redirect_canonical looks at it.
		add_action( 'template_redirect', array( $this, 'maybe_render_key_file' ), 1 );

		add_action( 'transition_post_status', array( $this, 'maybe_ping_on_publish' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'ping_before_delete' ) );
	}

	/**
	 * Adds the rewrite rule for the key verification file.
	 */
	public function add_rewrite_rules(): void {
		add_rewrite_rule( '^([a-f0-9]{16,64})\.txt$', 'index.php?seo_wp_indexnow_key=$matches[1]', 'top' );
	}

	/**
	 * Registers the seo_wp_indexnow_key query var.
	 *
	 * @param string[] $vars Query vars known to WordPress core.
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'seo_wp_indexnow_key';

		return $vars;
	}

	/**
	 * Serves the key file when the requested filename matches this site's
	 * key; any other {hex}.txt request falls through to a normal 404.
	 */
	public function maybe_render_key_file(): void {
		$requested = (string) get_query_var( 'seo_wp_indexnow_key' );
		if ( '' === $requested || $requested !== $this->key() ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $this->key() );
		exit;
	}

	/**
	 * Pings on every save that leaves a post published — both a first
	 * publish and a later edit of already-published content, since
	 * WordPress fires this hook on both.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status (unused).
	 * @param WP_Post $post       The post being saved.
	 */
	public function maybe_ping_on_publish( string $new_status, string $old_status, WP_Post $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $old_status is part of the transition_post_status hook signature.
		if ( 'publish' !== $new_status || ! $this->enabled() || ! self::is_indexable( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit_fire_and_forget( array( $permalink ), __( 'Publish/update', 'seooptimierung-toolkit' ) );
		}
	}

	/**
	 * Pings a removal just before a published post is deleted, while its
	 * permalink can still be resolved.
	 *
	 * @param int $post_id Post being deleted.
	 */
	public function ping_before_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->enabled() || ! self::is_indexable( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit_fire_and_forget( array( $permalink ), __( 'Delete', 'seooptimierung-toolkit' ) );
		}
	}

	/**
	 * Whether a post is a candidate for submission at all: not a
	 * revision/autosave, a public post type, and not marked noindex. Used
	 * both by the automatic hooks above (further gated by enabled()) and
	 * by the admin screen's one-click bulk submission (which intentionally
	 * ignores the "enable automatic pings" toggle — a manual action should
	 * always be able to run).
	 *
	 * @param WP_Post $post Post to check.
	 */
	public static function is_indexable( WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type || ! $post_type_object->public ) {
			return false;
		}

		return '1' !== get_post_meta( $post->ID, Robots::META_NOINDEX, true );
	}

	/**
	 * Whether automatic IndexNow pings are enabled (on by default). Does
	 * not gate manual submission via submit_urls().
	 */
	public function enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! isset( $settings['enable_indexnow'] ) || ! empty( $settings['enable_indexnow'] );
	}

	/**
	 * Submits a list of URLs for the automatic publish/update/delete
	 * hooks, fire-and-forget so the current request is never slowed down
	 * by a third-party API call. Since the response is never read, the
	 * log entry records that a submission was sent, not that it was
	 * confirmed accepted — see submit_urls() for a confirmed result.
	 *
	 * @param string[] $urls   URLs to submit.
	 * @param string   $source Short label recorded in the history log.
	 */
	private function submit_fire_and_forget( array $urls, string $source ): void {
		if ( array() === $urls ) {
			return;
		}

		$this->send_chunk( $urls, false );
		self::log( $source, count( $urls ), true, '' );
	}

	/**
	 * Submits a list of URLs to IndexNow, blocking so the caller can
	 * report a real result — used by the admin screen's manual paste-box
	 * and one-click bulk submission, never by the automatic hooks (which
	 * must never slow down a wp-admin save). Lists longer than
	 * MAX_URLS_PER_REQUEST are split into multiple requests, each logged
	 * and reported separately.
	 *
	 * @param string[] $urls   URLs to submit.
	 * @param string   $source Short label recorded in the history log.
	 * @return array{submitted:int,ok:bool,error:string}
	 */
	public function submit_urls( array $urls, string $source ): array {
		$urls = array_values( array_unique( array_filter( array_map( 'trim', $urls ) ) ) );
		if ( array() === $urls ) {
			return array(
				'submitted' => 0,
				'ok'        => true,
				'error'     => '',
			);
		}

		$submitted = 0;
		$ok        = true;
		$error     = '';

		foreach ( array_chunk( $urls, self::MAX_URLS_PER_REQUEST ) as $chunk ) {
			$response = $this->send_chunk( $chunk, true );

			if ( is_wp_error( $response ) ) {
				$ok    = false;
				$error = $response->get_error_message();
				self::log( $source, count( $chunk ), false, $error );
				continue;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$chunk_ok = $code >= 200 && $code < 300;

			if ( ! $chunk_ok ) {
				$ok = false;
				/* translators: %d: HTTP status code returned by the IndexNow endpoint. */
				$error = sprintf( __( 'IndexNow responded with HTTP %d.', 'seooptimierung-toolkit' ), $code );
			}

			self::log( $source, count( $chunk ), $chunk_ok, $chunk_ok ? '' : $error );
			$submitted += count( $chunk );
		}

		return array(
			'submitted' => $submitted,
			'ok'        => $ok,
			'error'     => $error,
		);
	}

	/**
	 * Sends one IndexNow request for up to MAX_URLS_PER_REQUEST URLs.
	 *
	 * @param string[] $urls     URLs to submit (already chunked by the caller).
	 * @param bool     $blocking Whether to wait for and return the response.
	 * @return array<string,mixed>|\WP_Error wp_remote_post()'s return value.
	 */
	private function send_chunk( array $urls, bool $blocking ) {
		$key  = $this->key();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$body = array(
			'host'        => is_string( $host ) ? $host : '',
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_values( $urls ),
		);

		return wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'  => $blocking ? 15 : 3,
				'blocking' => $blocking,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * Resolves this site's IndexNow key: a custom key set on the admin
	 * screen takes precedence, otherwise one is generated and persisted on
	 * first use. Public so the admin screen can display the effective key
	 * and its verification file URL.
	 */
	public function key(): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$custom   = isset( $settings['indexnow_key'] ) ? (string) $settings['indexnow_key'] : '';
		if ( '' !== $custom ) {
			return $custom;
		}

		$key = get_option( self::KEY_OPTION, '' );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::KEY_OPTION, $key, true );

		return $key;
	}

	/**
	 * Validates and normalizes a custom key entered on the admin screen:
	 * lowercased, and only accepted if it matches the rewrite rule
	 * registered in add_rewrite_rules() (16-64 hex characters) — a key
	 * that doesn't match would never be reachable at /{key}.txt, breaking
	 * IndexNow's verification.
	 *
	 * @param string $raw Raw input from the settings form.
	 * @return string The normalized key, or '' if invalid/empty.
	 */
	public static function sanitize_key( string $raw ): string {
		$key = strtolower( trim( $raw ) );

		return 1 === preg_match( '/^[a-f0-9]{16,64}$/', $key ) ? $key : '';
	}

	/**
	 * Records a submission attempt in the capped history log. One entry
	 * per request sent (not per URL) — a bulk submission of thousands of
	 * URLs logs one row per chunk, not one per URL.
	 *
	 * @param string $source Short label, e.g. "Manual paste", "Bulk: Products", "Publish/update".
	 * @param int    $count  Number of URLs in this submission.
	 * @param bool   $ok     Whether the submission succeeded (or, for fire-and-forget pings, was sent).
	 * @param string $detail Error detail, if any.
	 */
	public static function log( string $source, int $count, bool $ok, string $detail = '' ): void {
		$entries   = self::log_entries();
		$entries[] = array(
			'time'   => time(),
			'source' => $source,
			'count'  => $count,
			'ok'     => $ok,
			'detail' => $detail,
		);

		if ( count( $entries ) > self::MAX_LOG_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_LOG_ENTRIES );
		}

		update_option( self::LOG_OPTION, $entries, false );
	}

	/**
	 * Every recorded submission, oldest first.
	 *
	 * @return array<int,array{time:int,source:string,count:int,ok:bool,detail:string}>
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

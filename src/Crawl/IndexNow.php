<?php
/**
 * IndexNow key file and publish/update/delete pings.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

use SeoWp\Meta\Robots;
use WP_Post;
use WP_Post_Type;

/**
 * Implements the IndexNow protocol (supported by Bing, Yandex, Seznam and
 * Naver — Google does not participate): serves the required key
 * verification file at /{key}.txt, and pings the shared endpoint on
 * publish, update and delete of any public, indexable post, without
 * hardcoding a post type name. Not part of the classic sitemap ping
 * (Google/Bing retired those endpoints); this is a separate, still-active
 * protocol.
 */
final class IndexNow {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option key holding the generated IndexNow key.
	 */
	private const KEY_OPTION = 'seo_wp_indexnow_key';

	/**
	 * Shared IndexNow endpoint; fans out to every participating engine.
	 */
	private const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Registers the key file endpoint and the publish/update/delete pings.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_key_file' ) );

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
	public function maybe_ping_on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( 'publish' !== $new_status || ! $this->is_eligible( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit( array( $permalink ) );
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
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! $this->is_eligible( $post ) ) {
			return;
		}

		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$this->submit( array( $permalink ) );
		}
	}

	/**
	 * Whether a post should trigger a ping: not a revision/autosave, a
	 * public post type, and not marked noindex.
	 *
	 * @param WP_Post $post Post to check.
	 */
	private function is_eligible( WP_Post $post ): bool {
		if ( ! $this->enabled() || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object instanceof WP_Post_Type || ! $post_type_object->public ) {
			return false;
		}

		return '1' !== get_post_meta( $post->ID, Robots::META_NOINDEX, true );
	}

	/**
	 * Whether IndexNow pings are enabled (on by default).
	 */
	private function enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! isset( $settings['enable_indexnow'] ) || ! empty( $settings['enable_indexnow'] );
	}

	/**
	 * Submits a list of URLs to the IndexNow endpoint, fire-and-forget so
	 * the current request is never slowed down by a third-party API call.
	 *
	 * @param string[] $urls URLs to submit.
	 */
	private function submit( array $urls ): void {
		if ( array() === $urls ) {
			return;
		}

		$key  = $this->key();
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		$body = array(
			'host'        => is_string( $host ) ? $host : '',
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array_values( $urls ),
		);

		wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout'  => 3,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => wp_json_encode( $body ),
			)
		);
	}

	/**
	 * Resolves this site's IndexNow key, generating and persisting one on
	 * first use.
	 */
	private function key(): string {
		$key = get_option( self::KEY_OPTION, '' );
		if ( is_string( $key ) && '' !== $key ) {
			return $key;
		}

		$key = wp_generate_password( 32, false, false );
		update_option( self::KEY_OPTION, $key, true );

		return $key;
	}
}

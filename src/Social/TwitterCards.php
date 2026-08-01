<?php
/**
 * Twitter Card meta tag output.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Social;

use SeoWp\Meta\TitleDescription;
use WP_Post;
use WP_Term;

/**
 * Renders twitter: meta tags for any public post type or taxonomy,
 * falling back to the resolved title/description and the Open Graph
 * image when no Twitter-specific override is set.
 */
final class TwitterCards {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Post/term meta key for a twitter:title override.
	 */
	public const META_TITLE = '_seo_wp_twitter_title';

	/**
	 * Post/term meta key for a twitter:description override.
	 */
	public const META_DESCRIPTION = '_seo_wp_twitter_description';

	/**
	 * Post/term meta key for a twitter:image override (a plain URL).
	 */
	public const META_IMAGE = '_seo_wp_twitter_image';

	/**
	 * Registers the wp_head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 3 );
	}

	/**
	 * Echoes the twitter: meta tags for the current request.
	 */
	public function output(): void {
		if ( is_admin() || is_search() || is_404() ) {
			return;
		}

		$title = $this->title();
		if ( '' === $title ) {
			return;
		}

		$image = $this->image();

		$tags = array(
			'twitter:card'        => '' !== $image ? 'summary_large_image' : 'summary',
			'twitter:title'       => $title,
			'twitter:description' => $this->description(),
			'twitter:image'       => $image,
			'twitter:site'        => $this->handle( 'twitter_site' ),
			'twitter:creator'     => $this->handle( 'twitter_creator' ),
		);

		foreach ( $tags as $name => $content ) {
			if ( '' === $content ) {
				continue;
			}
			printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
		}
	}

	/**
	 * Resolves the twitter:title, falling back to the plugin's resolved
	 * document title.
	 */
	private function title(): string {
		$override = $this->override( self::META_TITLE );

		return '' !== $override ? $override : ( new TitleDescription() )->resolve_title();
	}

	/**
	 * Resolves the twitter:description, falling back to the plugin's
	 * resolved meta description.
	 */
	private function description(): string {
		$override = $this->override( self::META_DESCRIPTION );

		return '' !== $override ? $override : ( new TitleDescription() )->resolve_description();
	}

	/**
	 * Resolves the twitter:image, falling back to the same image Open
	 * Graph resolved (featured image, then site logo, then site icon).
	 */
	private function image(): string {
		$override = $this->override( self::META_IMAGE );

		return '' !== $override ? $override : ( new OpenGraph() )->image()['url'];
	}

	/**
	 * Reads a configured @handle from settings, prefixing it with @ when
	 * missing.
	 *
	 * @param string $key Settings key ('twitter_site' or 'twitter_creator').
	 */
	private function handle( string $key ): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$handle   = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

		if ( '' === $handle ) {
			return '';
		}

		return '@' === $handle[0] ? $handle : '@' . $handle;
	}

	/**
	 * Reads a per-object Twitter Card override.
	 *
	 * @param string $meta_key Post/term meta key.
	 */
	private function override( string $meta_key ): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? (string) get_post_meta( $post->ID, $meta_key, true ) : '';
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, $meta_key, true ) : '';
		}

		return '';
	}
}

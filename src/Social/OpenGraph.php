<?php
/**
 * Open Graph meta tag output.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Social;

use SeoWp\Meta\TitleDescription;
use SeoWp\Support\Url;
use WP_Post;
use WP_Term;

/**
 * Renders og: meta tags for any public post type or taxonomy, falling
 * back to the resolved title/description when no Open Graph-specific
 * override is set.
 */
final class OpenGraph {

	/**
	 * Post/term meta key for an og:title override.
	 */
	public const META_TITLE = '_seo_wp_og_title';

	/**
	 * Post/term meta key for an og:description override.
	 */
	public const META_DESCRIPTION = '_seo_wp_og_description';

	/**
	 * Post/term meta key for an og:image override (a plain URL).
	 */
	public const META_IMAGE = '_seo_wp_og_image';

	/**
	 * Registers the wp_head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 2 );
	}

	/**
	 * Echoes the og: meta tags for the current request.
	 */
	public function output(): void {
		if ( is_admin() || is_search() || is_404() ) {
			return;
		}

		$title = $this->title();
		$url   = $this->url();

		if ( '' === $title || '' === $url ) {
			return;
		}

		$tags = array(
			'og:locale'      => str_replace( '_', '-', get_locale() ),
			'og:type'        => is_front_page() || ! is_singular() ? 'website' : 'article',
			'og:title'       => $title,
			'og:description' => $this->description(),
			'og:url'         => $url,
			'og:site_name'   => (string) get_bloginfo( 'name' ),
		);

		$image = $this->image();
		if ( '' !== $image['url'] ) {
			$tags['og:image'] = $image['url'];
			if ( $image['width'] > 0 ) {
				$tags['og:image:width'] = (string) $image['width'];
			}
			if ( $image['height'] > 0 ) {
				$tags['og:image:height'] = (string) $image['height'];
			}
		}

		foreach ( $tags as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}
			printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
		}
	}

	/**
	 * Resolves the image used for both og:image and, as a fallback, the
	 * Twitter Card image.
	 *
	 * @return array{url: string, width: int, height: int}
	 */
	public function image(): array {
		$override = $this->override( self::META_IMAGE );
		if ( '' !== $override ) {
			return array(
				'url'    => $override,
				'width'  => 0,
				'height' => 0,
			);
		}

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && has_post_thumbnail( $post ) ) {
				$src = wp_get_attachment_image_src( (int) get_post_thumbnail_id( $post ), 'full' );
				if ( is_array( $src ) ) {
					return array(
						'url'    => (string) $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
					);
				}
			}
		}

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$src = wp_get_attachment_image_src( $logo_id, 'full' );
			if ( is_array( $src ) ) {
				return array(
					'url'    => (string) $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}

		$icon = get_site_icon_url( 512 );
		if ( '' !== $icon ) {
			return array(
				'url'    => $icon,
				'width'  => 512,
				'height' => 512,
			);
		}

		return array(
			'url'    => '',
			'width'  => 0,
			'height' => 0,
		);
	}

	/**
	 * Resolves the og:title, falling back to the plugin's resolved
	 * document title.
	 */
	private function title(): string {
		$override = $this->override( self::META_TITLE );

		return '' !== $override ? $override : ( new TitleDescription() )->resolve_title();
	}

	/**
	 * Resolves the og:description, falling back to the plugin's resolved
	 * meta description.
	 */
	private function description(): string {
		$override = $this->override( self::META_DESCRIPTION );

		return '' !== $override ? $override : ( new TitleDescription() )->resolve_description();
	}

	/**
	 * Resolves the canonical-ish og:url for the current request.
	 */
	private function url(): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}

		if ( is_singular() ) {
			$canonical = wp_get_canonical_url();
			return is_string( $canonical ) && '' !== $canonical ? $canonical : (string) get_permalink();
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$link = get_term_link( $term );
				return is_wp_error( $link ) ? '' : (string) $link;
			}
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			$link      = get_post_type_archive_link( $post_type );
			return false === $link ? '' : (string) $link;
		}

		return Url::current();
	}

	/**
	 * Reads a per-object Open Graph override.
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

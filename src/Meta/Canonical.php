<?php
/**
 * Self-referencing canonical URL output.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Meta;

use EasyWordpressSeo\Support\Url;
use WP_Post;
use WP_Term;

/**
 * Renders a self-referencing canonical URL, correctly paginated, and
 * WPML-aware via Url::wpml_permalink().
 *
 * Singular content is left to WordPress core's own wp_get_canonical_url()
 * (already pagination-aware) via the get_canonical_url filter; this class
 * only builds canonicals for the archive types core doesn't cover.
 */
final class Canonical {

	/**
	 * Post/term meta key for a per-object canonical override.
	 */
	private const META_CANONICAL = '_ews_canonical';

	/**
	 * Registers the singular filter and the non-singular wp_head output.
	 */
	public function register(): void {
		add_filter( 'get_canonical_url', array( $this, 'filter_singular_canonical' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'output_non_singular_canonical' ), 1 );
	}

	/**
	 * Overrides core's computed canonical for a singular post when a
	 * per-object override is set; otherwise passes core's own (already
	 * paginated) value through the WPML permalink filter.
	 *
	 * @param string  $canonical_url Canonical URL computed by WordPress core.
	 * @param WP_Post $post          The queried post.
	 */
	public function filter_singular_canonical( string $canonical_url, WP_Post $post ): string {
		$override = (string) get_post_meta( $post->ID, self::META_CANONICAL, true );

		return self::pick( $override, Url::wpml_permalink( $canonical_url ) );
	}

	/**
	 * Outputs a self-referencing canonical link for archive request types
	 * that WordPress core does not compute one for.
	 */
	public function output_non_singular_canonical(): void {
		if ( is_singular() || is_search() || is_404() || is_admin() ) {
			return;
		}

		$url = $this->resolve_non_singular_url();

		if ( '' === $url ) {
			return;
		}

		printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $url ) );
	}

	/**
	 * Returns the override when non-empty, otherwise the computed value.
	 *
	 * @param string $override Per-object override, empty when unset.
	 * @param string $computed Value computed from the current request.
	 */
	public static function pick( string $override, string $computed ): string {
		return '' !== $override ? $override : $computed;
	}

	/**
	 * Resolves the canonical URL for the current non-singular request,
	 * applying the term-level override and pagination.
	 */
	private function resolve_non_singular_url(): string {
		$url = self::pick( $this->term_override(), $this->base_non_singular_url() );

		if ( '' === $url ) {
			return '';
		}

		$paged = max( (int) get_query_var( 'paged' ), 1 );
		if ( $paged > 1 ) {
			$paged_url = get_pagenum_link( $paged, false );
			if ( is_string( $paged_url ) && '' !== $paged_url ) {
				$url = $paged_url;
			}
		}

		return Url::wpml_permalink( $url );
	}

	/**
	 * Reads the canonical override on the current taxonomy term, if any.
	 */
	private function term_override(): string {
		if ( ! is_category() && ! is_tag() && ! is_tax() ) {
			return '';
		}

		$term = get_queried_object();

		return $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, self::META_CANONICAL, true ) : '';
	}

	/**
	 * Computes the unpaginated canonical URL for the current archive type.
	 */
	private function base_non_singular_url(): string {
		if ( is_front_page() ) {
			return home_url( '/' );
		}

		if ( is_home() ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			return $page_for_posts > 0 ? (string) get_permalink( $page_for_posts ) : home_url( '/' );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return '';
			}
			$link = get_term_link( $term );
			return is_wp_error( $link ) ? '' : (string) $link;
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			$link      = get_post_type_archive_link( $post_type );
			return false === $link ? '' : (string) $link;
		}

		if ( is_author() ) {
			return (string) get_author_posts_url( (int) get_queried_object_id() );
		}

		if ( is_date() ) {
			return $this->date_archive_url();
		}

		return '';
	}

	/**
	 * Resolves the canonical URL for a day/month/year date archive.
	 */
	private function date_archive_url(): string {
		if ( is_day() ) {
			return (string) get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
		}

		if ( is_month() ) {
			return (string) get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
		}

		if ( is_year() ) {
			return (string) get_year_link( (int) get_query_var( 'year' ) );
		}

		return '';
	}
}

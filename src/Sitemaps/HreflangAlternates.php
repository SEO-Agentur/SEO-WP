<?php
/**
 * Renders xhtml:link hreflang alternates for sitemap entries.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

use Seooptimierung\Toolkit\Wpml\Hreflang;
use WP_Post;

/**
 * Resolves hreflang alternates for an arbitrary post or term (not the
 * current request) via WPML's element translation API, or natively via
 * Polylang when WPML isn't active, then goes through the exact same
 * Hreflang::apply_contract() the document head uses — the binding part of
 * the slim-hreflang compatibility contract — so a filter registered once
 * behaves identically in the head and in the sitemap.
 */
final class HreflangAlternates {

	/**
	 * Resolves the alternates for a single post, applying the contract.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public function for_post( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$permalink = get_permalink( $post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return array();
		}

		$element_type = 'post_' . $post->post_type;

		$alternates = $this->compute_element_alternates(
			$post_id,
			$element_type,
			array( Hreflang::class, 'resolve_published_post_permalink' )
		);

		return Hreflang::apply_contract(
			$alternates,
			array(
				'url'       => $permalink,
				'object_id' => $post_id,
				'lang'      => $this->language_of( $post_id, $element_type ),
			)
		);
	}

	/**
	 * Resolves the alternates for a single taxonomy term, applying the
	 * contract.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public function for_term( int $term_id, string $taxonomy ): array {
		$link = get_term_link( $term_id, $taxonomy );
		if ( is_wp_error( $link ) ) {
			return array();
		}

		$element_type = 'tax_' . $taxonomy;

		$alternates = $this->compute_element_alternates(
			$term_id,
			$element_type,
			static function ( int $id ) use ( $taxonomy ): ?string {
				$term_link = get_term_link( $id, $taxonomy );
				return is_wp_error( $term_link ) ? null : (string) $term_link;
			}
		);

		return Hreflang::apply_contract(
			$alternates,
			array(
				'url'       => (string) $link,
				'object_id' => $term_id,
				'lang'      => $this->language_of( $term_id, $element_type ),
			)
		);
	}

	/**
	 * Renders xhtml:link elements for a list of alternates.
	 *
	 * @param array<int,array{hreflang:string,href:string}> $alternates Alternates to render.
	 */
	public function xml( array $alternates ): string {
		$xml = '';

		foreach ( $alternates as $alternate ) {
			$xml .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $alternate['hreflang'] ) . '" href="' . esc_url( $alternate['href'] ) . '" />' . "\n";
		}

		return $xml;
	}

	/**
	 * Looks up an element's translations via WPML, or natively via
	 * Polylang when WPML isn't active, and resolves each one's URL — only
	 * ever using translations the active plugin itself confirms exist.
	 *
	 * @param int      $element_id   Post or term ID.
	 * @param string   $element_type Element type, e.g. 'post_post' or 'tax_category' (the WPML convention, reused as a prefix to pick the right Polylang lookup too).
	 * @param callable $resolve_url  Resolves a translated element's own ID to its URL.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_element_alternates( int $element_id, string $element_type, callable $resolve_url ): array {
		if ( has_filter( 'wpml_element_trid' ) ) {
			return $this->compute_wpml_element_alternates( $element_id, $element_type, $resolve_url );
		}

		return $this->compute_polylang_element_alternates( $element_id, $element_type, $resolve_url );
	}

	/**
	 * WPML branch of compute_element_alternates().
	 *
	 * @param int      $element_id   Post or term ID.
	 * @param string   $element_type WPML element type, e.g. 'post_post' or 'tax_category'.
	 * @param callable $resolve_url  Resolves a translated element's own ID to its URL.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_wpml_element_alternates( int $element_id, string $element_type, callable $resolve_url ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		$trid = apply_filters( 'wpml_element_trid', null, $element_id, $element_type );
		if ( ! $trid ) {
			return array();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		$translations = apply_filters( 'wpml_get_element_translations', null, $trid, $element_type );
		if ( ! is_array( $translations ) ) {
			return array();
		}

		$x_default_lang = Hreflang::x_default_language();
		$alternates     = array();

		foreach ( $translations as $language_code => $translation ) {
			$translated_id = isset( $translation->element_id ) ? (int) $translation->element_id : 0;
			if ( $translated_id <= 0 ) {
				continue;
			}

			$url = $resolve_url( $translated_id );
			if ( null === $url ) {
				continue;
			}

			$language_code = (string) $language_code;

			$alternates[] = array(
				'hreflang' => $language_code,
				'href'     => $url,
			);

			if ( '' !== $x_default_lang && $language_code === $x_default_lang ) {
				$alternates[] = array(
					'hreflang' => 'x-default',
					'href'     => $url,
				);
			}
		}

		return $alternates;
	}

	/**
	 * Polylang branch of compute_element_alternates(), used when WPML
	 * isn't active.
	 *
	 * @param int      $element_id   Post or term ID.
	 * @param string   $element_type 'post_...' or 'tax_...', picking pll_get_post_translations() vs. pll_get_term_translations().
	 * @param callable $resolve_url  Resolves a translated element's own ID to its URL.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_polylang_element_alternates( int $element_id, string $element_type, callable $resolve_url ): array {
		if ( str_starts_with( $element_type, 'post_' ) && function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $element_id );
		} elseif ( str_starts_with( $element_type, 'tax_' ) && function_exists( 'pll_get_term_translations' ) ) {
			$translations = pll_get_term_translations( $element_id );
		} else {
			return array();
		}

		if ( ! is_array( $translations ) ) {
			return array();
		}

		return Hreflang::alternates_from_polylang_translations( $translations, $resolve_url, Hreflang::x_default_language() );
	}

	/**
	 * Resolves the language code of a specific element, via WPML or,
	 * natively, Polylang.
	 *
	 * @param int    $element_id   Post or term ID.
	 * @param string $element_type Element type, e.g. 'post_post' or 'tax_category'.
	 */
	private function language_of( int $element_id, string $element_type ): string {
		if ( has_filter( 'wpml_element_language_details' ) ) {
			$details = apply_filters(
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
				'wpml_element_language_details',
				null,
				array(
					'element_id'   => $element_id,
					'element_type' => $element_type,
				)
			);

			return is_object( $details ) && isset( $details->language_code ) ? (string) $details->language_code : '';
		}

		if ( str_starts_with( $element_type, 'post_' ) && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $element_id, 'slug' );
			return is_string( $lang ) ? $lang : '';
		}

		if ( str_starts_with( $element_type, 'tax_' ) && function_exists( 'pll_get_term_language' ) ) {
			$lang = pll_get_term_language( $element_id, 'slug' );
			return is_string( $lang ) ? $lang : '';
		}

		return '';
	}
}

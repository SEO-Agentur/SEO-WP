<?php
/**
 * Hreflang alternates computation and rendering.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Wpml;

use Seooptimierung\Toolkit\Support\FragmentCache;
use Seooptimierung\Toolkit\Support\Url;
use WP_Post;
use WP_Term;

/**
 * Builds the hreflang alternates list from WPML or, natively, Polylang
 * (WPML is tried first; Polylang is only consulted when no WPML-compatible
 * plugin exposes the wpml_active_languages filter — the two are never
 * active together in practice, and this order keeps this class the single
 * hreflang sender regardless of which is installed), applies the
 * seooptimierung/hreflang/alternates and seooptimierung/hreflang/managed_externally
 * filters, and renders the head output. The sole hreflang sender for the
 * slim-hreflang plugin contract; apply_contract() is the shared entry
 * point the sitemap's xhtml:link output (Sitemaps\HreflangAlternates)
 * goes through too, so both surfaces honour the contract identically. When
 * neither WPML, Polylang, nor a slim-hreflang-style listener on the
 * contract filters above is present, this class computes and emits
 * nothing at all — including no x-default — since there is no
 * localization plugin to make that entry meaningful.
 */
final class Hreflang {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the wp_head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 5 );
	}

	/**
	 * Echoes the alternate hreflang links for the current request, using
	 * the fragment cache to avoid recomputing WPML lookups on every hit.
	 */
	public function output(): void {
		if ( is_admin() ) {
			return;
		}

		$html = ( new FragmentCache() )->remember( 'hreflang', array( $this, 'render' ) );

		if ( '' === $html ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() already escapes each value.
		echo $html;
	}

	/**
	 * Builds the alternate hreflang link markup for the current request.
	 */
	public function render(): string {
		$context    = $this->current_context();
		$alternates = self::apply_contract( $this->compute_current_request_alternates(), $context );

		if ( array() === $alternates ) {
			return '';
		}

		$html = '';
		foreach ( $alternates as $alternate ) {
			$html .= sprintf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $alternate['hreflang'] ),
				esc_url( $alternate['href'] )
			);
		}

		return $html;
	}

	/**
	 * Applies the compatibility contract with slim-hreflang: a listener can
	 * fully take over via managed_externally (this plugin's own computation
	 * is discarded), and/or extend or override the result via alternates.
	 * The sole place either filter is invoked, so the head and the sitemap
	 * behave identically.
	 *
	 * @param array<int,array{hreflang:string,href:string}>    $alternates Candidate alternates.
	 * @param array{url:string,object_id:int|null,lang:string} $context Request context.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public static function apply_contract( array $alternates, array $context ): array {
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced hook name is part of the binding slim-hreflang compatibility contract, fixed on both sides.
		$managed_externally = apply_filters( 'seooptimierung/hreflang/managed_externally', false, $context );

		if ( $managed_externally ) {
			$alternates = array();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced hook name is part of the binding slim-hreflang compatibility contract, fixed on both sides.
		return apply_filters( 'seooptimierung/hreflang/alternates', $alternates, $context );
	}

	/**
	 * Turns WPML's wpml_active_languages result into a candidate alternates
	 * list, adding an x-default entry where configured. Pure array
	 * processing so it is easy to unit test independently of WPML.
	 *
	 * @param array<string,array<string,mixed>> $languages      wpml_active_languages result.
	 * @param string                            $x_default_lang Language code to also emit as x-default, or ''.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public static function alternates_from_wpml_languages( array $languages, string $x_default_lang ): array {
		$alternates = array();

		foreach ( $languages as $language ) {
			$lang_code = isset( $language['language_code'] ) ? (string) $language['language_code'] : '';
			$url       = isset( $language['url'] ) ? (string) $language['url'] : '';

			if ( '' === $lang_code || '' === $url ) {
				continue;
			}

			$alternates[] = array(
				'hreflang' => $lang_code,
				'href'     => $url,
			);

			if ( '' !== $x_default_lang && $lang_code === $x_default_lang ) {
				$alternates[] = array(
					'hreflang' => 'x-default',
					'href'     => $url,
				);
			}
		}

		return $alternates;
	}

	/**
	 * Turns a Polylang translations map (as pll_get_post_translations()/
	 * pll_get_term_translations() return it: language slug => translated
	 * object ID, including the object's own language) into a candidate
	 * alternates list, adding an x-default entry where configured. Mirrors
	 * alternates_from_wpml_languages()'s contract and output shape; shared
	 * by the current-request computation below and
	 * Sitemaps\HreflangAlternates, so both surfaces resolve Polylang
	 * translations identically. Pure array processing plus one injected
	 * URL resolver, so it is easy to unit test independently of Polylang.
	 *
	 * @param array<string,int> $translations   Language slug => translated post/term ID.
	 * @param callable          $resolve_url    Resolves a translated object's ID to its URL, or null when it doesn't resolve.
	 * @param string            $x_default_lang Language slug to also emit as x-default, or ''.
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	public static function alternates_from_polylang_translations( array $translations, callable $resolve_url, string $x_default_lang ): array {
		$alternates = array();

		foreach ( $translations as $lang_slug => $object_id ) {
			$lang_slug = (string) $lang_slug;
			$object_id = (int) $object_id;

			if ( '' === $lang_slug || $object_id <= 0 ) {
				continue;
			}

			$url = $resolve_url( $object_id );
			if ( null === $url ) {
				continue;
			}

			$alternates[] = array(
				'hreflang' => $lang_slug,
				'href'     => $url,
			);

			if ( '' !== $x_default_lang && $lang_slug === $x_default_lang ) {
				$alternates[] = array(
					'hreflang' => 'x-default',
					'href'     => $url,
				);
			}
		}

		return $alternates;
	}

	/**
	 * Resolves a post ID to its permalink, but only when it's actually
	 * published. Used as the URL resolver everywhere a translation-plugin
	 * API hands back a linked post ID with no guarantee about its status —
	 * Polylang's pll_get_post_translations(), for instance, returns
	 * whatever translation relationship exists regardless of whether the
	 * linked post is a draft or private, since linking a published post to
	 * an in-progress draft translation is a completely normal editorial
	 * workflow. Emitting that draft's real URL in a hreflang tag or
	 * sitemap entry on the *published* sibling's own page would disclose
	 * its existence and slug to any visitor or crawler before it's ready.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function resolve_published_post_permalink( int $post_id ): ?string {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return null;
		}

		$link = get_permalink( $post_id );

		return is_string( $link ) && '' !== $link ? $link : null;
	}

	/**
	 * Resolves the language that should also be emitted as x-default, per
	 * the "hreflang_x_default" setting: 'off' emits none, 'default' (or
	 * unset) uses WPML's or Polylang's own default language (whichever is
	 * active; '' when neither is), anything else is taken as an explicit
	 * language code.
	 */
	public static function x_default_language(): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$setting  = isset( $settings['hreflang_x_default'] ) ? (string) $settings['hreflang_x_default'] : 'default';

		if ( 'off' === $setting ) {
			return '';
		}

		if ( 'default' === $setting || '' === $setting ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			if ( has_filter( 'wpml_default_language' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
				return (string) apply_filters( 'wpml_default_language', null );
			}

			return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
		}

		return $setting;
	}

	/**
	 * Computes the candidate alternates for the current request via WPML's
	 * own "current page in every language" API (which already only
	 * returns existing, bidirectionally-linked translations when asked to
	 * skip missing ones), or natively via Polylang when WPML isn't active.
	 *
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_current_request_alternates(): array {
		if ( has_filter( 'wpml_active_languages' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 1 ) );

			return is_array( $languages ) ? self::alternates_from_wpml_languages( $languages, self::x_default_language() ) : array();
		}

		return $this->compute_current_request_polylang_alternates();
	}

	/**
	 * Computes the candidate alternates for the current request natively
	 * via Polylang's own per-object translation lookups.
	 *
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_current_request_polylang_alternates(): array {
		if ( is_singular() && function_exists( 'pll_get_post_translations' ) ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return array();
			}

			$translations = pll_get_post_translations( $post->ID );

			return is_array( $translations )
				? self::alternates_from_polylang_translations(
					$translations,
					array( self::class, 'resolve_published_post_permalink' ),
					self::x_default_language()
				)
				: array();
		}

		if ( ( is_category() || is_tag() || is_tax() ) && function_exists( 'pll_get_term_translations' ) ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return array();
			}

			$translations = pll_get_term_translations( $term->term_id );
			$taxonomy     = $term->taxonomy;

			return is_array( $translations )
				? self::alternates_from_polylang_translations(
					$translations,
					static function ( int $id ) use ( $taxonomy ): ?string {
						$link = get_term_link( $id, $taxonomy );
						return is_wp_error( $link ) ? null : (string) $link;
					},
					self::x_default_language()
				)
				: array();
		}

		return array();
	}

	/**
	 * Builds the request context passed to both contract filters.
	 *
	 * @return array{url:string,object_id:int|null,lang:string}
	 */
	private function current_context(): array {
		return array(
			'url'       => $this->current_url(),
			'object_id' => $this->current_object_id(),
			'lang'      => self::current_language(),
		);
	}

	/**
	 * Resolves the current request's language via WPML or, natively,
	 * Polylang — whichever is active. '' when neither is. Also used by
	 * Support\FragmentCache and Sitemaps\Router to key their caches per
	 * language, so a cached fragment/sitemap page is never served across a
	 * language it wasn't built for.
	 */
	public static function current_language(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		if ( has_filter( 'wpml_current_language' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			return (string) apply_filters( 'wpml_current_language', null );
		}

		return function_exists( 'pll_current_language' ) ? (string) pll_current_language( 'slug' ) : '';
	}

	/**
	 * Resolves the current request's own URL.
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();
			return is_string( $permalink ) ? $permalink : '';
		}

		return Url::current();
	}

	/**
	 * Resolves the current request's object ID, when it has one.
	 */
	private function current_object_id(): ?int {
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? $post->ID : null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? $term->term_id : null;
		}

		return null;
	}
}

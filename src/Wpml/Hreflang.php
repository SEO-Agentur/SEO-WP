<?php
/**
 * Hreflang alternates computation and rendering.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Wpml;

use SeoWp\Support\FragmentCache;
use SeoWp\Support\Url;
use WP_Post;
use WP_Term;

/**
 * Builds the hreflang alternates list from WPML, applies the
 * slimseo/hreflang/alternates and slimseo/hreflang/managed_externally
 * filters, and renders the head output. The sole hreflang sender for the
 * slim-hreflang plugin contract; apply_contract() is the shared entry
 * point the sitemap's xhtml:link output (Sitemaps\HreflangAlternates)
 * goes through too, so both surfaces honour the contract identically.
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
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- binding slim-hreflang compatibility contract, hook name fixed by that contract.
		$managed_externally = apply_filters( 'slimseo/hreflang/managed_externally', false, $context );

		if ( $managed_externally ) {
			$alternates = array();
		}

		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- binding slim-hreflang compatibility contract, hook name fixed by that contract.
		return apply_filters( 'slimseo/hreflang/alternates', $alternates, $context );
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
	 * Resolves the language that should also be emitted as x-default,
	 * per the "hreflang_x_default" setting: 'off' emits none, 'default'
	 * (or unset) uses WPML's own default language, anything else is taken
	 * as an explicit WPML language code.
	 */
	public static function x_default_language(): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$setting  = isset( $settings['hreflang_x_default'] ) ? (string) $settings['hreflang_x_default'] : 'default';

		if ( 'off' === $setting ) {
			return '';
		}

		if ( 'default' === $setting || '' === $setting ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			return has_filter( 'wpml_default_language' ) ? (string) apply_filters( 'wpml_default_language', null ) : '';
		}

		return $setting;
	}

	/**
	 * Computes the candidate alternates for the current request via
	 * WPML's own "current page in every language" API, which already
	 * only returns existing, bidirectionally-linked translations when
	 * asked to skip missing ones.
	 *
	 * @return array<int,array{hreflang:string,href:string}>
	 */
	private function compute_current_request_alternates(): array {
		if ( ! has_filter( 'wpml_active_languages' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 1 ) );

		if ( ! is_array( $languages ) ) {
			return array();
		}

		return self::alternates_from_wpml_languages( $languages, self::x_default_language() );
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
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
			'lang'      => has_filter( 'wpml_current_language' ) ? (string) apply_filters( 'wpml_current_language', null ) : '',
		);
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

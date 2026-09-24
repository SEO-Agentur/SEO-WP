<?php
/**
 * Imports site-wide settings (not per-object meta) from Yoast/Rank Math.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Import;

use Seooptimierung\Toolkit\Templates\Tokens;

/**
 * Reads a competing plugin's own global options (title templates,
 * separator, social handles — distinct from the per-post/per-term meta
 * the other Import\*Importer classes handle) and maps them onto this
 * plugin's seo_wp_settings shape. Read-only: the source plugin's options
 * are never modified. AIOSEO is intentionally not covered here — unlike
 * Yoast's and Rank Math's option layouts, its settings schema was never
 * verified with enough confidence to map safely, matching the same
 * decision already made for its per-object import.
 */
final class SettingsImporter {

	/**
	 * Maps Yoast SEO's global settings. High confidence: the `wpseo_titles`
	 * and `wpseo_social` option layouts have been stable, flat arrays
	 * across Yoast versions 7.x–24.x.
	 *
	 * @return array<string,string>
	 */
	public function map_yoast(): array {
		$titles = self::option_array( 'wpseo_titles' );
		$social = self::option_array( 'wpseo_social' );
		$target = array();

		self::maybe_set( $target, 'title_template_home', $titles, 'title-home-wpseo', true );
		self::maybe_set( $target, 'description_template_home', $titles, 'metadesc-home-wpseo', true );

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			self::maybe_set( $target, "title_template_{$post_type}", $titles, "title-{$post_type}", true );
			self::maybe_set( $target, "description_template_{$post_type}", $titles, "metadesc-{$post_type}", true );
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			self::maybe_set( $target, "title_template_term_{$taxonomy}", $titles, "title-tax-{$taxonomy}", true );
		}

		$twitter_site = isset( $social['twitter_site'] ) ? ltrim( (string) $social['twitter_site'], '@' ) : '';
		if ( '' !== $twitter_site ) {
			$target['twitter_site'] = $twitter_site;
		}

		return $target;
	}

	/**
	 * Maps Rank Math's global settings. Moderate confidence: the
	 * `rank-math-options-*` option names and internal field names are less
	 * consistently documented across Rank Math versions than Yoast's —
	 * review the dry-run preview before committing an import from here.
	 *
	 * @return array<string,string>
	 */
	public function map_rank_math(): array {
		$titles = self::option_array( 'rank-math-options-titles' );
		$target = array();

		$separator = isset( $titles['title_separator'] ) ? (string) $titles['title_separator'] : '';
		if ( '' !== $separator ) {
			$target['separator'] = $separator;
		}

		self::maybe_set( $target, 'title_template_home', $titles, 'homepage_title', false );
		self::maybe_set( $target, 'description_template_home', $titles, 'homepage_description', false );

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			self::maybe_set( $target, "title_template_{$post_type}", $titles, "pt_{$post_type}_title", false );
			self::maybe_set( $target, "description_template_{$post_type}", $titles, "pt_{$post_type}_description", false );
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			self::maybe_set( $target, "title_template_term_{$taxonomy}", $titles, "tax_{$taxonomy}_title", false );
		}

		return $target;
	}

	/**
	 * Reads an option, coercing anything that isn't an array to an empty
	 * one so callers can index it without checking every time.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private static function option_array( string $option ): array {
		$value = get_option( $option, array() );

		return is_array( $value ) ? $value : array();
	}

	/**
	 * Copies one field from a source options array into the target array,
	 * converting its token syntax, only when the source has a non-empty
	 * value — so an import never clears an already-configured Seooptimierung Toolkit
	 * field by writing an empty string over it.
	 *
	 * @param array<string,string> $target       Target array, modified by reference.
	 * @param string               $target_key   Key to set in $target.
	 * @param array<string,mixed>  $source       Source options array.
	 * @param string               $source_key   Key to read from $source.
	 * @param bool                 $is_yoast_syntax Whether to convert via Tokens::from_yoast() (true) or from_rank_math() (false).
	 */
	private static function maybe_set( array &$target, string $target_key, array $source, string $source_key, bool $is_yoast_syntax ): void {
		$value = isset( $source[ $source_key ] ) ? (string) $source[ $source_key ] : '';
		if ( '' === $value ) {
			return;
		}

		$target[ $target_key ] = $is_yoast_syntax ? Tokens::from_yoast( $value ) : Tokens::from_rank_math( $value );
	}
}

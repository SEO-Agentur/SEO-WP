<?php
/**
 * Imports taxonomy term SEO data from Yoast, Rank Math and AIOSEO.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Import;

use SeoWp\Meta\Canonical;
use SeoWp\Meta\Robots;
use SeoWp\Meta\TitleDescription;
use SeoWp\Social\OpenGraph;
use SeoWp\Support\ErrorLog;
use SeoWp\Templates\Tokens;

/**
 * Reads Yoast's wpseo_taxonomy_meta option (taxonomy => term ID => fields
 * — Yoast does NOT store term data in termmeta), Rank Math's termmeta
 * (same key names as its postmeta), and AIOSEO's wp_aioseo_terms table.
 * Read-only throughout: no source plugin's own data is ever modified.
 */
final class TermImporter {

	/**
	 * Advanced robots directives this plugin understands.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVES = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Maps a term's Yoast fields, read from the wpseo_taxonomy_meta
	 * option rather than termmeta. Field names below match Yoast's
	 * documented option structure at the time of writing; if Yoast
	 * changes it, only this method needs updating.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	public function map_yoast_term( int $term_id, string $taxonomy ): array {
		$option = get_option( 'wpseo_taxonomy_meta', array() );
		$fields = is_array( $option ) && isset( $option[ $taxonomy ][ $term_id ] ) && is_array( $option[ $taxonomy ][ $term_id ] )
			? $option[ $taxonomy ][ $term_id ]
			: array();

		if ( array() === $fields ) {
			return array();
		}

		$target = array();

		$title = isset( $fields['wpseo_title'] ) ? (string) $fields['wpseo_title'] : '';
		if ( '' !== $title ) {
			$target[ TitleDescription::META_TITLE ] = Tokens::from_yoast( $title );
		}

		$description = isset( $fields['wpseo_desc'] ) ? (string) $fields['wpseo_desc'] : '';
		if ( '' !== $description ) {
			$target[ TitleDescription::META_DESCRIPTION ] = Tokens::from_yoast( $description );
		}

		$canonical = isset( $fields['wpseo_canonical'] ) ? (string) $fields['wpseo_canonical'] : '';
		if ( '' !== $canonical ) {
			$target[ Canonical::META_CANONICAL ] = $canonical;
		}

		$noindex = isset( $fields['wpseo_noindex'] ) ? (string) $fields['wpseo_noindex'] : '';
		if ( 'noindex' === $noindex ) {
			$target[ Robots::META_NOINDEX ] = '1';
		} elseif ( 'index' === $noindex ) {
			$target[ Robots::META_NOINDEX ] = '0';
		}

		$og_title = isset( $fields['wpseo_opengraph-title'] ) ? (string) $fields['wpseo_opengraph-title'] : '';
		if ( '' !== $og_title ) {
			$target[ OpenGraph::META_TITLE ] = Tokens::from_yoast( $og_title );
		}

		$og_description = isset( $fields['wpseo_opengraph-description'] ) ? (string) $fields['wpseo_opengraph-description'] : '';
		if ( '' !== $og_description ) {
			$target[ OpenGraph::META_DESCRIPTION ] = Tokens::from_yoast( $og_description );
		}

		$og_image = isset( $fields['wpseo_opengraph-image'] ) ? (string) $fields['wpseo_opengraph-image'] : '';
		if ( '' !== $og_image ) {
			$target[ OpenGraph::META_IMAGE ] = $og_image;
		}

		return $target;
	}

	/**
	 * Maps a term's Rank Math fields, read from termmeta using the same
	 * key names Rank Math uses for its post meta.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>
	 */
	public function map_rank_math_term( int $term_id ): array {
		$target = array();

		$title = (string) get_term_meta( $term_id, 'rank_math_title', true );
		if ( '' !== $title ) {
			$target[ TitleDescription::META_TITLE ] = Tokens::from_rank_math( $title );
		}

		$description = (string) get_term_meta( $term_id, 'rank_math_description', true );
		if ( '' !== $description ) {
			$target[ TitleDescription::META_DESCRIPTION ] = Tokens::from_rank_math( $description );
		}

		$canonical = (string) get_term_meta( $term_id, 'rank_math_canonical_url', true );
		if ( '' !== $canonical ) {
			$target[ Canonical::META_CANONICAL ] = $canonical;
		}

		$robots = RankMathImporter::unserialize_robots( get_term_meta( $term_id, 'rank_math_robots', true ) );
		if ( in_array( 'noindex', $robots, true ) ) {
			$target[ Robots::META_NOINDEX ] = '1';
		}
		if ( in_array( 'nofollow', $robots, true ) ) {
			$target[ Robots::META_NOFOLLOW ] = '1';
		}
		$advanced = array_values( array_intersect( self::ADVANCED_DIRECTIVES, $robots ) );
		if ( array() !== $advanced ) {
			$target[ Robots::META_ADVANCED ] = $advanced;
		}

		$og_title = (string) get_term_meta( $term_id, 'rank_math_facebook_title', true );
		if ( '' !== $og_title ) {
			$target[ OpenGraph::META_TITLE ] = Tokens::from_rank_math( $og_title );
		}

		$og_description = (string) get_term_meta( $term_id, 'rank_math_facebook_description', true );
		if ( '' !== $og_description ) {
			$target[ OpenGraph::META_DESCRIPTION ] = Tokens::from_rank_math( $og_description );
		}

		$og_image = (string) get_term_meta( $term_id, 'rank_math_facebook_image', true );
		if ( '' !== $og_image ) {
			$target[ OpenGraph::META_IMAGE ] = $og_image;
		}

		return $target;
	}

	/**
	 * Maps a term's AIOSEO row from its custom wp_aioseo_terms table, or
	 * null when AIOSEO has no row for this term.
	 *
	 * @param int $term_id Term ID.
	 * @return array<string,mixed>|null
	 */
	public function map_aioseo_term( int $term_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_terms';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- AIOSEO's own custom table, no WP API exists for it; $table is a fixed prefix + literal suffix, not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE term_id = %d", $term_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			ErrorLog::record( "import:aioseo:term:{$term_id}", $wpdb->last_error );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		$target = array();

		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		if ( '' !== $title ) {
			$target[ TitleDescription::META_TITLE ] = Tokens::from_aioseo( $title );
		}

		$description = isset( $row['description'] ) ? (string) $row['description'] : '';
		if ( '' !== $description ) {
			$target[ TitleDescription::META_DESCRIPTION ] = Tokens::from_aioseo( $description );
		}

		$canonical = isset( $row['canonical_url'] ) ? (string) $row['canonical_url'] : '';
		if ( '' !== $canonical ) {
			$target[ Canonical::META_CANONICAL ] = $canonical;
		}

		$robots_default = ! isset( $row['robots_default'] ) || (bool) $row['robots_default'];
		if ( ! $robots_default ) {
			if ( ! empty( $row['robots_noindex'] ) ) {
				$target[ Robots::META_NOINDEX ] = '1';
			}
			if ( ! empty( $row['robots_nofollow'] ) ) {
				$target[ Robots::META_NOFOLLOW ] = '1';
			}
		}

		$og_title = isset( $row['og_title'] ) ? (string) $row['og_title'] : '';
		if ( '' !== $og_title ) {
			$target[ OpenGraph::META_TITLE ] = Tokens::from_aioseo( $og_title );
		}

		$og_description = isset( $row['og_description'] ) ? (string) $row['og_description'] : '';
		if ( '' !== $og_description ) {
			$target[ OpenGraph::META_DESCRIPTION ] = Tokens::from_aioseo( $og_description );
		}

		return $target;
	}
}

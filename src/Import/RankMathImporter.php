<?php
/**
 * Imports object meta written by Rank Math.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Import;

use SeoWp\Meta\Canonical;
use SeoWp\Meta\Robots;
use SeoWp\Meta\TitleDescription;
use SeoWp\Schema\GraphBuilder;
use SeoWp\Social\OpenGraph;
use SeoWp\Social\TwitterCards;
use SeoWp\Templates\Tokens;

/**
 * Reads Rank Math's rank_math_* postmeta (unserialising rank_math_robots)
 * and maps it to this plugin's meta keys. Read-only: Rank Math's own data
 * is never modified or deleted.
 */
final class RankMathImporter {

	/**
	 * Advanced robots directives this plugin understands.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVES = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Maps a single post's Rank Math meta to this plugin's target meta
	 * keys. Only fields with a non-empty Rank Math value are included, so
	 * an import never clears an already-configured SEO-WP field.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function map_post( int $post_id ): array {
		$target = array();

		$title = (string) get_post_meta( $post_id, 'rank_math_title', true );
		if ( '' !== $title ) {
			$target[ TitleDescription::META_TITLE ] = Tokens::from_rank_math( $title );
		}

		$description = (string) get_post_meta( $post_id, 'rank_math_description', true );
		if ( '' !== $description ) {
			$target[ TitleDescription::META_DESCRIPTION ] = Tokens::from_rank_math( $description );
		}

		$canonical = (string) get_post_meta( $post_id, 'rank_math_canonical_url', true );
		if ( '' !== $canonical ) {
			$target[ Canonical::META_CANONICAL ] = $canonical;
		}

		$robots = self::unserialize_robots( get_post_meta( $post_id, 'rank_math_robots', true ) );
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

		$og_title = (string) get_post_meta( $post_id, 'rank_math_facebook_title', true );
		if ( '' !== $og_title ) {
			$target[ OpenGraph::META_TITLE ] = Tokens::from_rank_math( $og_title );
		}

		$og_description = (string) get_post_meta( $post_id, 'rank_math_facebook_description', true );
		if ( '' !== $og_description ) {
			$target[ OpenGraph::META_DESCRIPTION ] = Tokens::from_rank_math( $og_description );
		}

		$og_image = (string) get_post_meta( $post_id, 'rank_math_facebook_image', true );
		if ( '' !== $og_image ) {
			$target[ OpenGraph::META_IMAGE ] = $og_image;
		}

		$twitter_title = (string) get_post_meta( $post_id, 'rank_math_twitter_title', true );
		if ( '' !== $twitter_title ) {
			$target[ TwitterCards::META_TITLE ] = Tokens::from_rank_math( $twitter_title );
		}

		$twitter_description = (string) get_post_meta( $post_id, 'rank_math_twitter_description', true );
		if ( '' !== $twitter_description ) {
			$target[ TwitterCards::META_DESCRIPTION ] = Tokens::from_rank_math( $twitter_description );
		}

		$twitter_image = (string) get_post_meta( $post_id, 'rank_math_twitter_image', true );
		if ( '' !== $twitter_image ) {
			$target[ TwitterCards::META_IMAGE ] = $twitter_image;
		}

		$schema_type = (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		if ( '' !== $schema_type && 'off' !== $schema_type ) {
			$target[ GraphBuilder::META_SCHEMA_TYPE ] = $schema_type;
		}

		return $target;
	}

	/**
	 * Unserialises rank_math_robots (get_post_meta()/get_term_meta() with
	 * $single=true already does this in the common case, but the raw
	 * value is defensively unserialised again here in case it wasn't).
	 * Pure logic once the raw value is in hand, independent of WordPress
	 * beyond maybe_unserialize().
	 *
	 * @param mixed $raw Raw rank_math_robots meta value.
	 * @return string[]
	 */
	public static function unserialize_robots( $raw ): array {
		if ( is_array( $raw ) ) {
			return array_map( 'strval', $raw );
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$unserialized = maybe_unserialize( $raw );

		return is_array( $unserialized ) ? array_map( 'strval', $unserialized ) : array();
	}
}

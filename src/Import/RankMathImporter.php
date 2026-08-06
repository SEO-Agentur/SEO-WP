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
	 * Rank Math's rich_snippet option stores a lowercase, hyphenated slug
	 * rather than a Schema.org type name (e.g. "job-posting", not
	 * "JobPosting") — translated here on import. Best-effort: based on
	 * Rank Math's documented snippet type list, not verified against a
	 * live install. An unrecognised slug is left untranslated, and
	 * GraphBuilder::is_valid_schema_type() rejects it at render time
	 * rather than emitting invalid @type markup.
	 *
	 * @var array<string,string>
	 */
	private const SCHEMA_TYPES = array(
		'article'     => 'Article',
		'book'        => 'Book',
		'course'      => 'Course',
		'event'       => 'Event',
		'job-posting' => 'JobPosting',
		'local'       => 'LocalBusiness',
		'music'       => 'MusicGroup',
		'person'      => 'Person',
		'product'     => 'Product',
		'recipe'      => 'Recipe',
		'restaurant'  => 'Restaurant',
		'review'      => 'Review',
		'service'     => 'Service',
		'software'    => 'SoftwareApplication',
		'video'       => 'VideoObject',
	);

	/**
	 * Post meta key prefix Rank Math's newer, multi-schema-per-post
	 * "Schema Generator"/"Schema Builder" uses (one entry per attached
	 * schema block, keyed by a random suffix, e.g.
	 * "rank_math_schema_pfjr") — a different, newer storage location than
	 * the single `rank_math_rich_snippet` dropdown value SCHEMA_TYPES
	 * above translates. Best-effort, like SCHEMA_TYPES itself: Rank Math
	 * doesn't document this format, so this is inferred from observed
	 * post meta shape rather than verified against their source.
	 */
	private const SCHEMA_GENERATOR_PREFIX = 'rank_math_schema_';

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

		$schema_type = self::resolve_schema_type( $post_id );
		if ( '' !== $schema_type ) {
			$target[ GraphBuilder::META_SCHEMA_TYPE ] = $schema_type;
		}

		return $target;
	}

	/**
	 * Resolves a post's Rank Math schema type from whichever storage Rank
	 * Math actually used: the legacy `rank_math_rich_snippet` dropdown
	 * value first (translated via SCHEMA_TYPES), falling back to the
	 * newer per-post Schema Generator's own meta (see
	 * SCHEMA_GENERATOR_PREFIX) when the legacy key is absent — a post
	 * built entirely with the Schema Generator UI never sets the legacy
	 * key at all.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function resolve_schema_type( int $post_id ): string {
		$legacy = (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		if ( '' !== $legacy && 'off' !== $legacy ) {
			return self::SCHEMA_TYPES[ $legacy ] ?? ucfirst( $legacy );
		}

		foreach ( get_post_meta( $post_id ) as $meta_key => $meta_values ) {
			if ( 0 !== strpos( (string) $meta_key, self::SCHEMA_GENERATOR_PREFIX ) ) {
				continue;
			}

			$raw   = is_array( $meta_values ) ? reset( $meta_values ) : $meta_values;
			$block = maybe_unserialize( (string) $raw );
			if ( ! is_array( $block ) ) {
				continue;
			}

			$type = isset( $block['@type'] ) ? (string) $block['@type'] : ( isset( $block['type'] ) ? (string) $block['type'] : '' );
			if ( '' === $type ) {
				continue;
			}

			return self::SCHEMA_TYPES[ strtolower( $type ) ] ?? ucfirst( $type );
		}

		return '';
	}

	/**
	 * Maps a single author's Rank Math "noindex this author's archive"
	 * setting. Rank Math reuses the exact same rank_math_robots key and
	 * serialised-array format for users as it does for posts and terms,
	 * so this reads it via usermeta and the same unserialize_robots().
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public function map_author( int $user_id ): array {
		$robots = self::unserialize_robots( get_user_meta( $user_id, 'rank_math_robots', true ) );

		if ( ! in_array( 'noindex', $robots, true ) ) {
			return array();
		}

		return array( Robots::META_AUTHOR_NOINDEX => '1' );
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

<?php
/**
 * Imports object data written by AIOSEO v4.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Import;

use Seooptimierung\Toolkit\Meta\Canonical;
use Seooptimierung\Toolkit\Meta\Robots;
use Seooptimierung\Toolkit\Meta\TitleDescription;
use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Social\OpenGraph;
use Seooptimierung\Toolkit\Social\TwitterCards;
use Seooptimierung\Toolkit\Support\ErrorLog;
use Seooptimierung\Toolkit\Templates\Tokens;

/**
 * Reads AIOSEO's custom wp_aioseo_posts table (not postmeta — AIOSEO v4
 * stores object data in its own tables) and maps it to this plugin's meta
 * keys. Read-only: AIOSEO's own table is never modified or deleted.
 */
final class AioseoImporter {

	/**
	 * Advanced robots directives this plugin understands.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVES = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Maps a single post's AIOSEO row to this plugin's target meta keys,
	 * or null when AIOSEO has no row for this post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function map_post( int $post_id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- AIOSEO's own custom table, no WP API exists for it; $table is a fixed prefix + literal suffix, not user input.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE post_id = %d", $post_id ), ARRAY_A );

		if ( '' !== $wpdb->last_error ) {
			ErrorLog::record( "import:aioseo:post:{$post_id}", $wpdb->last_error );
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::map_row( $row );
	}

	/**
	 * Maps a raw wp_aioseo_posts row to this plugin's target meta keys.
	 * Split out from map_post() so the mapping itself can be unit tested
	 * with a plain array, independent of the database.
	 *
	 * @param array<string,mixed> $row A wp_aioseo_posts row.
	 * @return array<string,mixed>
	 */
	public static function map_row( array $row ): array {
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

		// robots_default = true means "use type defaults"; the individual
		// robots_* columns on this row are then stale/irrelevant and must
		// be ignored, per AIOSEO's own documented behaviour.
		$robots_default = ! isset( $row['robots_default'] ) || (bool) $row['robots_default'];

		if ( ! $robots_default ) {
			if ( ! empty( $row['robots_noindex'] ) ) {
				$target[ Robots::META_NOINDEX ] = '1';
			}
			if ( ! empty( $row['robots_nofollow'] ) ) {
				$target[ Robots::META_NOFOLLOW ] = '1';
			}

			$advanced = array();
			foreach ( self::ADVANCED_DIRECTIVES as $directive ) {
				if ( ! empty( $row[ 'robots_' . $directive ] ) ) {
					$advanced[] = $directive;
				}
			}
			if ( array() !== $advanced ) {
				$target[ Robots::META_ADVANCED ] = $advanced;
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

		$og_image = isset( $row['og_image_custom_url'] ) ? (string) $row['og_image_custom_url'] : '';
		if ( '' !== $og_image ) {
			$target[ OpenGraph::META_IMAGE ] = $og_image;
		}

		$twitter_title = isset( $row['twitter_title'] ) ? (string) $row['twitter_title'] : '';
		if ( '' !== $twitter_title ) {
			$target[ TwitterCards::META_TITLE ] = Tokens::from_aioseo( $twitter_title );
		}

		$twitter_description = isset( $row['twitter_description'] ) ? (string) $row['twitter_description'] : '';
		if ( '' !== $twitter_description ) {
			$target[ TwitterCards::META_DESCRIPTION ] = Tokens::from_aioseo( $twitter_description );
		}

		$twitter_image = isset( $row['twitter_image_custom_url'] ) ? (string) $row['twitter_image_custom_url'] : '';
		if ( '' !== $twitter_image ) {
			$target[ TwitterCards::META_IMAGE ] = $twitter_image;
		}

		$schema_type = self::schema_type_from_json( isset( $row['schema'] ) ? (string) $row['schema'] : '' );
		if ( '' !== $schema_type ) {
			$target[ GraphBuilder::META_SCHEMA_TYPE ] = $schema_type;
		}

		return $target;
	}

	/**
	 * Extracts the first graph node's type from AIOSEO's JSON schema
	 * column. Pure string/JSON processing, independent of WordPress.
	 *
	 * @param string $json Raw wp_aioseo_posts.schema value.
	 */
	public static function schema_type_from_json( string $json ): string {
		if ( '' === $json ) {
			return '';
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) && isset( $decoded['graphs'][0]['type'] ) ? (string) $decoded['graphs'][0]['type'] : '';
	}
}

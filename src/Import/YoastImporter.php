<?php
/**
 * Imports object meta written by Yoast SEO.
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
use Seooptimierung\Toolkit\Templates\Tokens;

/**
 * Reads Yoast's _yoast_wpseo_* postmeta and maps it to this plugin's meta
 * keys. Read-only: Yoast's own data is never modified or deleted.
 */
final class YoastImporter {

	/**
	 * Advanced robots directives this plugin understands.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVES = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Maps a single post's Yoast meta to this plugin's target meta keys.
	 * Only fields with a non-empty Yoast value are included, so an import
	 * never clears an already-configured Seooptimierung Toolkit field.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public function map_post( int $post_id ): array {
		$target = array();

		$title = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
		if ( '' !== $title ) {
			$target[ TitleDescription::META_TITLE ] = Tokens::from_yoast( $title );
		}

		$description = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( '' !== $description ) {
			$target[ TitleDescription::META_DESCRIPTION ] = Tokens::from_yoast( $description );
		}

		$canonical = (string) get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		if ( '' !== $canonical ) {
			$target[ Canonical::META_CANONICAL ] = $canonical;
		}

		$noindex = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $noindex ) {
			$target[ Robots::META_NOINDEX ] = '1';
		} elseif ( '2' === $noindex ) {
			$target[ Robots::META_NOINDEX ] = '0';
		}

		if ( '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
			$target[ Robots::META_NOFOLLOW ] = '1';
		}

		$advanced = self::advanced_directives( (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true ) );
		if ( array() !== $advanced ) {
			$target[ Robots::META_ADVANCED ] = $advanced;
		}

		$og_title = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true );
		if ( '' !== $og_title ) {
			$target[ OpenGraph::META_TITLE ] = Tokens::from_yoast( $og_title );
		}

		$og_description = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true );
		if ( '' !== $og_description ) {
			$target[ OpenGraph::META_DESCRIPTION ] = Tokens::from_yoast( $og_description );
		}

		$og_image = (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
		if ( '' !== $og_image ) {
			$target[ OpenGraph::META_IMAGE ] = $og_image;
		}

		$twitter_title = (string) get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true );
		if ( '' !== $twitter_title ) {
			$target[ TwitterCards::META_TITLE ] = Tokens::from_yoast( $twitter_title );
		}

		$twitter_description = (string) get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true );
		if ( '' !== $twitter_description ) {
			$target[ TwitterCards::META_DESCRIPTION ] = Tokens::from_yoast( $twitter_description );
		}

		$twitter_image = (string) get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true );
		if ( '' !== $twitter_image ) {
			$target[ TwitterCards::META_IMAGE ] = $twitter_image;
		}

		$schema_type = (string) get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true );
		if ( '' !== $schema_type ) {
			$target[ GraphBuilder::META_SCHEMA_TYPE ] = $schema_type;
		}

		return $target;
	}

	/**
	 * Reads Yoast's per-author "noindex this author's archive" setting.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>
	 */
	public function map_author( int $user_id ): array {
		if ( '1' !== (string) get_user_meta( $user_id, 'wpseo_noindex_author', true ) ) {
			return array();
		}

		return array( Robots::META_AUTHOR_NOINDEX => '1' );
	}

	/**
	 * Parses Yoast's comma-separated advanced robots field (e.g.
	 * "noarchive,noimageindex") into the directives this plugin
	 * understands. Pure string processing, independent of WordPress.
	 *
	 * @param string $raw Raw _yoast_wpseo_meta-robots-adv value.
	 * @return string[]
	 */
	public static function advanced_directives( string $raw ): array {
		if ( '' === $raw || 'none' === $raw ) {
			return array();
		}

		$requested = array_map( 'trim', explode( ',', $raw ) );

		return array_values( array_intersect( self::ADVANCED_DIRECTIVES, $requested ) );
	}
}

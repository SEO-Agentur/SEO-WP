<?php
/**
 * Batched, cron/AJAX-driven import runner.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Import;

use WP_Query;

/**
 * Runs the per-source importers in small batches (one page per call, so
 * an admin-ajax or cron-driven wizard in M7 can call this repeatedly
 * without timing out on a large site), with dry-run support and a log of
 * what was imported or skipped. Never touches a source plugin's own data
 * — only ever reads it.
 */
final class Batch {

	/**
	 * Option key the import log is stored under (last run only).
	 */
	private const LOG_OPTION = 'seo_wp_import_log';

	/**
	 * Items processed per call.
	 */
	private const BATCH_SIZE = 20;

	/**
	 * Sources this class knows how to import from.
	 *
	 * @var string[]
	 */
	private const VALID_SOURCES = array( 'yoast', 'rank_math', 'aioseo' );

	/**
	 * Imports one page of posts (any post type/status except trash and
	 * auto-draft) for a source.
	 *
	 * @param string $source  'yoast', 'rank_math' or 'aioseo'.
	 * @param int    $page    Page number, starting at 1.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array{processed:int,has_more:bool,log:array<int,array<string,mixed>>}
	 */
	public function run_posts_page( string $source, int $page, bool $dry_run = false ): array {
		if ( ! in_array( $source, self::VALID_SOURCES, true ) ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$entries = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$mapped  = $this->map_post( $source, $post_id );

			if ( array() === $mapped ) {
				continue;
			}

			if ( ! $dry_run ) {
				foreach ( $mapped as $meta_key => $value ) {
					update_post_meta( $post_id, $meta_key, $value );
				}
			}

			$entries[] = array(
				'type'    => 'post',
				'id'      => $post_id,
				'source'  => $source,
				'fields'  => array_keys( $mapped ),
				'dry_run' => $dry_run,
			);
		}

		$this->append_log( $entries );

		return array(
			'processed' => count( $query->posts ),
			'has_more'  => $page < (int) $query->max_num_pages,
			'log'       => $entries,
		);
	}

	/**
	 * Imports one page of taxonomy terms (across every registered
	 * taxonomy, not just public ones) for a source.
	 *
	 * @param string $source  'yoast', 'rank_math' or 'aioseo'.
	 * @param int    $page    Page number, starting at 1.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array{processed:int,has_more:bool,log:array<int,array<string,mixed>>}
	 */
	public function run_terms_page( string $source, int $page, bool $dry_run = false ): array {
		if ( ! in_array( $source, self::VALID_SOURCES, true ) ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => get_taxonomies( array(), 'names' ),
				'hide_empty' => false,
				'number'     => self::BATCH_SIZE,
				'offset'     => ( $page - 1 ) * self::BATCH_SIZE,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( ! is_array( $terms ) ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		$entries = array();

		foreach ( $terms as $term ) {
			$mapped = $this->map_term( $source, (int) $term->term_id, (string) $term->taxonomy );

			if ( array() === $mapped ) {
				continue;
			}

			if ( ! $dry_run ) {
				foreach ( $mapped as $meta_key => $value ) {
					update_term_meta( (int) $term->term_id, $meta_key, $value );
				}
			}

			$entries[] = array(
				'type'     => 'term',
				'id'       => (int) $term->term_id,
				'taxonomy' => (string) $term->taxonomy,
				'source'   => $source,
				'fields'   => array_keys( $mapped ),
				'dry_run'  => $dry_run,
			);
		}

		$this->append_log( $entries );

		return array(
			'processed' => count( $terms ),
			'has_more'  => count( $terms ) === self::BATCH_SIZE,
			'log'       => $entries,
		);
	}

	/**
	 * Imports author-level settings for a source. Currently only Yoast's
	 * per-author noindex flag is understood; Rank Math and AIOSEO author
	 * settings are not imported (their storage was not confirmed against
	 * a live install, so nothing is guessed).
	 *
	 * @param string $source  'yoast', 'rank_math' or 'aioseo'.
	 * @param int    $page    Page number, starting at 1.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array{processed:int,has_more:bool,log:array<int,array<string,mixed>>}
	 */
	public function run_authors_page( string $source, int $page, bool $dry_run = false ): array {
		if ( 'yoast' !== $source ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		$users = get_users(
			array(
				'number'  => self::BATCH_SIZE,
				'offset'  => ( $page - 1 ) * self::BATCH_SIZE,
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		$importer = new YoastImporter();
		$entries  = array();

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;
			$mapped  = $importer->map_author( $user_id );

			if ( array() === $mapped ) {
				continue;
			}

			if ( ! $dry_run ) {
				foreach ( $mapped as $meta_key => $value ) {
					update_user_meta( $user_id, $meta_key, $value );
				}
			}

			$entries[] = array(
				'type'    => 'author',
				'id'      => $user_id,
				'source'  => $source,
				'fields'  => array_keys( $mapped ),
				'dry_run' => $dry_run,
			);
		}

		$this->append_log( $entries );

		return array(
			'processed' => count( $users ),
			'has_more'  => count( $users ) === self::BATCH_SIZE,
			'log'       => $entries,
		);
	}

	/**
	 * Logs a "redirects were found but not migrated" entry when a source
	 * has any redirect data. Call once per import run, not per page.
	 *
	 * @param string $source 'yoast', 'rank_math' or 'aioseo'.
	 */
	public function log_skipped_redirects( string $source ): void {
		if ( ! ( new Detector() )->redirects_present( $source ) ) {
			return;
		}

		$this->append_log(
			array(
				array(
					'type'   => 'redirects-skipped',
					'source' => $source,
					'note'   => 'Redirects were found but are not migrated by this plugin.',
				),
			)
		);
	}

	/**
	 * The current import log (accumulates across a run; reset with
	 * reset_log() before starting a new one).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function log(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clears the import log, e.g. before starting a fresh run.
	 */
	public function reset_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Dispatches to the right post importer for a source.
	 *
	 * @param string $source  Source key.
	 * @param int    $post_id Post ID.
	 * @return array<string,mixed>
	 */
	private function map_post( string $source, int $post_id ): array {
		switch ( $source ) {
			case 'yoast':
				return ( new YoastImporter() )->map_post( $post_id );
			case 'rank_math':
				return ( new RankMathImporter() )->map_post( $post_id );
			case 'aioseo':
				return ( new AioseoImporter() )->map_post( $post_id ) ?? array();
			default:
				return array();
		}
	}

	/**
	 * Dispatches to the right term mapping for a source.
	 *
	 * @param string $source   Source key.
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<string,mixed>
	 */
	private function map_term( string $source, int $term_id, string $taxonomy ): array {
		$importer = new TermImporter();

		switch ( $source ) {
			case 'yoast':
				return $importer->map_yoast_term( $term_id, $taxonomy );
			case 'rank_math':
				return $importer->map_rank_math_term( $term_id );
			case 'aioseo':
				return $importer->map_aioseo_term( $term_id ) ?? array();
			default:
				return array();
		}
	}

	/**
	 * Appends entries to the persisted log.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries to append.
	 */
	private function append_log( array $entries ): void {
		if ( array() === $entries ) {
			return;
		}

		update_option( self::LOG_OPTION, array_merge( $this->log(), $entries ), false );
	}
}

<?php
/**
 * Batched, cron/AJAX-driven import runner.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Import;

use Seooptimierung\Toolkit\Support\ErrorLog;
use Seooptimierung\Toolkit\Support\FragmentCache;
use WP_Query;

/**
 * Runs the per-source importers in small batches (one page per call, so
 * an admin-ajax or cron-driven wizard in M7 can call this repeatedly
 * without timing out on a large site), with dry-run support and a log of
 * what was imported or skipped. Never touches a source plugin's own data
 * — only ever reads it.
 *
 * Writes go straight through update_post_meta()/update_term_meta()/
 * update_user_meta()/update_option() rather than wp_insert_post() or
 * similar — none of which fire save_post/edited_term, the only hooks
 * FragmentCache listens to. Left alone, a schema/hreflang fragment
 * already cached for a page (e.g. from a visit before the import ran)
 * would keep serving its pre-import content for up to FragmentCache::TTL
 * regardless of what this class just wrote. Each run_*_page()/
 * run_settings() call below flushes it once per page after a real
 * (non-dry-run) write, so newly-imported schema/social data shows up on
 * the very next request instead of silently waiting out the cache.
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

			// The whole per-post pipeline (mapping, the idempotency
			// filter, and the actual writes) runs inside capture(), not
			// just the mapping call — a Throwable from any of those
			// three for one unusual post must not abort the whole batch
			// (and every later page/stage after it) for the rest of the
			// site's content.
			$entry = ErrorLog::capture(
				"import:{$source}:post:{$post_id}",
				function () use ( $source, $post_id, $dry_run ) {
					return $this->import_one_post( $source, $post_id, $dry_run );
				}
			);

			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		$this->append_log( $entries );
		$this->maybe_invalidate_fragment_cache( $dry_run, $entries );

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
			$term_id  = (int) $term->term_id;
			$taxonomy = (string) $term->taxonomy;

			$entry = ErrorLog::capture(
				"import:{$source}:term:{$term_id}",
				function () use ( $source, $term_id, $taxonomy, $dry_run ) {
					return $this->import_one_term( $source, $term_id, $taxonomy, $dry_run );
				}
			);

			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		$this->append_log( $entries );
		$this->maybe_invalidate_fragment_cache( $dry_run, $entries );

		return array(
			'processed' => count( $terms ),
			'has_more'  => count( $terms ) === self::BATCH_SIZE,
			'log'       => $entries,
		);
	}

	/**
	 * Imports author-level settings for a source. Only Yoast's and Rank
	 * Math's per-author noindex flag is understood; AIOSEO does not
	 * appear to expose any per-author SEO settings (unlike Yoast/Rank
	 * Math, it has no SEO fields on the user-edit screen — its author
	 * archive handling is a site-wide template setting, not a per-user
	 * one), so there is nothing to import for it.
	 *
	 * @param string $source  'yoast', 'rank_math' or 'aioseo'.
	 * @param int    $page    Page number, starting at 1.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array{processed:int,has_more:bool,log:array<int,array<string,mixed>>}
	 */
	public function run_authors_page( string $source, int $page, bool $dry_run = false ): array {
		if ( ! in_array( $source, array( 'yoast', 'rank_math' ), true ) ) {
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

		$importer = 'yoast' === $source ? new YoastImporter() : new RankMathImporter();
		$entries  = array();

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;

			$entry = ErrorLog::capture(
				"import:{$source}:author:{$user_id}",
				static function () use ( $importer, $source, $user_id, $dry_run ) {
					$mapped = $importer->map_author( $user_id );
					$mapped = self::filter_to_empty_fields(
						$mapped,
						static fn ( string $meta_key ) => get_user_meta( $user_id, $meta_key, true )
					);

					if ( array() === $mapped ) {
						return null;
					}

					if ( ! $dry_run ) {
						foreach ( $mapped as $meta_key => $value ) {
							update_user_meta( $user_id, $meta_key, $value );
						}
					}

					return array(
						'type'    => 'author',
						'id'      => $user_id,
						'source'  => $source,
						'fields'  => array_keys( $mapped ),
						'dry_run' => $dry_run,
					);
				}
			);

			if ( is_array( $entry ) ) {
				$entries[] = $entry;
			}
		}

		$this->append_log( $entries );
		$this->maybe_invalidate_fragment_cache( $dry_run, $entries );

		return array(
			'processed' => count( $users ),
			'has_more'  => count( $users ) === self::BATCH_SIZE,
			'log'       => $entries,
		);
	}

	/**
	 * Imports a source's site-wide settings (title templates, separator,
	 * social handles — see Import\SettingsImporter) in a single step; not
	 * paginated, since it's a handful of option reads rather than a query
	 * across many objects. Only Yoast and Rank Math are covered; AIOSEO's
	 * settings schema isn't imported (see SettingsImporter's docblock).
	 *
	 * @param string $source  'yoast', 'rank_math' or 'aioseo'.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array{processed:int,has_more:bool,log:array<int,array<string,mixed>>}
	 */
	public function run_settings( string $source, bool $dry_run = false ): array {
		if ( ! in_array( $source, array( 'yoast', 'rank_math' ), true ) ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		$importer = new SettingsImporter();
		$mapped   = ErrorLog::capture(
			"import:{$source}:settings",
			static function () use ( $importer, $source ) {
				return 'yoast' === $source ? $importer->map_yoast() : $importer->map_rank_math();
			}
		);
		$mapped   = is_array( $mapped ) ? $mapped : array();

		$existing = get_option( 'seo_wp_settings', array() );
		$existing = is_array( $existing ) ? $existing : array();
		$mapped   = self::filter_to_empty_fields(
			$mapped,
			static fn ( string $key ) => $existing[ $key ] ?? ''
		);

		if ( array() === $mapped ) {
			return array(
				'processed' => 0,
				'has_more'  => false,
				'log'       => array(),
			);
		}

		if ( ! $dry_run ) {
			update_option( 'seo_wp_settings', array_merge( $existing, $mapped ), 'yes' );
		}

		$entries = array(
			array(
				'type'    => 'settings',
				'source'  => $source,
				'fields'  => array_keys( $mapped ),
				'dry_run' => $dry_run,
			),
		);

		$this->append_log( $entries );
		$this->maybe_invalidate_fragment_cache( $dry_run, $entries );

		return array(
			'processed' => 1,
			'has_more'  => false,
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
					'note'   => __( 'Redirects were found but are not migrated by this plugin.', 'seooptimierung-toolkit' ),
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
	 * Drops any field a target already has a non-empty value for, so a
	 * re-run of the import (or one that picks up newly-added source data)
	 * never overwrites data that was already imported, or that a site
	 * owner has since edited by hand in Seooptimierung Toolkit itself — an existing
	 * value, from wherever it came, always wins over the source plugin's.
	 *
	 * @param array<string,mixed> $mapped       Fields this import would otherwise write.
	 * @param callable            $get_existing Given a field's key, returns its current value in Seooptimierung Toolkit.
	 * @return array<string,mixed>
	 */
	public static function filter_to_empty_fields( array $mapped, callable $get_existing ): array {
		foreach ( $mapped as $key => $value ) {
			if ( ! self::is_empty_value( $get_existing( (string) $key ) ) ) {
				unset( $mapped[ $key ] );
			}
		}

		return $mapped;
	}

	/**
	 * Whether an existing Seooptimierung Toolkit value counts as "not set yet" — an empty
	 * string or an empty array. A meaningful falsy-looking value like the
	 * string '0' (e.g. Robots::META_NOINDEX explicitly forcing "indexed")
	 * is not empty.
	 *
	 * @param mixed $value Existing value to check.
	 */
	public static function is_empty_value( $value ): bool {
		return is_array( $value ) ? array() === $value : '' === (string) $value;
	}

	/**
	 * Maps, filters and (unless dry-running) writes one post's fields,
	 * returning the log entry for it, or null when the source had nothing
	 * at all for this post. The whole thing is one unit for
	 * ErrorLog::capture() to wrap in run_posts_page() — see the comment
	 * there.
	 *
	 * Distinguishes, in the returned entry's 'skipped' field, "the source
	 * plugin had a value here but Seooptimierung Toolkit already had one too, so it was
	 * left alone" from "the source plugin had nothing here" (the latter
	 * never even reaches this method — see the early return below): a
	 * social/schema field reading empty in RankMath's or Yoast's own admin
	 * (relying on their dynamic preview/placeholder rather than an actual
	 * saved override) has nothing to import, which is correct, not a bug;
	 * this makes that distinction visible in the import log instead of
	 * both cases looking identical ("nothing changed").
	 *
	 * @param string $source  Source key.
	 * @param int    $post_id Post ID.
	 * @param bool   $dry_run When true, computes but does not write.
	 * @return array<string,mixed>|null
	 */
	private function import_one_post( string $source, int $post_id, bool $dry_run ): ?array {
		$raw_mapped = $this->map_post( $source, $post_id );
		if ( array() === $raw_mapped ) {
			return null;
		}

		$mapped = self::filter_to_empty_fields(
			$raw_mapped,
			static fn ( string $meta_key ) => get_post_meta( $post_id, $meta_key, true )
		);

		if ( ! $dry_run ) {
			foreach ( $mapped as $meta_key => $value ) {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		return array(
			'type'    => 'post',
			'id'      => $post_id,
			'source'  => $source,
			'fields'  => array_keys( $mapped ),
			'skipped' => array_values( array_diff( array_keys( $raw_mapped ), array_keys( $mapped ) ) ),
			'dry_run' => $dry_run,
		);
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
	 * Maps, filters and (unless dry-running) writes one term's fields; see
	 * import_one_post() for why this is one unit for capture() to wrap,
	 * and for the 'skipped' vs "source had nothing" distinction.
	 *
	 * @param string $source   Source key.
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param bool   $dry_run  When true, computes but does not write.
	 * @return array<string,mixed>|null
	 */
	private function import_one_term( string $source, int $term_id, string $taxonomy, bool $dry_run ): ?array {
		$raw_mapped = $this->map_term( $source, $term_id, $taxonomy );
		if ( array() === $raw_mapped ) {
			return null;
		}

		$mapped = self::filter_to_empty_fields(
			$raw_mapped,
			static fn ( string $meta_key ) => get_term_meta( $term_id, $meta_key, true )
		);

		if ( ! $dry_run ) {
			foreach ( $mapped as $meta_key => $value ) {
				update_term_meta( $term_id, $meta_key, $value );
			}
		}

		return array(
			'type'     => 'term',
			'id'       => $term_id,
			'taxonomy' => $taxonomy,
			'source'   => $source,
			'fields'   => array_keys( $mapped ),
			'skipped'  => array_values( array_diff( array_keys( $raw_mapped ), array_keys( $mapped ) ) ),
			'dry_run'  => $dry_run,
		);
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

	/**
	 * Flushes the schema/hreflang fragment cache after a real write, so
	 * newly-imported data appears on the very next request instead of a
	 * stale, pre-import fragment continuing to serve for up to
	 * FragmentCache::TTL — see the class docblock for why this is needed
	 * at all (update_*_meta()/update_option() don't fire the hooks
	 * FragmentCache invalidates on).
	 *
	 * @param bool                           $dry_run A dry run never wrote anything, so there is nothing stale to clear.
	 * @param array<int,array<string,mixed>> $entries This call's log entries; nothing to invalidate if none were written.
	 */
	private function maybe_invalidate_fragment_cache( bool $dry_run, array $entries ): void {
		if ( $dry_run || array() === $entries ) {
			return;
		}

		( new FragmentCache() )->invalidate();
	}
}

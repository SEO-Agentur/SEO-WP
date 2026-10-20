<?php
/**
 * Detects which competing SEO plugins have data present.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Import;

use Seooptimierung\Toolkit\Support\ErrorLog;

/**
 * Detects Yoast SEO, Rank Math and AIOSEO data available for import,
 * including whether any redirect data exists (redirects are never
 * migrated by this plugin — Batch logs their presence instead).
 */
final class Detector {

	/**
	 * Sources with any object or term meta detected.
	 *
	 * @return string[]
	 */
	public function detected(): array {
		$found = array();

		if ( $this->yoast_present() ) {
			$found[] = 'yoast';
		}

		if ( $this->rank_math_present() ) {
			$found[] = 'rank_math';
		}

		if ( $this->aioseo_present() ) {
			$found[] = 'aioseo';
		}

		return $found;
	}

	/**
	 * Whether Yoast SEO object or term meta is present.
	 */
	public function yoast_present(): bool {
		return $this->postmeta_key_exists( '_yoast_wpseo_title' )
			|| false !== get_option( 'wpseo_titles', false )
			|| is_array( get_option( 'wpseo_taxonomy_meta', false ) );
	}

	/**
	 * Whether Rank Math object meta is present.
	 */
	public function rank_math_present(): bool {
		return $this->postmeta_key_exists( 'rank_math_title' );
	}

	/**
	 * Whether AIOSEO's own tables exist and hold data.
	 */
	public function aioseo_present(): bool {
		return $this->table_has_rows( 'aioseo_posts' ) || $this->table_has_rows( 'aioseo_terms' );
	}

	/**
	 * Whether Yoast has any redirect data. Best-effort: Yoast Premium's
	 * redirect storage has changed across versions (a legacy option, or a
	 * dedicated table in newer releases), so both are checked.
	 */
	public function yoast_redirects_present(): bool {
		$legacy = get_option( 'wpseo-redirects', array() );

		return ( is_array( $legacy ) && array() !== $legacy ) || $this->table_has_rows( 'yoast_redirects' );
	}

	/**
	 * Whether Rank Math's Redirections module has any redirects.
	 */
	public function rank_math_redirects_present(): bool {
		return $this->table_has_rows( 'rank_math_redirections' );
	}

	/**
	 * Whether AIOSEO's Redirects module has any redirects.
	 */
	public function aioseo_redirects_present(): bool {
		return $this->table_has_rows( 'aioseo_redirects' );
	}

	/**
	 * Whether a given source has any redirect data at all.
	 *
	 * @param string $source 'yoast', 'rank_math' or 'aioseo'.
	 */
	public function redirects_present( string $source ): bool {
		switch ( $source ) {
			case 'yoast':
				return $this->yoast_redirects_present();
			case 'rank_math':
				return $this->rank_math_redirects_present();
			case 'aioseo':
				return $this->aioseo_redirects_present();
			default:
				return false;
		}
	}

	/**
	 * Whether at least one row in wp_postmeta uses the given meta key.
	 *
	 * @param string $meta_key Meta key to look for.
	 */
	private function postmeta_key_exists( string $meta_key ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off detection, no WP API exists for "does any row use this meta key".
		$result = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $meta_key ) );

		$this->maybe_record_db_error( "detector:postmeta_key_exists:{$meta_key}" );

		return (bool) $result;
	}

	/**
	 * Whether a custom table (without the wp_ prefix) exists and has at
	 * least one row.
	 *
	 * @param string $table_suffix Table name, without the site's prefix.
	 */
	private function table_has_rows( string $table_suffix ): bool {
		global $wpdb;

		$table = $wpdb->prefix . $table_suffix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names cannot be parameterised; $table is built from a fixed suffix, not user input.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists !== $table ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name already validated to exist just above, not user input; passed as an identifier placeholder.
		$result = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $table ) );

		$this->maybe_record_db_error( "detector:table_has_rows:{$table_suffix}" );

		return (bool) $result;
	}

	/**
	 * Records $wpdb's last error, if any, so a broken/unexpected table
	 * schema surfaces in the error log instead of just quietly detecting
	 * "not present".
	 *
	 * @param string $context Identifies which query this was.
	 */
	private function maybe_record_db_error( string $context ): void {
		global $wpdb;

		if ( '' !== $wpdb->last_error ) {
			ErrorLog::record( $context, $wpdb->last_error );
		}
	}
}

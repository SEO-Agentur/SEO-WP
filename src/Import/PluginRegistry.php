<?php
/**
 * Locates and deactivates a detected competing SEO plugin.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Import;

/**
 * Resolves which plugin file (if any) is actually active for a given
 * import source, checking real candidate slugs against the site's (and,
 * on multisite, the network's) active-plugins list rather than assuming a
 * single hardcoded path — a renamed install or an unlisted variant simply
 * yields no match, never a guess. deactivate() only ever acts on a file
 * this lookup actually found active, and is only ever called from an
 * explicit, admin-clicked action (see Admin\ImportWizard) — never
 * automatically.
 */
final class PluginRegistry {

	/**
	 * Known plugin file candidates per import source (free and, where
	 * applicable, premium editions).
	 *
	 * @var array<string,string[]>
	 */
	private const CANDIDATES = array(
		'yoast'     => array(
			'wordpress-seo/wpseo.php',
			'wordpress-seo-premium/wpseo.php',
		),
		'rank_math' => array(
			'seo-by-rank-math/rank-math.php',
			'seo-by-rank-math-pro/rank-math-pro.php',
		),
		'aioseo'    => array(
			'all-in-one-seo-pack/all_in_one_seo_pack.php',
			'all-in-one-seo-pack-pro/all_in_one_seo_pack.php',
		),
	);

	/**
	 * Display names for each source, for confirmation UI.
	 *
	 * @var array<string,string>
	 */
	private const LABELS = array(
		'yoast'     => 'Yoast SEO',
		'rank_math' => 'Rank Math',
		'aioseo'    => 'All in One SEO',
	);

	/**
	 * The active plugin file for a source, or '' when none of its known
	 * candidates are actually active.
	 *
	 * @param string $source 'yoast', 'rank_math' or 'aioseo'.
	 */
	public static function active_plugin_file( string $source ): string {
		if ( ! isset( self::CANDIDATES[ $source ] ) ) {
			return '';
		}

		$active = array_merge( self::site_active_plugins(), self::network_active_plugins() );

		foreach ( self::CANDIDATES[ $source ] as $candidate ) {
			if ( in_array( $candidate, $active, true ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * The display label for a source.
	 *
	 * @param string $source 'yoast', 'rank_math' or 'aioseo'.
	 */
	public static function label( string $source ): string {
		return self::LABELS[ $source ] ?? $source;
	}

	/**
	 * Deactivates the active plugin file for a source, if one was found.
	 * Loads wp-admin's plugin.php on demand, since deactivate_plugins()
	 * isn't available outside the admin plugin-management screens by
	 * default.
	 *
	 * @param string $source 'yoast', 'rank_math' or 'aioseo'.
	 * @return bool True once confirmed inactive; false when nothing to deactivate or it's still active afterwards.
	 */
	public static function deactivate( string $source ): bool {
		$file = self::active_plugin_file( $source );
		if ( '' === $file ) {
			return false;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$network_wide = is_multisite() && in_array( $file, self::network_active_plugins(), true );

		deactivate_plugins( $file, false, $network_wide );

		return ! in_array( $file, array_merge( self::site_active_plugins(), self::network_active_plugins() ), true );
	}

	/**
	 * Plugins active for the current site.
	 *
	 * @return string[]
	 */
	private static function site_active_plugins(): array {
		$active = get_option( 'active_plugins', array() );

		return is_array( $active ) ? array_map( 'strval', $active ) : array();
	}

	/**
	 * Plugins active network-wide, on multisite; empty otherwise.
	 *
	 * @return string[]
	 */
	private static function network_active_plugins(): array {
		if ( ! is_multisite() ) {
			return array();
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) ? array_map( 'strval', array_keys( $active ) ) : array();
	}
}

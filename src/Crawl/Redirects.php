<?php
/**
 * URL redirect rules.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Crawl;

/**
 * Admin-defined redirect rules, matched by exact path against every front
 * end request before WordPress decides whether it's a 404. Rules are
 * stored as a flat, ID-keyed option array rather than a custom database
 * table — simple exact-path lookup against an in-memory array is fast
 * enough at the scale a redirect list actually reaches, and it avoids a
 * schema migration on activation.
 */
final class Redirects {

	/**
	 * Option key the rules are stored under.
	 */
	private const OPTION_KEY = 'seo_wp_redirects';

	/**
	 * Supported redirect/status types.
	 *
	 * @var int[]
	 */
	public const TYPES = array( 301, 302, 307, 410, 451 );

	/**
	 * Types that terminate the request with a status only, no Location
	 * header.
	 *
	 * @var int[]
	 */
	private const STATUS_ONLY_TYPES = array( 410, 451 );

	/**
	 * Registers the redirect dispatcher. Runs before anything else on
	 * template_redirect (including Crawl\SpecialArchives and
	 * Crawl\NotFoundMonitor), so a configured rule always wins regardless
	 * of what WordPress would otherwise have resolved the request to.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Redirects (or terminates with a Gone/Unavailable status) when the
	 * current request matches an active rule.
	 */
	public function maybe_redirect(): void {
		$rule = self::match( NotFoundMonitor::current_path() );
		if ( null === $rule ) {
			return;
		}

		self::increment_hits( (int) $rule['id'] );

		if ( in_array( $rule['type'], self::STATUS_ONLY_TYPES, true ) ) {
			status_header( $rule['type'] );
			nocache_headers();
			echo esc_html__( 'This content is no longer available.', 'seo-wp' );
			exit;
		}

		if ( '' === $rule['target'] ) {
			return;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is an admin-authored setting (not user input), and cross-domain targets are a legitimate, intentional use of a redirect manager.
		wp_redirect( $rule['target'], $rule['type'] );
		exit;
	}

	/**
	 * Finds the active rule matching a normalised path, if any.
	 *
	 * @param string $path Path as normalised by NotFoundMonitor::normalize_path().
	 * @return array{id:int,source:string,target:string,type:int,active:bool,hits:int}|null
	 */
	public static function match( string $path ): ?array {
		foreach ( self::all() as $id => $rule ) {
			if ( ! empty( $rule['active'] ) && $rule['source'] === $path ) {
				$rule['id'] = $id;
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Every stored rule, keyed by ID.
	 *
	 * @return array<int,array{source:string,target:string,type:int,active:bool,hits:int}>
	 */
	public static function all(): array {
		$rules = get_option( self::OPTION_KEY, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Creates or updates a rule.
	 *
	 * @param int                 $id   Existing rule ID to update, or 0 to create a new one.
	 * @param array<string,mixed> $rule Rule data: source, target, type, active.
	 * @return int The rule's ID.
	 */
	public static function save( int $id, array $rule ): int {
		$rules = self::all();

		$existing_hits = $id > 0 && isset( $rules[ $id ] ) ? (int) ( $rules[ $id ]['hits'] ?? 0 ) : 0;

		$source = self::normalize_path( isset( $rule['source'] ) ? (string) $rule['source'] : '' );
		$type   = isset( $rule['type'] ) ? (int) $rule['type'] : 301;

		$clean = array(
			'source' => $source,
			'target' => isset( $rule['target'] ) ? esc_url_raw( (string) $rule['target'] ) : '',
			'type'   => in_array( $type, self::TYPES, true ) ? $type : 301,
			'active' => ! empty( $rule['active'] ),
			'hits'   => $existing_hits,
		);

		if ( $id <= 0 || ! isset( $rules[ $id ] ) ) {
			$id = self::next_id( $rules );
		}

		$rules[ $id ] = $clean;

		update_option( self::OPTION_KEY, $rules, false );

		return $id;
	}

	/**
	 * Increments a rule's hit counter after it's matched a request.
	 *
	 * @param int $id Rule ID.
	 */
	private static function increment_hits( int $id ): void {
		$rules = self::all();
		if ( ! isset( $rules[ $id ] ) ) {
			return;
		}

		$rules[ $id ]['hits'] = (int) ( $rules[ $id ]['hits'] ?? 0 ) + 1;
		update_option( self::OPTION_KEY, $rules, false );
	}

	/**
	 * Deletes a rule.
	 *
	 * @param int $id Rule ID.
	 */
	public static function delete( int $id ): void {
		$rules = self::all();
		unset( $rules[ $id ] );
		update_option( self::OPTION_KEY, $rules, false );
	}

	/**
	 * Normalises a submitted source path via NotFoundMonitor's shared path
	 * normaliser, so a rule created from a 404 log entry (or pasted as a
	 * full URL) matches real requests reliably.
	 *
	 * @param string $source Raw source path or URL.
	 */
	public static function normalize_path( string $source ): string {
		return NotFoundMonitor::normalize_path( $source );
	}

	/**
	 * The next free integer ID.
	 *
	 * @param array<int,mixed> $rules Existing rules.
	 */
	private static function next_id( array $rules ): int {
		return array() === $rules ? 1 : max( array_keys( $rules ) ) + 1;
	}
}

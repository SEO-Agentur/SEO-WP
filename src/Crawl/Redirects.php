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
	 * Maximum accepted length for a regex rule's source pattern. Generous
	 * for any real redirect rule; bounds the worst-case PCRE search space.
	 */
	private const MAX_PATTERN_LENGTH = 500;

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
		$path = NotFoundMonitor::current_path();
		$rule = self::match( $path );
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

		$target = self::resolve_target( $rule, $path );
		if ( '' === $target ) {
			return;
		}

		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- target is an admin-authored setting (not user input), and cross-domain targets are a legitimate, intentional use of a redirect manager.
		wp_redirect( $target, $rule['type'] );
		exit;
	}

	/**
	 * Finds the active rule matching a normalised path, if any. Regex
	 * rules (is_regex) are tried in stored order against the raw pattern;
	 * exact rules compare the normalised source directly.
	 *
	 * @param string $path Path as normalised by NotFoundMonitor::normalize_path().
	 * @return array{id:int,source:string,target:string,type:int,active:bool,hits:int,is_regex:bool}|null
	 */
	public static function match( string $path ): ?array {
		foreach ( self::all() as $id => $rule ) {
			if ( empty( $rule['active'] ) ) {
				continue;
			}

			$matches = ! empty( $rule['is_regex'] )
				? self::regex_matches( $rule['source'], $path )
				: $rule['source'] === $path;

			if ( $matches ) {
				$rule['id'] = $id;
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Resolves a matched rule's redirect target, substituting PCRE
	 * backreferences (e.g. "$1") from the current path for regex rules —
	 * so a single rule like source "^/blog/(.+)$" / target "/news/$1" can
	 * cover a whole moved section instead of one row per URL.
	 *
	 * @param array{source:string,target:string,is_regex?:bool} $rule Matched rule.
	 * @param string                                            $path Path the rule matched against.
	 */
	public static function resolve_target( array $rule, string $path ): string {
		$target = $rule['target'];

		if ( empty( $rule['is_regex'] ) || ! self::is_valid_regex( $rule['source'] ) ) {
			return $target;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validity is already confirmed by is_valid_regex() above; suppressing only the redundant warning a malformed pattern would otherwise still raise.
		$replaced = @preg_replace( self::wrap_pattern( $rule['source'] ), $target, $path, 1 );

		return is_string( $replaced ) ? $replaced : $target;
	}

	/**
	 * Whether a user-authored pattern (without delimiters) compiles as a
	 * valid PCRE regex once wrapped. Pure and safe to call on untrusted
	 * input — never emits a warning of its own.
	 *
	 * @param string $pattern Pattern as authored, without delimiters.
	 */
	public static function is_valid_regex( string $pattern ): bool {
		$pattern = trim( $pattern );

		if ( '' === $pattern || strlen( $pattern ) > self::MAX_PATTERN_LENGTH || self::has_catastrophic_backtracking_shape( $pattern ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the boolean return value is the point: this is the validity check itself, so the E_WARNING a malformed pattern raises is expected noise, not a bug to surface.
		return false !== @preg_match( self::wrap_pattern( $pattern ), '' );
	}

	/**
	 * Rejects the classic nested-quantifier shape (e.g. "(a+)+", "(a*)*")
	 * that causes PCRE's catastrophic backtracking on a crafted, non-
	 * matching input — a ReDoS vector this plugin would otherwise let an
	 * admin (or a CSV import) plant, since is_valid_regex() only used to
	 * confirm a pattern *compiles*, not that it terminates quickly on
	 * every input. A heuristic, not a full static analyzer: it catches the
	 * common accidental/malicious shapes without rejecting ordinary
	 * redirect patterns like "^/blog/(.+)$".
	 *
	 * @param string $pattern Pattern as authored, without delimiters.
	 */
	private static function has_catastrophic_backtracking_shape( string $pattern ): bool {
		return 1 === preg_match( '/\([^()]*[+*][^()]*\)[+*]/', $pattern );
	}

	/**
	 * Whether a path matches a user-authored regex pattern.
	 *
	 * @param string $pattern Pattern as authored, without delimiters.
	 * @param string $path    Path to test.
	 */
	private static function regex_matches( string $pattern, string $path ): bool {
		if ( ! self::is_valid_regex( $pattern ) ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- validity already confirmed by is_valid_regex() above.
		return 1 === @preg_match( self::wrap_pattern( $pattern ), $path );
	}

	/**
	 * Wraps a delimiter-less user pattern in PCRE delimiters. "#" is used
	 * because it practically never appears in a URL path pattern, unlike
	 * "/" — sparing admins from having to escape the delimiter themselves,
	 * the same convention the Redirection plugin uses.
	 *
	 * @param string $pattern Pattern as authored, without delimiters.
	 */
	private static function wrap_pattern( string $pattern ): string {
		return '#' . $pattern . '#';
	}

	/**
	 * Every stored rule, keyed by ID.
	 *
	 * @return array<int,array{source:string,target:string,type:int,active:bool,hits:int,is_regex:bool}>
	 */
	public static function all(): array {
		$rules = get_option( self::OPTION_KEY, array() );

		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Creates or updates a rule.
	 *
	 * @param int                 $id   Existing rule ID to update, or 0 to create a new one.
	 * @param array<string,mixed> $rule Rule data: source, target, type, active, is_regex.
	 * @return int The rule's ID.
	 */
	public static function save( int $id, array $rule ): int {
		$rules = self::all();

		$existing_hits = $id > 0 && isset( $rules[ $id ] ) ? (int) ( $rules[ $id ]['hits'] ?? 0 ) : 0;

		$is_regex   = ! empty( $rule['is_regex'] );
		$raw_source = isset( $rule['source'] ) ? (string) $rule['source'] : '';
		// A regex pattern isn't a path: normalize_path() would wp_parse_url()
		// and slash-trim it, corrupting anything but the simplest pattern.
		// Only trim incidental whitespace instead.
		$source = $is_regex ? trim( $raw_source ) : self::normalize_path( $raw_source );
		$type   = isset( $rule['type'] ) ? (int) $rule['type'] : 301;

		$clean = array(
			'source'   => $source,
			'target'   => isset( $rule['target'] ) ? esc_url_raw( (string) $rule['target'] ) : '',
			'type'     => in_array( $type, self::TYPES, true ) ? $type : 301,
			'active'   => ! empty( $rule['active'] ),
			'is_regex' => $is_regex,
			'hits'     => $existing_hits,
		);

		if ( $id <= 0 || ! isset( $rules[ $id ] ) ) {
			$id = self::next_id( $rules );
		}

		$rules[ $id ] = $clean;

		update_option( self::OPTION_KEY, $rules, false );

		return $id;
	}

	/**
	 * Parses one CSV row (already split into columns, keyed by lower-cased
	 * header name) into a clean payload for save(), or null when it has no
	 * source. Pure array transformation, independent of the filesystem, so
	 * the mapping itself is easy to unit test without a real upload.
	 *
	 * @param array<string,string> $row One CSV row, keyed by header name.
	 * @return array{source:string,target:string,type:int,active:bool,is_regex:bool}|null
	 */
	public static function parse_csv_row( array $row ): ?array {
		$source = trim( (string) ( $row['source'] ?? '' ) );
		if ( '' === $source ) {
			return null;
		}

		$type = (int) ( $row['type'] ?? 301 );

		return array(
			'source'   => $source,
			'target'   => trim( (string) ( $row['target'] ?? '' ) ),
			'type'     => in_array( $type, self::TYPES, true ) ? $type : 301,
			'active'   => self::parse_csv_bool( (string) ( $row['active'] ?? '1' ) ),
			'is_regex' => self::parse_csv_bool( (string) ( $row['is_regex'] ?? '0' ) ),
		);
	}

	/**
	 * Interprets a CSV cell as a boolean: "1", "true" and "yes"
	 * (case-insensitive) are true, everything else is false.
	 *
	 * @param string $value Raw cell value.
	 */
	private static function parse_csv_bool( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * Flattens one stored rule into the CSV row shape exported/re-imported
	 * by RedirectsPage — the exact counterpart of parse_csv_row(). Pure,
	 * for the same testing reason.
	 *
	 * @param array{source:string,target:string,type:int,active:bool,is_regex?:bool,hits?:int} $rule Stored rule.
	 * @return array<string,string>
	 */
	public static function to_csv_row( array $rule ): array {
		return array(
			'source'   => $rule['source'],
			'target'   => $rule['target'],
			'type'     => (string) $rule['type'],
			'active'   => ! empty( $rule['active'] ) ? '1' : '0',
			'is_regex' => ! empty( $rule['is_regex'] ) ? '1' : '0',
		);
	}

	/**
	 * The CSV header row used by both export and the import parser.
	 *
	 * @return string[]
	 */
	public static function csv_header(): array {
		return array( 'source', 'target', 'type', 'active', 'is_regex' );
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

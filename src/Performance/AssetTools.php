<?php
/**
 * Pure helpers for the asset optimizer: CSS and JavaScript minifiers, URL
 * handling and the grouping of files that may be combined.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Performance;

/**
 * No WordPress calls in here, so every branch is unit-testable. The
 * minifiers are deliberately conservative: they only remove what cannot
 * change behaviour (comments, indentation, redundant spaces) and copy
 * strings, URLs, regular expressions and template literals verbatim.
 */
final class AssetTools {

	/**
	 * Keywords after which a "/" starts a regular expression, not a division.
	 *
	 * @var string[]
	 */
	private const REGEX_KEYWORDS = array( 'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void', 'throw', 'case', 'do', 'else', 'yield', 'await' );

	/**
	 * Characters after which a "/" starts a regular expression.
	 */
	private const REGEX_PRECEDERS = '(,=:[!&|?{};+-*%<>~^';

	/**
	 * Minifies CSS: drops comments (except /*! ... *\/ licence comments),
	 * collapses whitespace and removes the spaces around { } ; , > and the
	 * last semicolon of a block. Strings, url(...) and escapes are copied
	 * untouched.
	 *
	 * @param string $css Source.
	 */
	public static function minify_css( string $css ): string {
		$length  = strlen( $css );
		$out     = '';
		$pending = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $css[ $i ];

			if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
				$end     = strpos( $css, '*/', $i + 2 );
				$end     = false === $end ? $length : $end + 2;
				$comment = substr( $css, $i, $end - $i );
				if ( isset( $comment[2] ) && '!' === $comment[2] ) {
					$out .= $comment;
				} else {
					$pending = '' !== $out;
				}
				$i = $end - 1;
				continue;
			}

			if ( ctype_space( $char ) ) {
				$pending = '' !== $out;
				continue;
			}

			if ( $pending ) {
				$last = '' === $out ? '' : $out[ strlen( $out ) - 1 ];
				if ( false === strpos( '{};,>', $last ) && false === strpos( '{};,>', $char ) ) {
					$out .= ' ';
				}
				$pending = false;
			}

			if ( '"' === $char || "'" === $char ) {
				$end  = self::string_end( $css, $i, $char );
				$out .= substr( $css, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}

			if ( '\\' === $char ) {
				$out .= substr( $css, $i, 2 );
				++$i;
				continue;
			}

			if ( ( 'u' === $char || 'U' === $char ) && 0 === strncasecmp( substr( $css, $i, 4 ), 'url(', 4 ) ) {
				$end  = self::url_end( $css, $i + 4 );
				$out .= substr( $css, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}

			if ( '}' === $char && '' !== $out && ';' === $out[ strlen( $out ) - 1 ] ) {
				$out = substr( $out, 0, -1 );
			}

			$out .= $char;
		}

		return trim( $out );
	}

	/**
	 * Position just after a quoted string that starts at $start.
	 *
	 * @param string $source Source text.
	 * @param int    $start  Index of the opening quote.
	 * @param string $quote  The quote character.
	 */
	private static function string_end( string $source, int $start, string $quote ): int {
		$length = strlen( $source );

		for ( $i = $start + 1; $i < $length; $i++ ) {
			if ( '\\' === $source[ $i ] ) {
				++$i;
				continue;
			}
			if ( $quote === $source[ $i ] || ( "\n" === $source[ $i ] && '`' !== $quote ) ) {
				return $i + 1;
			}
		}

		return $length;
	}

	/**
	 * Position just after the closing ")" of a url( ... ) whose content
	 * starts at $start; quoted and unquoted contents are both handled.
	 *
	 * @param string $css   Source.
	 * @param int    $start Index just after "url(".
	 */
	private static function url_end( string $css, int $start ): int {
		$length = strlen( $css );
		$i      = $start;

		while ( $i < $length && ctype_space( $css[ $i ] ) ) {
			++$i;
		}
		if ( $i < $length && ( '"' === $css[ $i ] || "'" === $css[ $i ] ) ) {
			$i = self::string_end( $css, $i, $css[ $i ] );
		}
		while ( $i < $length && ')' !== $css[ $i ] ) {
			++$i;
		}

		return min( $length, $i + 1 );
	}

	/**
	 * Whether a stylesheet contains an @import rule (which cannot be
	 * combined safely: it must stay first and is fetched separately).
	 *
	 * @param string $css Source.
	 */
	public static function css_has_import( string $css ): bool {
		$without_comments = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		return 1 === preg_match( '/@import\b/i', $without_comments );
	}

	/**
	 * Rewrites relative url(...) references so they still point at the same
	 * files when the stylesheet is served from another folder, and drops
	 * a leading @charset (the combined file is UTF-8 anyway).
	 *
	 * @param string $css      Source.
	 * @param string $file_url Address of the original stylesheet.
	 */
	public static function absolutize_css_urls( string $css, string $file_url ): string {
		$bare = strtok( $file_url, '?#' );
		$bare = false === $bare ? $file_url : $bare;
		$base = substr( $file_url, 0, (int) strrpos( $bare, '/' ) + 1 );

		$css = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $css );
		$css = (string) preg_replace( '/^\s*@charset\s+"[^"]*"\s*;/i', '', $css );

		return (string) preg_replace_callback(
			'/url\(\s*(["\']?)([^"\')]+?)\1\s*\)/i',
			static function ( array $m ) use ( $base ): string {
				$ref = $m[2];
				if ( 1 === preg_match( '#^(?:data:|https?:|//|/|\#)#i', $ref ) ) {
					return $m[0];
				}

				return 'url(' . $m[1] . self::resolve_url( $base, $ref ) . $m[1] . ')';
			},
			$css
		);
	}

	/**
	 * Resolves a relative reference ("../img/a.png") against a base folder URL.
	 *
	 * @param string $base Base folder URL, ending in "/".
	 * @param string $ref  Relative reference.
	 */
	public static function resolve_url( string $base, string $ref ): string {
		$parts = self::split_origin( $base );
		$path  = $parts['path'];
		$stack = explode( '/', trim( $path, '/' ) );
		if ( array( '' ) === $stack ) {
			$stack = array();
		}

		$suffix = '';
		if ( 1 === preg_match( '/^([^?#]*)([?#].*)$/', $ref, $m ) ) {
			$ref    = $m[1];
			$suffix = $m[2];
		}

		foreach ( explode( '/', $ref ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $stack );
			} elseif ( '.' !== $segment && '' !== $segment ) {
				$stack[] = $segment;
			}
		}

		return $parts['origin'] . '/' . implode( '/', $stack ) . $suffix;
	}

	/**
	 * Minifies JavaScript conservatively: removes comments (keeping
	 * licence comments), indentation and redundant spaces, and joins
	 * lines only where that can never change how the code is parsed. Line
	 * breaks that might act as automatic semicolons are kept. Strings,
	 * template literals and regular expressions are copied untouched.
	 *
	 * @param string $js Source.
	 */
	public static function minify_js( string $js ): string {
		$length = strlen( $js );
		$out    = '';
		$space  = false;
		$break  = false;

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $js[ $i ];

			if ( '/' === $char && $i + 1 < $length && '/' === $js[ $i + 1 ] ) {
				$end  = strpos( $js, "\n", $i );
				$end  = false === $end ? $length : $end;
				$line = substr( $js, $i, $end - $i );
				if ( 1 === preg_match( '#^//[!\#@]?\s*(?:@license|@preserve|@cc_on)#', $line ) || ( isset( $line[2] ) && '!' === $line[2] ) ) {
					$out  .= ( '' !== $out && "\n" !== $out[ strlen( $out ) - 1 ] ? "\n" : '' ) . $line;
					$break = true;
				} else {
					$break = $break || $space || '' !== $out;
				}
				$i = $end - 1;
				continue;
			}

			if ( '/' === $char && $i + 1 < $length && '*' === $js[ $i + 1 ] ) {
				$end     = strpos( $js, '*/', $i + 2 );
				$end     = false === $end ? $length : $end + 2;
				$comment = substr( $js, $i, $end - $i );
				if ( 1 === preg_match( '#^/\*[!*]?\s*(?:!|@license|@preserve|@cc_on)#', $comment ) || ( isset( $comment[2] ) && '!' === $comment[2] ) ) {
					$out .= ( '' !== $out && "\n" !== $out[ strlen( $out ) - 1 ] ? "\n" : '' ) . $comment . "\n";
				} elseif ( false !== strpos( $comment, "\n" ) ) {
					$break = '' !== $out;
				} else {
					$space = '' !== $out;
				}
				$i = $end - 1;
				continue;
			}

			if ( ctype_space( $char ) ) {
				if ( "\n" === $char || "\r" === $char ) {
					$break = '' !== $out;
				} else {
					$space = '' !== $out;
				}
				continue;
			}

			if ( $break || $space ) {
				$out  .= self::separator( $out, $char, $break );
				$break = false;
				$space = false;
			}

			if ( '"' === $char || "'" === $char ) {
				$end  = self::string_end( $js, $i, $char );
				$out .= substr( $js, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}

			if ( '`' === $char ) {
				$end  = self::template_end( $js, $i );
				$out .= substr( $js, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}

			if ( '/' === $char && self::regex_allowed( $out ) ) {
				$end  = self::regex_end( $js, $i );
				$out .= substr( $js, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}

			$out .= $char;
		}

		return trim( $out );
	}

	/**
	 * What to put between the code before and the next character after
	 * whitespace: nothing, a space or a line break.
	 *
	 * @param string $out   Output so far.
	 * @param string $next  The next character.
	 * @param bool   $line_break Whether the whitespace contained a line break.
	 */
	private static function separator( string $out, string $next, bool $line_break ): string {
		if ( '' === $out ) {
			return '';
		}

		$prev = $out[ strlen( $out ) - 1 ];

		if ( $line_break ) {
			if ( false !== strpos( '{([,;', $prev ) || false !== strpos( '})]', $next ) || "\n" === $prev ) {
				return '';
			}

			return "\n";
		}

		$tight = '{}()[];,:=';
		if ( false !== strpos( $tight, $prev ) || false !== strpos( $tight, $next ) ) {
			return '';
		}

		// Everything else keeps one space: it covers "x + +y", "x - -y" and "a < !--b",
		// where removing it would change how the code is read.
		return ' ';
	}

	/**
	 * Whether a "/" at this point starts a regular expression literal,
	 * judging from the last significant character or word before it.
	 *
	 * @param string $out Output so far.
	 */
	private static function regex_allowed( string $out ): bool {
		$trimmed = rtrim( $out );
		if ( '' === $trimmed ) {
			return true;
		}

		$last = $trimmed[ strlen( $trimmed ) - 1 ];
		if ( false !== strpos( self::REGEX_PRECEDERS, $last ) ) {
			return true;
		}

		if ( 1 === preg_match( '/([A-Za-z_$][\w$]*)$/', $trimmed, $m ) ) {
			return in_array( $m[1], self::REGEX_KEYWORDS, true );
		}

		return false;
	}

	/**
	 * Position just after a regular expression literal starting at $start.
	 *
	 * @param string $js    Source.
	 * @param int    $start Index of the opening "/".
	 */
	private static function regex_end( string $js, int $start ): int {
		$length   = strlen( $js );
		$in_class = false;

		for ( $i = $start + 1; $i < $length; $i++ ) {
			$char = $js[ $i ];
			if ( '\\' === $char ) {
				++$i;
			} elseif ( "\n" === $char ) {
				return $i;
			} elseif ( '[' === $char ) {
				$in_class = true;
			} elseif ( ']' === $char ) {
				$in_class = false;
			} elseif ( '/' === $char && ! $in_class ) {
				++$i;
				while ( $i < $length && ctype_alpha( $js[ $i ] ) ) {
					++$i;
				}

				return $i;
			}
		}

		return $length;
	}

	/**
	 * Position just after a template literal starting at $start, including
	 * nested ${ ... } expressions (which may contain templates themselves).
	 *
	 * @param string $js    Source.
	 * @param int    $start Index of the opening backtick.
	 */
	private static function template_end( string $js, int $start ): int {
		$length = strlen( $js );

		for ( $i = $start + 1; $i < $length; $i++ ) {
			$char = $js[ $i ];
			if ( '\\' === $char ) {
				++$i;
			} elseif ( '`' === $char ) {
				return $i + 1;
			} elseif ( '$' === $char && $i + 1 < $length && '{' === $js[ $i + 1 ] ) {
				$i = self::expression_end( $js, $i + 2 ) - 1;
			}
		}

		return $length;
	}

	/**
	 * Position just after the "}" that closes a template expression whose
	 * content starts at $start, skipping strings and nested templates.
	 *
	 * @param string $js    Source.
	 * @param int    $start Index just after "${".
	 */
	private static function expression_end( string $js, int $start ): int {
		$length = strlen( $js );
		$depth  = 1;
		$pos    = $start;

		while ( $pos < $length && $depth > 0 ) {
			$inner = $js[ $pos ];
			if ( '`' === $inner ) {
				$pos = self::template_end( $js, $pos );
				continue;
			}
			if ( '"' === $inner || "'" === $inner ) {
				$pos = self::string_end( $js, $pos, $inner );
				continue;
			}
			if ( '{' === $inner ) {
				++$depth;
			} elseif ( '}' === $inner ) {
				--$depth;
			}
			++$pos;
		}

		return $pos;
	}

	/**
	 * Whether the script starts with a "use strict" directive — which would
	 * make everything after it in a combined file strict too.
	 *
	 * @param string $js Source.
	 */
	public static function js_starts_strict( string $js ): bool {
		$head = (string) preg_replace( '#^(?:\s+|//[^\n]*\n?|/\*.*?\*/)+#s', '', ltrim( $js, "\xEF\xBB\xBF" ) );

		return 1 === preg_match( '/^([\'"])use strict\1/', $head );
	}

	/**
	 * Removes "sourceMappingURL" comment lines (they would point at the
	 * wrong file once the code is combined or minified).
	 *
	 * @param string $code JavaScript or CSS.
	 */
	public static function strip_source_map_comment( string $code ): string {
		return (string) preg_replace( '#^[ \t]*(?://[\#@]|/\*[\#@])[ \t]*sourceMappingURL=[^\r\n]*$#m', '', $code );
	}

	/**
	 * Maps a file address to a path on disk using the given base pairs
	 * (address prefix => folder); the longest matching prefix wins and the
	 * scheme is ignored. Returns '' when no base matches or the address
	 * tries to climb out of its folder.
	 *
	 * @param string               $url   File address, with or without query string.
	 * @param array<string,string> $bases Address prefix => folder without trailing slash.
	 */
	public static function url_to_path( string $url, array $bases ): string {
		$url = (string) strtok( $url, '?#' );
		$url = rawurldecode( (string) preg_replace( '#^https?:#i', '', $url ) );
		if ( 0 !== strpos( $url, '//' ) || false !== strpos( $url, '..' ) || false !== strpos( $url, "\0" ) ) {
			return '';
		}

		$best     = '';
		$best_dir = '';
		foreach ( $bases as $prefix => $dir ) {
			$prefix = rtrim( (string) preg_replace( '#^https?:#i', '', $prefix ), '/' ) . '/';
			if ( 0 === strpos( $url, $prefix ) && strlen( $prefix ) > strlen( $best ) ) {
				$best     = $prefix;
				$best_dir = rtrim( $dir, '/' );
			}
		}

		if ( '' === $best ) {
			return '';
		}

		return $best_dir . '/' . substr( $url, strlen( $best ) );
	}

	/**
	 * Splits the print order of a batch into runs of neighbouring files
	 * that can go into one combined file. An item is [handle, key]; a null
	 * key marks a file that cannot be combined and ends the current run;
	 * a change of key (stylesheet media, script type) also starts a new
	 * run. Without combining every eligible file is a run of its own.
	 *
	 * @param array<int,array{0:string,1:?string}> $items   Handle and grouping key in print order.
	 * @param bool                                 $combine Whether neighbours may share a file.
	 * @return array<int,string[]> Runs of handles (ineligible files are not listed).
	 */
	public static function plan_runs( array $items, bool $combine ): array {
		$runs    = array();
		$current = array();
		$key     = null;

		foreach ( $items as $item ) {
			list( $handle, $item_key ) = $item;

			if ( null === $item_key ) {
				if ( array() !== $current ) {
					$runs[] = $current;
				}
				$current = array();
				$key     = null;
				continue;
			}

			if ( array() !== $current && ( ! $combine || $item_key !== $key ) ) {
				$runs[]  = $current;
				$current = array();
			}

			$current[] = $handle;
			$key       = $item_key;
		}

		if ( array() !== $current ) {
			$runs[] = $current;
		}

		return $runs;
	}

	/**
	 * Splits an absolute URL into its origin and path.
	 *
	 * @param string $url Absolute URL.
	 * @return array{origin:string,path:string}
	 */
	private static function split_origin( string $url ): array {
		if ( 1 === preg_match( '#^((?:https?:)?//[^/?\#]+)(/[^?\#]*)?#i', $url, $m ) ) {
			return array(
				'origin' => $m[1],
				'path'   => $m[2] ?? '/',
			);
		}

		return array(
			'origin' => '',
			'path'   => '/' . ltrim( (string) strtok( $url, '?#' ), '/' ),
		);
	}

	/**
	 * Parses the exclusion lines of the settings: one handle or address
	 * fragment per line, "#" starts a comment.
	 *
	 * @param string $text Textarea content.
	 * @return string[]
	 */
	public static function parse_list( string $text ): array {
		$out = array();

		$lines = preg_split( '/\R/', $text );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) preg_replace( '/\s+#.*$/', '', $line ) );
			if ( '' !== $line && '#' !== $line[0] ) {
				$out[] = strtolower( $line );
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a handle or file address matches one of the exclusion entries.
	 *
	 * @param string   $handle Registered handle.
	 * @param string   $src    File address.
	 * @param string[] $entries Entries from parse_list().
	 */
	public static function is_excluded( string $handle, string $src, array $entries ): bool {
		$handle = strtolower( $handle );
		$src    = strtolower( $src );

		foreach ( $entries as $entry ) {
			if ( $entry === $handle || ( ( false !== strpos( $entry, '/' ) || false !== strpos( $entry, '.' ) ) && false !== strpos( $src, $entry ) ) ) {
				return true;
			}
		}

		return false;
	}
}

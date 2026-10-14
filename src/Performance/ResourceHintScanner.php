<?php
/**
 * Scans the site's own rendered front page for external resource origins
 * worth a dns-prefetch/preconnect hint.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Performance;

/**
 * The Performance tab's "Resource hints" field is free text an admin
 * fills in by hand — this doesn't replace that, it just suggests what to
 * put there. Fetches the site's own front page (a real page load, not
 * WordPress's own script/style registries, which are only ever populated
 * for the specific request being rendered — a wp-admin request never has
 * the front end's actual enqueues loaded) and extracts every external
 * host referenced by a <script src> or <link href>, counted by how many
 * distinct resources come from it. A host with more than one resource is
 * suggested as preconnect (worth the extra connection setup); a host
 * with exactly one is suggested as the cheaper dns-prefetch. Never
 * applied automatically — only ever a suggestion an admin reviews and
 * adds to the resource hints field by hand.
 */
final class ResourceHintScanner {

	/**
	 * Pages fetched per scan: the home page plus the newest public post of
	 * a few content types, so a host used only on blog posts or product
	 * pages is found too.
	 */
	private const MAX_PAGES = 5;

	/**
	 * Stylesheets fetched per scan to look for @font-face files and other
	 * hosts referenced from inside CSS.
	 */
	private const MAX_STYLESHEETS = 6;

	/**
	 * Largest stylesheet body that is parsed, in bytes.
	 */
	private const MAX_CSS_BYTES = 300000;

	/**
	 * Fetches the front page and a few sample pages, plus the stylesheets
	 * they load, and returns what was found.
	 *
	 * @return array{lines:string[],rows:array<int,array<string,mixed>>,pages:int}|\WP_Error Suggested hint lines, per-host details and the number of pages scanned; a WP_Error if not even the home page could be fetched.
	 */
	public static function scan() {
		$own_host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$pages    = array();
		$first    = null;

		foreach ( self::sample_urls() as $url ) {
			$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

			if ( is_wp_error( $response ) ) {
				if ( null === $first ) {
					return $response;
				}
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 400 ) {
				if ( null === $first ) {
					return new \WP_Error(
						'seo_wp_resource_hint_scan_failed',
						sprintf(
						/* translators: %d: HTTP status code. */
							__( 'The homepage returned HTTP %d.', 'seooptimierung-toolkit' ),
							$code
						)
					);
				}
				continue;
			}

			$first         = $first ?? $url;
			$pages[ $url ] = (string) wp_remote_retrieve_body( $response );
		}

		$css = array();
		foreach ( array_slice( self::own_stylesheet_urls( $pages, $own_host ), 0, self::MAX_STYLESHEETS ) as $css_url ) {
			$response = wp_remote_get( $css_url, array( 'timeout' => 6 ) );
			if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) < 400 ) {
				$css[ $css_url ] = substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_CSS_BYTES );
			}
		}

		$rows = self::analyse( array_values( $pages ), $css, $own_host, self::configured_hint_hosts() );

		$lines = array();
		foreach ( $rows as $row ) {
			if ( ! $row['hinted'] ) {
				$lines = array_merge( $lines, $row['suggestions'] );
			}
		}

		return array(
			'lines' => array_values( array_unique( $lines ) ),
			'rows'  => $rows,
			'pages' => count( $pages ),
		);
	}

	/**
	 * The home page plus the newest public post of up to four content
	 * types.
	 *
	 * @return string[]
	 */
	private static function sample_urls(): array {
		$urls = array( home_url( '/' ) );

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			if ( count( $urls ) >= self::MAX_PAGES || 'attachment' === $post_type ) {
				continue;
			}

			$latest = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'posts_per_page'         => 1,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'has_password'           => false,
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( array() !== $latest ) {
				$permalink = get_permalink( $latest[0] );
				if ( is_string( $permalink ) && '' !== $permalink && ! in_array( $permalink, $urls, true ) ) {
					$urls[] = $permalink;
				}
			}
		}

		return $urls;
	}

	/**
	 * Hosts that already have a preconnect/dns-prefetch/preload line in
	 * the plugin's own Resource Hints field.
	 *
	 * @return string[]
	 */
	private static function configured_hint_hosts(): array {
		$settings = get_option( 'seo_wp_settings', array() );
		$raw      = is_array( $settings ) && isset( $settings['resource_hints'] ) ? (string) $settings['resource_hints'] : '';

		return self::hinted_hosts_from_text( $raw );
	}

	/**
	 * Hosts named in a Resource Hints textarea.
	 *
	 * @param string $raw Textarea content, one hint per line.
	 * @return string[]
	 */
	public static function hinted_hosts_from_text( string $raw ): array {
		$hosts = array();

		foreach ( (array) preg_split( '/[\r\n]+/', $raw ) as $line ) {
			$parts = preg_split( '/\s+/', trim( (string) $line ) );
			if ( is_array( $parts ) && count( $parts ) >= 2 && '#' !== substr( $parts[0], 0, 1 ) ) {
				$host = strtolower( (string) parse_url( $parts[1], PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.
				if ( '' !== $host ) {
					$hosts[] = $host;
				}
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Stylesheet URLs linked from the scanned pages, absolute.
	 *
	 * @param array<string,string> $pages Page URL => HTML.
	 * @return string[]
	 */
	private static function stylesheet_urls( array $pages ): array {
		$urls = array();

		foreach ( $pages as $page_url => $html ) {
			foreach ( self::tags( $html, 'link' ) as $attrs ) {
				if ( isset( $attrs['href'] ) && isset( $attrs['rel'] ) && false !== stripos( $attrs['rel'], 'stylesheet' ) ) {
					$urls[] = self::absolute( $attrs['href'], $page_url );
				}
			}
		}

		return array_values( array_unique( array_filter( $urls ) ) );
	}

	/**
	 * Stylesheets served from this site's own host. Only these are
	 * fetched: the scanner never contacts a third-party URL, so a font
	 * stylesheet on another origin is recognised by its host alone.
	 *
	 * @param array<string,string> $pages    Page URL => HTML.
	 * @param string               $own_host This site's host.
	 * @return string[]
	 */
	private static function own_stylesheet_urls( array $pages, string $own_host ): array {
		return array_values(
			array_filter(
				self::stylesheet_urls( $pages ),
				static function ( string $url ) use ( $own_host ): bool {
					return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( $own_host );
				}
			)
		);
	}

	/**
	 * Analyses the HTML of several pages (and optionally the CSS they
	 * load) for the origins they pull resources from. Pure string logic,
	 * independent of WordPress, for easy unit testing.
	 *
	 * Per external host: how many distinct resources of which kind it
	 * serves, on how many of the scanned pages, whether a hint for it
	 * already exists, and ready-to-paste hint lines. A host serving
	 * render-blocking things (stylesheets, scripts, fonts) is suggested as
	 * preconnect; one serving only images/iframes/media — nothing the page
	 * needs to start rendering — as the cheaper dns-prefetch. Font files get an extra preload suggestion, since a
	 * late-discovered font is the classic cause of text popping in.
	 *
	 * @param string[]                 $pages_html HTML of each scanned page.
	 * @param array<int|string,string> $css_texts Content of stylesheets loaded by those pages, keyed by stylesheet URL (so relative url() references can be resolved).
	 * @param string                   $own_host   This site's own host (its fonts are still reported, as preload candidates).
	 * @param string[]                 $hinted     Hosts that already have a resource hint.
	 * @return array<int,array{host:string,total:int,types:array<string,int>,pages:int,hinted:bool,suggestions:string[]}>
	 */
	public static function analyse( array $pages_html, array $css_texts, string $own_host, array $hinted = array() ): array {
		$own_host = strtolower( $own_host );
		$hinted   = array_map( 'strtolower', $hinted );
		$by_host  = array();

		$add = static function ( string $url, string $type, $page_index ) use ( &$by_host, $own_host ): void {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return;
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.
			if ( '' === $host ) {
				return;
			}

			$is_font = (bool) preg_match( '/\.(woff2?|ttf|otf)(\?|#|$)/i', $url );
			if ( $host === $own_host && ! $is_font ) {
				return;
			}

			$type = $is_font ? 'font' : $type;

			$by_host[ $host ]['urls'][ $url ]         = $type;
			$by_host[ $host ]['pages'][ $page_index ] = true;
			$by_host[ $host ]['fonts']                = $by_host[ $host ]['fonts'] ?? array();
			if ( $is_font && preg_match( '/\.woff2(\?|#|$)/i', $url ) ) {
				$by_host[ $host ]['fonts'][ $url ] = true;
			}
		};

		foreach ( $pages_html as $index => $html ) {
			foreach ( self::tags( $html, 'script' ) as $attrs ) {
				if ( isset( $attrs['src'] ) ) {
					$add( $attrs['src'], 'script', $index );
				}
			}
			foreach ( self::tags( $html, 'link' ) as $attrs ) {
				$rel = strtolower( $attrs['rel'] ?? '' );
				if ( ! isset( $attrs['href'] ) || false !== strpos( $rel, 'preconnect' ) || false !== strpos( $rel, 'dns-prefetch' ) ) {
					continue;
				}
				$type = false !== strpos( $rel, 'stylesheet' ) ? 'style' : ( 'font' === strtolower( $attrs['as'] ?? '' ) ? 'font' : 'other' );
				$add( $attrs['href'], $type, $index );
			}
			foreach ( array( 'img', 'source', 'video', 'audio', 'iframe', 'embed' ) as $tag ) {
				$type = 'iframe' === $tag || 'embed' === $tag ? 'iframe' : ( 'img' === $tag ? 'image' : 'media' );
				foreach ( self::tags( $html, $tag ) as $attrs ) {
					foreach ( array( 'src', 'poster', 'data-src' ) as $attr ) {
						if ( isset( $attrs[ $attr ] ) ) {
							$add( $attrs[ $attr ], $type, $index );
						}
					}
					if ( isset( $attrs['srcset'] ) ) {
						foreach ( explode( ',', $attrs['srcset'] ) as $candidate ) {
							$parts = preg_split( '/\s+/', trim( $candidate ) );
							$add( (string) ( $parts[0] ?? '' ), $type, $index );
						}
					}
				}
			}
		}

		foreach ( $css_texts as $css_base => $css ) {
			$base = is_string( $css_base ) ? $css_base : '';

			if ( preg_match_all( '/url\(\s*[\'"]?([^\'")\s]+)[\'"]?\s*\)/i', $css, $matches ) ) {
				foreach ( $matches[1] as $url ) {
					$add( self::absolute( $url, $base ), 'image', 'css' );
				}
			}
			if ( preg_match_all( '/@import\s+(?:url\()?[\'"]?([^\'")\s;]+)/i', $css, $matches ) ) {
				foreach ( $matches[1] as $url ) {
					$add( self::absolute( $url, $base ), 'style', 'css' );
				}
			}
		}

		// Google Fonts' stylesheet lives on fonts.googleapis.com but the font
		// files themselves on fonts.gstatic.com, which only shows up inside
		// that (third-party, never fetched) stylesheet — so it is implied.
		if ( isset( $by_host['fonts.googleapis.com'] ) && ! isset( $by_host['fonts.gstatic.com'] ) ) {
			$by_host['fonts.gstatic.com'] = array(
				'urls'  => array( 'https://fonts.gstatic.com/' => 'font' ),
				'pages' => $by_host['fonts.googleapis.com']['pages'],
				'fonts' => array(),
			);
		}

		$blocking = array( 'style', 'script', 'font' );
		$rows     = array();

		foreach ( $by_host as $host => $data ) {
			$types = array();
			foreach ( $data['urls'] as $type ) {
				$types[ $type ] = ( $types[ $type ] ?? 0 ) + 1;
			}
			arsort( $types );

			$only_own_fonts = $host === $own_host;
			$is_blocking    = array() !== array_intersect( array_keys( $types ), $blocking );
			$suggestions    = array();

			if ( ! $only_own_fonts ) {
				$suggestions[] = ( $is_blocking ? 'preconnect' : 'dns-prefetch' ) . ' https://' . $host . ( isset( $types['font'] ) ? ' crossorigin' : '' );
			}

			foreach ( array_slice( array_keys( $data['fonts'] ), 0, 2 ) as $font_url ) {
				$suggestions[] = 'preload ' . $font_url . ' as=font type=font/woff2 crossorigin';
			}

			if ( array() === $suggestions ) {
				continue;
			}

			$rows[] = array(
				'host'        => $host,
				'total'       => count( $data['urls'] ),
				'types'       => $types,
				'pages'       => count( $data['pages'] ),
				'hinted'      => in_array( $host, $hinted, true ),
				'suggestions' => $suggestions,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b['pages'], $b['total'] ) <=> array( $a['pages'], $a['total'] );
			}
		);

		return $rows;
	}

	/**
	 * Attribute maps of every opening <tag ...> of one tag name. Pure
	 * string logic, independent of WordPress.
	 *
	 * @param string $html HTML to scan.
	 * @param string $tag  Tag name.
	 * @return array<int,array<string,string>>
	 */
	private static function tags( string $html, string $tag ): array {
		$found = array();

		if ( ! preg_match_all( '/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i', $html, $matches ) ) {
			return $found;
		}

		foreach ( $matches[1] as $attr_string ) {
			$attrs = array();
			if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $attr_string, $pairs, PREG_SET_ORDER ) ) {
				foreach ( $pairs as $pair ) {
					$attrs[ strtolower( $pair[1] ) ] = html_entity_decode( '' !== $pair[2] ? $pair[2] : ( $pair[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
				}
			}
			$found[] = $attrs;
		}

		return $found;
	}

	/**
	 * Resolves a possibly relative URL against the document it appeared
	 * in. Returns '' when it can't be resolved (no usable base).
	 *
	 * @param string $url  URL as written in the markup or CSS.
	 * @param string $base URL of the page or stylesheet it appeared in.
	 */
	private static function absolute( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}

		$parts = parse_url( $base ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return '';
		}

		$root = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}

		$dir      = isset( $parts['path'] ) ? substr( $parts['path'], 0, (int) strrpos( $parts['path'], '/' ) + 1 ) : '/';
		$segments = array();
		foreach ( explode( '/', $dir . preg_replace( '/[?#].*$/', '', $url ) ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '' !== $segment && '.' !== $segment ) {
				$segments[] = $segment;
			}
		}

		return $root . '/' . implode( '/', $segments );
	}
}

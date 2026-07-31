<?php
/**
 * Internal {token} syntax and conversion from/to %token%, %%token%%, #token.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Templates;

/**
 * Resolves internal tokens and converts between the internal {token}
 * syntax and the external syntaxes used by the UI, Yoast, Rank Math and
 * AIOSEO.
 */
final class Tokens {

	/**
	 * Tokens known to this plugin: title, sitename, sep, excerpt, category,
	 * date, page.
	 *
	 * Maps the external representation used by each source to the internal
	 * token name.
	 *
	 * @var array<string,string>
	 */
	private const YOAST_MAP = array(
		'%%title%%'    => 'title',
		'%%sitename%%' => 'sitename',
		'%%sep%%'      => 'sep',
		'%%excerpt%%'  => 'excerpt',
		'%%category%%' => 'category',
		'%%date%%'     => 'date',
		'%%page%%'     => 'page',
	);

	/**
	 * Rank Math token map. Also used verbatim as the internal UI syntax.
	 *
	 * @var array<string,string>
	 */
	private const RANK_MATH_MAP = array(
		'%title%'    => 'title',
		'%sitename%' => 'sitename',
		'%sep%'      => 'sep',
		'%excerpt%'  => 'excerpt',
		'%category%' => 'category',
		'%date%'     => 'date',
		'%page%'     => 'page',
	);

	/**
	 * AIOSEO token map.
	 *
	 * @var array<string,string>
	 */
	private const AIOSEO_MAP = array(
		'#post_title'     => 'title',
		'#site_title'     => 'sitename',
		'#separator_sa'   => 'sep',
		'#post_excerpt'   => 'excerpt',
		'#taxonomy_title' => 'category',
		'#post_date'      => 'date',
		'#page_number'    => 'page',
	);

	/**
	 * Resolves internal {token} placeholders against a value map. Tokens
	 * missing from the map are left untouched.
	 *
	 * @param string               $template Template containing {token} placeholders.
	 * @param array<string,string> $values   Token name => resolved value.
	 */
	public static function resolve( string $template, array $values ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( array $matches ) use ( $values ): string {
				return array_key_exists( $matches[1], $values ) ? $values[ $matches[1] ] : $matches[0];
			},
			$template
		);
	}

	/**
	 * Converts a Yoast SEO %%token%% template to the internal {token} syntax.
	 *
	 * @param string        $template  Template using %%token%% placeholders.
	 * @param callable|null $on_unknown Called with each unrecognised %%token%% found.
	 */
	public static function from_yoast( string $template, ?callable $on_unknown = null ): string {
		return self::convert( $template, self::YOAST_MAP, '/%%[a-z0-9_]+%%/i', $on_unknown );
	}

	/**
	 * Converts a Rank Math %token% template to the internal {token} syntax.
	 *
	 * @param string        $template   Template using %token% placeholders.
	 * @param callable|null $on_unknown Called with each unrecognised %token% found.
	 */
	public static function from_rank_math( string $template, ?callable $on_unknown = null ): string {
		return self::convert( $template, self::RANK_MATH_MAP, '/%[a-z0-9_]+%/i', $on_unknown );
	}

	/**
	 * Converts an AIOSEO #token template to the internal {token} syntax.
	 *
	 * @param string        $template   Template using #token placeholders.
	 * @param callable|null $on_unknown Called with each unrecognised #token found.
	 */
	public static function from_aioseo( string $template, ?callable $on_unknown = null ): string {
		return self::convert( $template, self::AIOSEO_MAP, '/#[a-z0-9_]+/i', $on_unknown );
	}

	/**
	 * Converts the internal {token} syntax to the %token% syntax shown in
	 * the UI.
	 *
	 * @param string $template Template using {token} placeholders.
	 */
	public static function to_ui( string $template ): string {
		return (string) preg_replace( '/\{([a-z_]+)\}/', '%$1%', $template );
	}

	/**
	 * Converts a %token% template edited in the UI back to the internal
	 * {token} syntax. Only known token names are converted; anything else
	 * is left as literal text.
	 *
	 * @param string $template Template using %token% placeholders.
	 */
	public static function from_ui( string $template ): string {
		$replacements = array();
		foreach ( self::RANK_MATH_MAP as $needle => $token ) {
			$replacements[ $needle ] = '{' . $token . '}';
		}

		return strtr( $template, $replacements );
	}

	/**
	 * Replaces every known placeholder in $map with its internal {token}
	 * form, then reports any remaining placeholder matching $pattern as
	 * unknown, leaving it unchanged in the output.
	 *
	 * @param string               $template   Source template.
	 * @param array<string,string> $map        Source placeholder => internal token name.
	 * @param string               $pattern    Regex matching this source's placeholder syntax.
	 * @param callable|null        $on_unknown Called with each unrecognised placeholder found.
	 */
	private static function convert( string $template, array $map, string $pattern, ?callable $on_unknown ): string {
		$replacements = array();
		foreach ( $map as $needle => $token ) {
			$replacements[ $needle ] = '{' . $token . '}';
		}

		$converted = strtr( $template, $replacements );

		if ( null !== $on_unknown ) {
			preg_replace_callback(
				$pattern,
				static function ( array $matches ) use ( $on_unknown ): string {
					$on_unknown( $matches[0] );
					return $matches[0];
				},
				$converted
			);
		}

		return $converted;
	}
}

<?php
/**
 * Renders a token template into a final string.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Templates;

/**
 * Turns a template into a string and cleans up duplicate separators left
 * behind by empty token values.
 */
final class Renderer {

	/**
	 * Resolves the template's tokens and cleans up the result.
	 *
	 * @param string               $template  Template containing {token} placeholders.
	 * @param array<string,string> $values    Token name => resolved value.
	 * @param string               $separator The configured separator character, used for cleanup.
	 */
	public static function render( string $template, array $values, string $separator = '-' ): string {
		$rendered = Tokens::resolve( $template, $values );

		return self::clean_separators( $rendered, $separator );
	}

	/**
	 * Collapses repeated separators (caused by adjacent empty tokens) into
	 * a single one, and trims leading/trailing separators and whitespace.
	 *
	 * @param string $text      Rendered text.
	 * @param string $separator Separator character to clean up around.
	 */
	private static function clean_separators( string $text, string $separator ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', $text ) );

		if ( '' === $separator ) {
			return $text;
		}

		$quoted = preg_quote( $separator, '/' );

		$text = (string) preg_replace( '/(?:\s*' . $quoted . '\s*){2,}/', ' ' . $separator . ' ', $text );
		$text = (string) preg_replace( '/^\s*(?:' . $quoted . '\s*)+/', '', $text );
		$text = (string) preg_replace( '/\s*(?:' . $quoted . '\s*)+$/', '', $text );

		return trim( $text );
	}
}

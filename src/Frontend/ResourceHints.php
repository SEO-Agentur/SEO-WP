<?php
/**
 * Manually-configured preconnect/dns-prefetch/preload resource hints.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Frontend;

/**
 * One hint per line in a simple "type url [as=... type=... crossorigin]"
 * textarea format (Settings > Performance) — the same "site owner
 * supplies it, this plugin just outputs it" model as CriticalCss's manual
 * critical CSS: genuinely knowing which third-party origins a specific
 * theme, page builder or embed needs (Google Fonts, a CDN, an analytics
 * endpoint) isn't something this plugin can discover on its own, but
 * adding or removing one is a one-line edit for whoever configured it.
 */
final class ResourceHints {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Rel values this plugin will output; anything else in the textarea
	 * is silently skipped rather than passed through unchecked.
	 */
	private const VALID_TYPES = array( 'preconnect', 'dns-prefetch', 'preload', 'prefetch', 'prerender' );

	/**
	 * Registers the early wp_head output — resource hints only help when
	 * they arrive before the browser would otherwise discover that origin
	 * on its own, so this runs ahead of everything else this plugin adds
	 * to <head>.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_hints' ), 1 );
	}

	/**
	 * Echoes one <link> per configured, valid hint.
	 */
	public function output_hints(): void {
		if ( is_admin() ) {
			return;
		}

		foreach ( $this->parse_hints() as $hint ) {
			echo $this->render_hint( $hint ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_hint() escapes every attribute itself.
		}
	}

	/**
	 * Parses the configured textarea into structured hints, silently
	 * skipping malformed or non-URL lines rather than erroring the whole
	 * page — this runs on every front-end request.
	 *
	 * @return array<int,array{type:string,url:string,as:string,type_attr:string,crossorigin:bool}>
	 */
	private function parse_hints(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		$raw      = is_array( $settings ) && isset( $settings['resource_hints'] ) ? (string) $settings['resource_hints'] : '';

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = is_array( $lines ) ? $lines : array();

		$hints = array();

		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				continue;
			}

			$parts = preg_split( '/\s+/', $line );
			$parts = is_array( $parts ) ? $parts : array();
			if ( count( $parts ) < 2 ) {
				continue;
			}

			$type = strtolower( (string) array_shift( $parts ) );
			$url  = (string) array_shift( $parts );

			if ( ! in_array( $type, self::VALID_TYPES, true ) || false === wp_http_validate_url( $url ) ) {
				continue;
			}

			$hint = array(
				'type'        => $type,
				'url'         => $url,
				'as'          => '',
				'type_attr'   => '',
				'crossorigin' => false,
			);

			foreach ( $parts as $part ) {
				if ( 'crossorigin' === $part ) {
					$hint['crossorigin'] = true;
				} elseif ( 0 === strpos( $part, 'as=' ) ) {
					$hint['as'] = substr( $part, 3 );
				} elseif ( 0 === strpos( $part, 'type=' ) ) {
					$hint['type_attr'] = substr( $part, 5 );
				}
			}

			$hints[] = $hint;
		}

		return $hints;
	}

	/**
	 * Builds one <link> tag for a parsed hint, escaping every attribute.
	 *
	 * @param array{type:string,url:string,as:string,type_attr:string,crossorigin:bool} $hint Parsed hint.
	 */
	private function render_hint( array $hint ): string {
		$html = sprintf( '<link rel="%1$s" href="%2$s"', esc_attr( $hint['type'] ), esc_url( $hint['url'] ) );

		if ( 'preload' === $hint['type'] && '' !== $hint['as'] ) {
			$html .= sprintf( ' as="%s"', esc_attr( $hint['as'] ) );
		}
		if ( 'preload' === $hint['type'] && '' !== $hint['type_attr'] ) {
			$html .= sprintf( ' type="%s"', esc_attr( $hint['type_attr'] ) );
		}
		if ( $hint['crossorigin'] ) {
			$html .= ' crossorigin';
		}

		return $html . ' />';
	}
}

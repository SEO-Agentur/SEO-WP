<?php
/**
 * Manually-supplied critical CSS and optional non-blocking stylesheet loading.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Frontend;

/**
 * Deliberately manual, not auto-extracted: genuinely detecting which CSS
 * rules apply to the above-the-fold viewport requires actually rendering
 * the page (real box positions, real layout) — something no PHP parsing
 * pass, and no amount of AI text generation either, can determine without
 * a headless browser. A site owner supplies their own critical CSS (from
 * a tool of their choice, or written by hand); this just inlines it and,
 * independently, can optionally defer every other stylesheet so it never
 * blocks first paint. The two settings are deliberately independent —
 * deferring styles with no critical CSS in place will show a flash of
 * unstyled content, but that tradeoff is the site owner's call, not
 * something this plugin should decide for them.
 */
final class CriticalCss {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the critical CSS output and the stylesheet-deferring filter.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output_critical_css' ), 1 );
		add_filter( 'style_loader_tag', array( $this, 'maybe_defer_stylesheet' ), 10, 4 );
	}

	/**
	 * Echoes the configured critical CSS inline, early in <head>, when
	 * one is configured.
	 */
	public function output_critical_css(): void {
		if ( is_admin() ) {
			return;
		}

		$css = $this->critical_css();
		if ( '' === $css ) {
			return;
		}

		// A literal "</style>" inside the stored CSS would otherwise close
		// this tag early; nothing else needs escaping since raw CSS is the
		// intended, admin-capability-gated content here (the same trust
		// model as WordPress core's own Customizer "Additional CSS"). HTML
		// end-tag matching is case-insensitive, so this has to match
		// "</STYLE"/"</Style"/etc. too, not just the lowercase literal.
		echo '<style id="seo-wp-critical-css">' . preg_replace( '#</style#i', '<\/style', $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw admin-supplied CSS, escaped for the one real risk (tag breakout) above; see docblock.
	}

	/**
	 * Rewrites an enqueued stylesheet's <link> tag to load non-blocking
	 * (media=print swapped to all on load), with a <noscript> fallback for
	 * when JavaScript is unavailable, when the defer setting is enabled.
	 *
	 * @param string $html   The stylesheet's <link> tag as WordPress built it.
	 * @param string $handle Registered handle (unused).
	 * @param string $href   Stylesheet URL (unused).
	 * @param string $media  The media attribute WordPress set.
	 */
	public function maybe_defer_stylesheet( string $html, string $handle, string $href, string $media ): string {
		if ( is_admin() || ! $this->defer_enabled() ) {
			return $html;
		}

		$quoted   = preg_quote( $media, '/' );
		$deferred = preg_replace(
			'/media=([\'"])' . $quoted . '\1/',
			'media=$1print$1 onload="this.media=\'all\'"',
			$html,
			1
		);

		// If the expected media attribute wasn't found (an unexpected tag
		// shape from some other plugin's filter), leave it untouched
		// rather than risk producing a broken tag.
		if ( ! is_string( $deferred ) || $deferred === $html ) {
			return $html;
		}

		return $deferred . "\n" . '<noscript>' . $html . '</noscript>';
	}

	/**
	 * The configured critical CSS, or '' if none is set.
	 */
	private function critical_css(): string {
		$settings = get_option( self::OPTION_KEY, array() );

		return isset( $settings['critical_css'] ) ? trim( (string) $settings['critical_css'] ) : '';
	}

	/**
	 * Whether non-critical stylesheets should load non-blocking.
	 */
	private function defer_enabled(): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! empty( $settings['defer_noncritical_css'] );
	}
}

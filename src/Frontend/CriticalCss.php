<?php
/**
 * Manually-supplied critical CSS and optional non-blocking stylesheet loading.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Frontend;

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
	 * Handle the critical CSS is registered and enqueued under — a
	 * source-less "carrier" style whose only purpose is to hold the
	 * inline CSS wp_add_inline_style() attaches to it.
	 */
	private const STYLE_HANDLE = 'seooptimierung-critical-css';

	/**
	 * Registers the critical CSS output and the stylesheet-deferring filter.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_critical_css' ) );
		add_filter( 'style_loader_tag', array( $this, 'maybe_defer_stylesheet' ), 10, 4 );
	}

	/**
	 * Registers a source-less style handle and attaches the configured
	 * critical CSS to it as an inline style, when one is configured. Goes
	 * through WordPress core's own style queue (wp_add_inline_style())
	 * rather than a raw echoed <style> tag, per the plugin directory's
	 * enqueue guidelines.
	 */
	public function maybe_enqueue_critical_css(): void {
		if ( is_admin() ) {
			return;
		}

		$css = $this->critical_css();
		if ( '' === $css ) {
			return;
		}

		// A literal "</style>" inside the stored CSS would otherwise close
		// the <style> tag WordPress core wraps this inline CSS in early —
		// core's own inline-style printing does no such escaping itself,
		// so this is still needed even via wp_add_inline_style(). Nothing
		// else needs escaping since raw CSS is the intended,
		// admin-capability-gated content here (the same trust model as
		// WordPress core's own Customizer "Additional CSS"). HTML end-tag
		// matching is case-insensitive, so this has to match
		// "</STYLE"/"</Style"/etc. too, not just the lowercase literal.
		$safe_css = (string) preg_replace( '#</style#i', '<\/style', $css );

		wp_register_style( self::STYLE_HANDLE, false, array(), \Seooptimierung\Toolkit\VERSION );
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_add_inline_style( self::STYLE_HANDLE, $safe_css );
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

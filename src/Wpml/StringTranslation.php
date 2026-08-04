<?php
/**
 * Per-language translation of title/description templates via WPML String Translation.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Wpml;

/**
 * Registers every *_template* setting (title/description templates for
 * any post type, taxonomy or the home page, plus the separator) with
 * WPML String Translation, and lets code resolve the current language's
 * translation of one back. A no-op when WPML String Translation is not
 * active.
 */
final class StringTranslation {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * String Translation context every registered string is grouped under.
	 */
	private const CONTEXT = 'SEO-WP';

	/**
	 * Registers strings whenever the settings are added or updated.
	 */
	public function register(): void {
		add_action( 'add_option_' . self::OPTION_KEY, array( $this, 'register_strings_on_add' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'register_strings_on_update' ), 10, 2 );
	}

	/**
	 * Registers strings the first time the settings option is created.
	 *
	 * @param string $option Option name (unused, matches the add_option hook signature).
	 * @param mixed  $value  The settings array being added.
	 */
	public function register_strings_on_add( string $option, $value ): void {
		$this->register_strings( $value );
	}

	/**
	 * Registers strings whenever the settings option is updated.
	 *
	 * @param mixed $old_value Previous settings (unused).
	 * @param mixed $value     New settings.
	 */
	public function register_strings_on_update( $old_value, $value ): void {
		$this->register_strings( $value );
	}

	/**
	 * Returns the current language's translation of a registered template,
	 * or the original value when WPML String Translation is not active or
	 * has no override for it.
	 *
	 * @param string $key   Settings key the template was registered under.
	 * @param string $value Original (default-language) template value.
	 */
	public static function translate( string $key, string $value ): string {
		if ( '' === $value || ! has_filter( 'wpml_translate_single_string' ) ) {
			return $value;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter, not one of ours.
		return (string) apply_filters( 'wpml_translate_single_string', $value, self::CONTEXT, $key );
	}

	/**
	 * Registers every non-empty *_template* setting with WPML String
	 * Translation.
	 *
	 * @param mixed $settings The plugin's settings array.
	 */
	private function register_strings( $settings ): void {
		if ( ! is_array( $settings ) || ! has_action( 'wpml_register_single_string' ) ) {
			return;
		}

		foreach ( $settings as $key => $template ) {
			if ( ! is_string( $key ) || ! is_string( $template ) || '' === $template ) {
				continue;
			}

			if ( false === strpos( $key, '_template' ) && 'separator' !== $key ) {
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own action, not one of ours.
			do_action( 'wpml_register_single_string', self::CONTEXT, $key, $template );
		}
	}
}

<?php
/**
 * Minimal, hand-rolled stand-ins for the handful of WordPress functions
 * that pure-logic unit tests need. Not a general WordPress test harness —
 * code that needs more than this is exercised as integration code, not
 * unit tested here.
 *
 * @package SeoWp
 */

declare(strict_types=1);

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Mirrors WordPress core's thin wrapper around parse_url().
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Optional PHP_URL_* component.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Minimal stand-in for WordPress core's esc_url_raw(): rejects
	 * disallowed protocols (e.g. javascript:), otherwise returns the URL
	 * trimmed but unchanged. Real core also entity-encodes ampersands for
	 * database storage; no test here depends on that.
	 *
	 * @param string $url URL to sanitise.
	 */
	function esc_url_raw( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Mirrors WordPress core's trailingslashit(): strips any existing
	 * trailing slash(es), then adds exactly one.
	 *
	 * @param string $string String to add a trailing slash to.
	 */
	function trailingslashit( string $string ): string {
		return rtrim( $string, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Thin mirror of WordPress core's wp_json_encode(): core adds UTF-8
	 * sanitisation and a few default flags on top of json_encode(); no
	 * test here depends on that, only on valid JSON output.
	 *
	 * @param mixed $data Data to encode.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- test-only stub of a WP core function.
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Records a callback for a hook so apply_filters() can call it back.
	 *
	 * @param string   $hook_name     Hook name.
	 * @param callable $callback      Callback to run.
	 * @param int      $priority      Unused by this stub.
	 * @param int      $accepted_args Unused by this stub.
	 */
	function add_filter( string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['seo_wp_test_filters'][ $hook_name ][] = $callback;

		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	/**
	 * Reports whether any callback is registered for a hook.
	 *
	 * @param string        $hook_name Hook name.
	 * @param callable|bool $callback  Unused by this stub.
	 * @return bool
	 */
	function has_filter( string $hook_name, $callback = false ): bool {
		return ! empty( $GLOBALS['seo_wp_test_filters'][ $hook_name ] );
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Mirrors WordPress core's maybe_unserialize(): unserialises a value
	 * that looks serialized, otherwise returns it unchanged.
	 *
	 * @param mixed $value Value to maybe unserialise.
	 * @return mixed
	 */
	function maybe_unserialize( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( 'N;' === $value ) {
			return null;
		}

		$result = @unserialize( $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- test-only stub of a WP core function.

		return false === $result ? $value : $result;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * In-memory stand-in for WordPress core's option storage.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Fallback when unset.
	 * @return mixed
	 */
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['seo_wp_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	/**
	 * In-memory stand-in for WordPress core's is_multisite().
	 */
	function is_multisite(): bool {
		return ! empty( $GLOBALS['seo_wp_test_multisite'] );
	}
}

if ( ! function_exists( 'get_site_option' ) ) {
	/**
	 * In-memory stand-in for WordPress core's network-wide option storage.
	 *
	 * @param string $option        Option name.
	 * @param mixed  $default_value Fallback when unset.
	 * @return mixed
	 */
	function get_site_option( string $option, $default_value = false ) {
		return $GLOBALS['seo_wp_test_site_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * In-memory stand-in for WordPress core's option storage.
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Value to store.
	 * @param mixed  $autoload Unused by this stub.
	 */
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['seo_wp_test_options'][ $option ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * In-memory stand-in for WordPress core's option storage.
	 *
	 * @param string $option Option name.
	 */
	function delete_option( string $option ): bool {
		unset( $GLOBALS['seo_wp_test_options'][ $option ] );

		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Runs every callback registered for a hook through add_filter().
	 *
	 * @param string $hook_name Hook name.
	 * @param mixed  ...$args   Value plus any additional filter arguments.
	 * @return mixed
	 */
	function apply_filters( string $hook_name, ...$args ) {
		$value = $args[0] ?? null;

		foreach ( $GLOBALS['seo_wp_test_filters'][ $hook_name ] ?? array() as $callback ) {
			$args[0] = $value;
			$value   = $callback( ...$args );
		}

		return $value;
	}
}

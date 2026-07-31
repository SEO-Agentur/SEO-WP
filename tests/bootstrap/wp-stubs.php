<?php
/**
 * Minimal, hand-rolled stand-ins for the handful of WordPress functions
 * that pure-logic unit tests need. Not a general WordPress test harness —
 * code that needs more than this is exercised as integration code, not
 * unit tested here.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

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
		$GLOBALS['ews_test_filters'][ $hook_name ][] = $callback;

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

		foreach ( $GLOBALS['ews_test_filters'][ $hook_name ] ?? array() as $callback ) {
			$args[0] = $value;
			$value   = $callback( ...$args );
		}

		return $value;
	}
}

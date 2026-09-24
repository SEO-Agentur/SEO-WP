<?php
/**
 * PSR-4 style autoloader for the plugin's own classes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit;

/**
 * Maps the Seooptimierung\Toolkit\ namespace prefix to the src/ directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this autoloader.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Base directory the prefix maps to.
	 */
	private const BASE_DIR = __DIR__ . '/';

	/**
	 * Register the autoloader with the SPL autoload stack.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	/**
	 * Resolve and require a class file for the given fully qualified class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 */
	private static function autoload( string $class_name ): void {
		if ( 0 !== strpos( $class_name, self::PREFIX ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );

		// A real PHP class declaration can't contain anything but this
		// character set, but the string handed to a registered autoloader
		// isn't required to have come from one (e.g. a crafted
		// class_exists()/instantiation call fed by some other, unrelated
		// code path) — reject anything else before it can influence a
		// filesystem path.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$path = self::BASE_DIR . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
}

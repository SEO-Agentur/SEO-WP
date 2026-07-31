<?php
/**
 * Plugin bootstrap and hook registration container.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo;

/**
 * Singleton container responsible for wiring up all plugin modules.
 */
final class Plugin {

	/**
	 * Shared instance.
	 *
	 * @var self|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Get the shared plugin instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor, use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Boot the plugin on plugins_loaded.
	 */
	public function init(): void {
		load_plugin_textdomain( 'easy-wordpress-seo', false, dirname( BASENAME ) . '/languages' );
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

<?php
/**
 * Plugin bootstrap and hook registration container.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp;

use SeoWp\Admin\BulkEditor;
use SeoWp\Admin\Diagnostics;
use SeoWp\Admin\ImportWizard;
use SeoWp\Admin\Metabox;
use SeoWp\Admin\Settings;
use SeoWp\Crawl\IndexNow;
use SeoWp\Crawl\LlmsTxt;
use SeoWp\Crawl\RobotsTxt;
use SeoWp\Crawl\SpecialArchives;
use SeoWp\Crawl\Verification;
use SeoWp\Frontend\Head;
use SeoWp\Sitemaps\Router;
use SeoWp\Wpml\StringTranslation;

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
		load_plugin_textdomain( 'seo-wp', false, dirname( BASENAME ) . '/languages' );

		( new Head() )->register();
		( new Router() )->register();
		( new StringTranslation() )->register();

		( new RobotsTxt() )->register();
		( new LlmsTxt() )->register();
		( new Verification() )->register();
		( new SpecialArchives() )->register();
		( new IndexNow() )->register();

		if ( is_admin() ) {
			( new Metabox() )->register();
			( new Settings() )->register();
			( new BulkEditor() )->register();
			( new ImportWizard() )->register();
			( new Diagnostics() )->register();
		}
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

<?php
/**
 * Plugin bootstrap and hook registration container.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp;

use SeoWp\Admin\AiSettings;
use SeoWp\Admin\BulkEditor;
use SeoWp\Admin\Diagnostics;
use SeoWp\Admin\DuplicateChecker;
use SeoWp\Admin\GoogleIndexingPage;
use SeoWp\Admin\ImportWizard;
use SeoWp\Admin\IndexNowPage;
use SeoWp\Admin\LinkAudit;
use SeoWp\Admin\Metabox;
use SeoWp\Admin\NotFoundMonitorPage;
use SeoWp\Admin\RedirectsPage;
use SeoWp\Admin\RoleManager;
use SeoWp\Admin\Settings;
use SeoWp\Crawl\GoogleIndexing;
use SeoWp\Crawl\ImageLinks;
use SeoWp\Crawl\IndexNow;
use SeoWp\Crawl\LlmsTxt;
use SeoWp\Crawl\NotFoundMonitor;
use SeoWp\Crawl\Redirects;
use SeoWp\Crawl\RobotsTxt;
use SeoWp\Crawl\SpecialArchives;
use SeoWp\Crawl\StagingProtection;
use SeoWp\Crawl\Verification;
use SeoWp\Frontend\Head;
use SeoWp\Performance\Optimizations;
use SeoWp\Performance\ServerHeaders;
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
		// Registered unconditionally (not only under is_admin()): the
		// user_has_cap filter it adds must be active for any capability
		// check anywhere, not just while rendering wp-admin screens.
		( new RoleManager() )->register();
		// Also unconditional: its WP-Cron hook must fire on wp-cron.php
		// requests, which are never is_admin().
		( new LinkAudit() )->register();

		( new RobotsTxt() )->register();
		( new LlmsTxt() )->register();
		( new Verification() )->register();
		( new SpecialArchives() )->register();
		( new IndexNow() )->register();
		( new ImageLinks() )->register();
		( new Redirects() )->register();
		( new NotFoundMonitor() )->register();
		( new GoogleIndexing() )->register();
		( new ServerHeaders() )->register();
		( new Optimizations() )->register();
		( new StagingProtection() )->register();

		if ( is_admin() ) {
			( new Metabox() )->register();
			( new Settings() )->register();
			( new BulkEditor() )->register();
			( new ImportWizard() )->register();
			( new Diagnostics() )->register();
			( new RedirectsPage() )->register();
			( new NotFoundMonitorPage() )->register();
			( new GoogleIndexingPage() )->register();
			( new IndexNowPage() )->register();
			( new DuplicateChecker() )->register();
			( new AiSettings() )->register();
		}
	}

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		// Read on nearly every front-end request across many classes;
		// guaranteeing it's autoloaded (a no-op if it already exists,
		// preserving whatever autoload state it already has) means every
		// one of those reads is an in-memory array lookup, never a query.
		add_option( 'seo_wp_settings', array(), '', 'yes' );

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'seo_wp_check_links_batch' );

		flush_rewrite_rules();
	}
}

<?php
/**
 * Plugin bootstrap and hook registration container.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit;

use Seooptimierung\Toolkit\Admin\AiSettings;
use Seooptimierung\Toolkit\Admin\AuthorFields;
use Seooptimierung\Toolkit\Admin\BulkEditor;
use Seooptimierung\Toolkit\Admin\ContentFreshness;
use Seooptimierung\Toolkit\Admin\Diagnostics;
use Seooptimierung\Toolkit\Admin\DuplicateChecker;
use Seooptimierung\Toolkit\Admin\GoogleIndexingPage;
use Seooptimierung\Toolkit\Admin\ImportWizard;
use Seooptimierung\Toolkit\Admin\IndexNowPage;
use Seooptimierung\Toolkit\Admin\LinkAudit;
use Seooptimierung\Toolkit\Admin\Metabox;
use Seooptimierung\Toolkit\Admin\NotFoundMonitorPage;
use Seooptimierung\Toolkit\Admin\RedirectsPage;
use Seooptimierung\Toolkit\Admin\RoleManager;
use Seooptimierung\Toolkit\Admin\Settings;
use Seooptimierung\Toolkit\Crawl\GoogleIndexing;
use Seooptimierung\Toolkit\Crawl\ImageLinks;
use Seooptimierung\Toolkit\Crawl\IndexNow;
use Seooptimierung\Toolkit\Crawl\LlmsTxt;
use Seooptimierung\Toolkit\Crawl\NotFoundMonitor;
use Seooptimierung\Toolkit\Crawl\Redirects;
use Seooptimierung\Toolkit\Crawl\RobotsTxt;
use Seooptimierung\Toolkit\Crawl\SpecialArchives;
use Seooptimierung\Toolkit\Crawl\StagingProtection;
use Seooptimierung\Toolkit\Crawl\Verification;
use Seooptimierung\Toolkit\Frontend\Head;
use Seooptimierung\Toolkit\Performance\Optimizations;
use Seooptimierung\Toolkit\Performance\ServerHeaders;
use Seooptimierung\Toolkit\Sitemaps\Router;
use Seooptimierung\Toolkit\Wpml\StringTranslation;

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

		// A plugin update replaces the files without running the activation
		// hook, so rewrite rules added in a new version (sitemap names,
		// llms.txt, ...) would stay unknown until someone flushed by hand.
		// Runs after every other init callback has registered its rules.
		add_action( 'init', array( self::class, 'maybe_flush_rewrite_rules' ), 99 );

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
			( new AuthorFields() )->register();
			( new Settings() )->register();
			( new BulkEditor() )->register();
			( new ImportWizard() )->register();
			( new Diagnostics() )->register();
			( new RedirectsPage() )->register();
			( new NotFoundMonitorPage() )->register();
			( new GoogleIndexingPage() )->register();
			( new IndexNowPage() )->register();
			( new DuplicateChecker() )->register();
			( new ContentFreshness() )->register();
			( new AiSettings() )->register();
		}
	}

	/**
	 * Flushes rewrite rules once per plugin version.
	 */
	public static function maybe_flush_rewrite_rules(): void {
		if ( VERSION === get_option( 'seo_wp_rewrite_version' ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( 'seo_wp_rewrite_version', VERSION, true );
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

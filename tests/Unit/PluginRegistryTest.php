<?php
/**
 * Tests for locating a competing SEO plugin's active file.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Import\PluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Import\PluginRegistry
 */
final class PluginRegistryTest extends TestCase {

	/**
	 * Resets the in-memory options store before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['seo_wp_test_options']      = array();
		$GLOBALS['seo_wp_test_site_options'] = array();
		$GLOBALS['seo_wp_test_multisite']    = false;
	}

	/**
	 * The free Yoast SEO slug is found when it's the active one.
	 */
	public function test_finds_the_active_yoast_free_slug(): void {
		$GLOBALS['seo_wp_test_options']['active_plugins'] = array( 'wordpress-seo/wpseo.php', 'akismet/akismet.php' );

		$this->assertSame( 'wordpress-seo/wpseo.php', PluginRegistry::active_plugin_file( 'yoast' ) );
	}

	/**
	 * A premium-edition slug is found too, not just the free one.
	 */
	public function test_finds_the_active_premium_slug(): void {
		$GLOBALS['seo_wp_test_options']['active_plugins'] = array( 'wordpress-seo-premium/wpseo.php' );

		$this->assertSame( 'wordpress-seo-premium/wpseo.php', PluginRegistry::active_plugin_file( 'yoast' ) );
	}

	/**
	 * When no known candidate is active, no file is guessed.
	 */
	public function test_returns_empty_string_when_nothing_active_matches(): void {
		$GLOBALS['seo_wp_test_options']['active_plugins'] = array( 'akismet/akismet.php' );

		$this->assertSame( '', PluginRegistry::active_plugin_file( 'yoast' ) );
	}

	/**
	 * An unknown source key never matches anything.
	 */
	public function test_returns_empty_string_for_an_unknown_source(): void {
		$this->assertSame( '', PluginRegistry::active_plugin_file( 'not-a-real-source' ) );
	}

	/**
	 * A network-activated plugin (multisite) is found via the site-wide
	 * active list, not just the per-site one.
	 */
	public function test_finds_a_network_activated_plugin_on_multisite(): void {
		$GLOBALS['seo_wp_test_multisite']                                              = true;
		$GLOBALS['seo_wp_test_site_options']['active_sitewide_plugins']                = array( 'seo-by-rank-math/rank-math.php' => true );

		$this->assertSame( 'seo-by-rank-math/rank-math.php', PluginRegistry::active_plugin_file( 'rank_math' ) );
	}

	/**
	 * Every known source has a human-readable label.
	 */
	public function test_every_source_has_a_label(): void {
		$this->assertSame( 'Yoast SEO', PluginRegistry::label( 'yoast' ) );
		$this->assertSame( 'Rank Math', PluginRegistry::label( 'rank_math' ) );
		$this->assertSame( 'All in One SEO', PluginRegistry::label( 'aioseo' ) );
	}
}

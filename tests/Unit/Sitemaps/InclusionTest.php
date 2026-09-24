<?php
/**
 * Tests for the sitemap inclusion toggles (per post type, per taxonomy,
 * and the video/hreflang extensions).
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit\Sitemaps;

use Seooptimierung\Toolkit\Sitemaps\Inclusion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Sitemaps\Inclusion
 */
final class InclusionTest extends TestCase {

	/**
	 * Resets the in-memory options store before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['seo_wp_test_options'] = array();
	}

	/**
	 * With no settings saved yet, every toggle defaults to included —
	 * installing this plugin (or adding a new toggle to it later) never
	 * silently removes something that was already in the sitemap.
	 */
	public function test_defaults_to_included_when_unset(): void {
		$this->assertTrue( Inclusion::post_type( 'post' ) );
		$this->assertTrue( Inclusion::taxonomy( 'category' ) );
		$this->assertTrue( Inclusion::video_sitemap() );
		$this->assertTrue( Inclusion::hreflang_in_sitemap() );
	}

	/**
	 * A post type with no stored setting is included by default, so
	 * WooCommerce's "product" appears in the sitemap without any manual
	 * configuration.
	 */
	public function test_post_type_defaults_to_included(): void {
		$this->assertTrue( Inclusion::post_type( 'product' ) );
	}

	/**
	 * A post type explicitly unchecked in the settings is excluded.
	 */
	public function test_post_type_excluded_when_setting_is_false(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'sitemap_include_product' => false );

		$this->assertFalse( Inclusion::post_type( 'product' ) );
	}

	/**
	 * A taxonomy with no stored setting is included by default, so
	 * WooCommerce's "product_cat" appears in the sitemap without any
	 * manual configuration.
	 */
	public function test_taxonomy_defaults_to_included(): void {
		$this->assertTrue( Inclusion::taxonomy( 'product_cat' ) );
	}

	/**
	 * A taxonomy explicitly unchecked in the settings is excluded.
	 */
	public function test_taxonomy_excluded_when_setting_is_false(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'sitemap_include_tax_product_cat' => false );

		$this->assertFalse( Inclusion::taxonomy( 'product_cat' ) );
	}

	/**
	 * An explicit false on the video/hreflang extension toggles is
	 * honoured.
	 */
	public function test_honours_explicit_false(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array(
			'sitemap_include_video'    => false,
			'sitemap_include_hreflang' => false,
		);

		$this->assertFalse( Inclusion::video_sitemap() );
		$this->assertFalse( Inclusion::hreflang_in_sitemap() );
	}

	/**
	 * An explicit true on the video/hreflang extension toggles is
	 * honoured (not just the default).
	 */
	public function test_honours_explicit_true(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array(
			'sitemap_include_video'    => true,
			'sitemap_include_hreflang' => true,
		);

		$this->assertTrue( Inclusion::video_sitemap() );
		$this->assertTrue( Inclusion::hreflang_in_sitemap() );
	}
}

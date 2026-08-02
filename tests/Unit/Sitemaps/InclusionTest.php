<?php
/**
 * Tests for per-post-type/taxonomy sitemap inclusion settings.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit\Sitemaps;

use SeoWp\Sitemaps\Inclusion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Sitemaps\Inclusion
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
}

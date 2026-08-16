<?php
/**
 * Tests for the sitemap inclusion toggles.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

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
	 * An explicit false is honoured.
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
	 * An explicit true is honoured (not just the default).
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

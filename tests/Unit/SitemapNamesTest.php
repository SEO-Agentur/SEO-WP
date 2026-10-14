<?php
/**
 * Tests for sitemap file naming rules.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Sitemaps\SitemapNames;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Sitemaps\SitemapNames
 */
final class SitemapNamesTest extends TestCase {

	/**
	 * Readable defaults for the common WordPress and WooCommerce types.
	 */
	public function test_default_names(): void {
		$this->assertSame( 'posts', SitemapNames::default_stem( SitemapNames::KIND_POST_TYPE, 'post' ) );
		$this->assertSame( 'page', SitemapNames::default_stem( SitemapNames::KIND_POST_TYPE, 'page' ) );
		$this->assertSame( 'products', SitemapNames::default_stem( SitemapNames::KIND_POST_TYPE, 'product' ) );
		$this->assertSame( 'category', SitemapNames::default_stem( SitemapNames::KIND_TAXONOMY, 'category' ) );
		$this->assertSame( 'tags', SitemapNames::default_stem( SitemapNames::KIND_TAXONOMY, 'post_tag' ) );
		$this->assertSame( 'product-category', SitemapNames::default_stem( SitemapNames::KIND_TAXONOMY, 'product_cat' ) );
		$this->assertSame( 'video', SitemapNames::default_stem( SitemapNames::KIND_VIDEO ) );
		$this->assertSame( 'images', SitemapNames::default_stem( SitemapNames::KIND_IMAGES ) );
		$this->assertSame( 'news', SitemapNames::default_stem( SitemapNames::KIND_NEWS ) );
	}

	/**
	 * Any other post type or taxonomy gets its slug, underscores turned
	 * into hyphens.
	 */
	public function test_default_name_for_custom_types(): void {
		$this->assertSame( 'my-case-study', SitemapNames::default_stem( SitemapNames::KIND_POST_TYPE, 'my_case_study' ) );
		$this->assertSame( 'topic', SitemapNames::default_stem( SitemapNames::KIND_TAXONOMY, 'topic' ) );
	}

	/**
	 * Custom names are reduced to a safe, URL-friendly form.
	 */
	public function test_sanitize_stem(): void {
		$this->assertSame( 'my-pages', SitemapNames::sanitize_stem( ' My Pages ' ) );
		$this->assertSame( 'pages', SitemapNames::sanitize_stem( 'sitemap_pages.xml' ) );
		$this->assertSame( 'a-b', SitemapNames::sanitize_stem( 'a/../b' ) );
		$this->assertSame( 'with_underscore', SitemapNames::sanitize_stem( 'with_underscore' ) );
		$this->assertSame( '', SitemapNames::sanitize_stem( '!!!' ) );
	}

	/**
	 * A trailing "_number" would read as a page number, so it is removed.
	 */
	public function test_sanitize_stem_rejects_page_number_suffix(): void {
		$this->assertSame( 'pages', SitemapNames::sanitize_stem( 'pages_2' ) );
		$this->assertSame( 'pages-2', SitemapNames::sanitize_stem( 'pages-2' ) );
	}

	/**
	 * Option keys keep post types and taxonomies apart.
	 */
	public function test_option_keys(): void {
		$this->assertSame( 'sitemap_name_pt_page', SitemapNames::option_key( SitemapNames::KIND_POST_TYPE, 'page' ) );
		$this->assertSame( 'sitemap_name_tax_category', SitemapNames::option_key( SitemapNames::KIND_TAXONOMY, 'category' ) );
		$this->assertSame( 'sitemap_name_video', SitemapNames::option_key( SitemapNames::KIND_VIDEO ) );
	}
}

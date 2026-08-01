<?php
/**
 * Tests for extracting <img> URLs from post content.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Sitemaps\ImageSitemap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Sitemaps\ImageSitemap
 */
final class ImageSitemapTest extends TestCase {

	/**
	 * A single <img> tag's src is extracted.
	 */
	public function test_extracts_a_single_image(): void {
		$html = '<p>Text</p><img src="https://example.com/a.jpg" alt="">';

		$this->assertSame( array( 'https://example.com/a.jpg' ), ImageSitemap::extract_image_urls( $html ) );
	}

	/**
	 * Multiple <img> tags in any attribute order are all extracted.
	 */
	public function test_extracts_multiple_images_regardless_of_attribute_order(): void {
		$html = '<img alt="one" src="https://example.com/a.jpg" /><img src=\'https://example.com/b.jpg\' class="wp-image-1" />';

		$this->assertSame(
			array( 'https://example.com/a.jpg', 'https://example.com/b.jpg' ),
			ImageSitemap::extract_image_urls( $html )
		);
	}

	/**
	 * Content without any <img> tags yields an empty list.
	 */
	public function test_returns_empty_array_when_no_images(): void {
		$this->assertSame( array(), ImageSitemap::extract_image_urls( '<p>No images here.</p>' ) );
	}

	/**
	 * Non-image tags with a src-like attribute name are not matched.
	 */
	public function test_does_not_match_non_img_tags(): void {
		$html = '<iframe src="https://example.com/video"></iframe>';

		$this->assertSame( array(), ImageSitemap::extract_image_urls( $html ) );
	}
}

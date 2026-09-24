<?php
/**
 * Tests for stripping attachment-page links from inserted images.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Crawl\ImageLinks;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Crawl\ImageLinks
 */
final class ImageLinksTest extends TestCase {

	/**
	 * A link wrapping its own image, pointing at that image's own
	 * attachment page, is stripped down to the bare <img>.
	 */
	public function test_strips_a_link_to_its_own_attachment_page(): void {
		$content = '<a href="https://example.com/?attachment_id=42"><img class="wp-image-42" src="a.jpg" /></a>';

		$result = ImageLinks::strip_links(
			$content,
			static fn( int $id ) => 42 === $id ? 'https://example.com/?attachment_id=42' : false
		);

		$this->assertSame( '<img class="wp-image-42" src="a.jpg" />', $result );
	}

	/**
	 * A deliberate link to an unrelated page around a wp-image is left
	 * alone, since it doesn't match that image's own attachment link.
	 */
	public function test_leaves_link_to_a_different_page_untouched(): void {
		$content = '<a href="https://example.com/somewhere-else"><img class="wp-image-42" src="a.jpg" /></a>';

		$result = ImageLinks::strip_links(
			$content,
			static fn( int $id ) => 42 === $id ? 'https://example.com/?attachment_id=42' : false
		);

		$this->assertSame( $content, $result );
	}

	/**
	 * An image without a wp-image-{ID} class (not WordPress-managed) is
	 * left alone, even if wrapped in a link.
	 */
	public function test_leaves_images_without_wp_image_class_untouched(): void {
		$content = '<a href="https://example.com/x"><img src="a.jpg" /></a>';

		$result = ImageLinks::strip_links( $content, static fn( int $id ) => 'https://example.com/x' );

		$this->assertSame( $content, $result );
	}

	/**
	 * A plain text link with no image inside is left alone.
	 */
	public function test_leaves_non_image_links_untouched(): void {
		$content = '<a href="https://example.com/x">Click here</a>';

		$result = ImageLinks::strip_links( $content, static fn( int $id ) => 'https://example.com/x' );

		$this->assertSame( $content, $result );
	}

	/**
	 * When the resolver can't resolve the attachment ID (e.g. the
	 * attachment no longer exists), the link is left untouched rather
	 * than assumed to match.
	 */
	public function test_leaves_link_untouched_when_resolver_returns_falsy(): void {
		$content = '<a href="https://example.com/?attachment_id=42"><img class="wp-image-42" src="a.jpg" /></a>';

		$result = ImageLinks::strip_links( $content, static fn( int $id ) => false );

		$this->assertSame( $content, $result );
	}
}

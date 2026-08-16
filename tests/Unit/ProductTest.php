<?php
/**
 * Tests for the Product schema node's pure logic.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Schema\Nodes\Product;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Schema\Nodes\Product
 */
final class ProductTest extends TestCase {

	/**
	 * A non-empty brand name becomes a Brand sub-node.
	 */
	public function test_brand_node_builds_from_a_name(): void {
		$this->assertSame(
			array(
				'@type' => 'Brand',
				'name'  => 'Acme',
			),
			Product::brand_node( 'Acme' )
		);
	}

	/**
	 * Leading/trailing whitespace is trimmed before checking for content.
	 */
	public function test_brand_node_trims_whitespace(): void {
		$this->assertSame(
			array(
				'@type' => 'Brand',
				'name'  => 'Acme',
			),
			Product::brand_node( '  Acme  ' )
		);
	}

	/**
	 * An empty (or whitespace-only) name yields no brand node at all.
	 */
	public function test_brand_node_returns_null_for_empty_name(): void {
		$this->assertNull( Product::brand_node( '' ) );
		$this->assertNull( Product::brand_node( '   ' ) );
	}

	/**
	 * A real, well-formed Y-m-d date passes through unchanged.
	 */
	public function test_normalise_price_valid_until_accepts_a_real_date(): void {
		$this->assertSame( '2026-12-31', Product::normalise_price_valid_until( '2026-12-31' ) );
	}

	/**
	 * A calendar-invalid date (e.g. Feb 30) is rejected.
	 */
	public function test_normalise_price_valid_until_rejects_an_impossible_date(): void {
		$this->assertSame( '', Product::normalise_price_valid_until( '2026-02-30' ) );
	}

	/**
	 * Malformed input (wrong shape, empty, free text) is rejected.
	 *
	 * @dataProvider malformed_date_provider
	 */
	public function test_normalise_price_valid_until_rejects_malformed_input( string $input ): void {
		$this->assertSame( '', Product::normalise_price_valid_until( $input ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function malformed_date_provider(): array {
		return array(
			'empty'          => array( '' ),
			'us format'      => array( '12/31/2026' ),
			'no zero pad'    => array( '2026-1-1' ),
			'free text'      => array( 'next month' ),
			'datetime shape' => array( '2026-12-31 12:00:00' ),
		);
	}

	/**
	 * The primary image comes first, followed by gallery images, with
	 * duplicates and blanks dropped.
	 */
	public function test_merge_image_urls_orders_primary_first_and_dedupes(): void {
		$this->assertSame(
			array( 'https://example.com/main.jpg', 'https://example.com/gallery-1.jpg' ),
			Product::merge_image_urls(
				'https://example.com/main.jpg',
				array( 'https://example.com/main.jpg', 'https://example.com/gallery-1.jpg' )
			)
		);
	}

	/**
	 * An empty primary is simply skipped rather than producing a blank
	 * first entry.
	 */
	public function test_merge_image_urls_skips_empty_primary(): void {
		$this->assertSame(
			array( 'https://example.com/gallery-1.jpg' ),
			Product::merge_image_urls( '', array( 'https://example.com/gallery-1.jpg' ) )
		);
	}

	/**
	 * No primary and no gallery images yields an empty list.
	 */
	public function test_merge_image_urls_returns_empty_array_when_nothing_available(): void {
		$this->assertSame( array(), Product::merge_image_urls( '', array() ) );
	}
}

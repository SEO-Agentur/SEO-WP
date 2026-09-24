<?php
/**
 * Tests for parsing Yoast's comma-separated advanced robots field.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Import\YoastImporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Import\YoastImporter
 */
final class YoastImporterTest extends TestCase {

	/**
	 * Known directives are extracted in this plugin's canonical order.
	 */
	public function test_extracts_known_directives(): void {
		$result = YoastImporter::advanced_directives( 'noarchive,noimageindex' );

		$this->assertSame( array( 'noarchive', 'noimageindex' ), $result );
	}

	/**
	 * Directives this plugin doesn't understand are dropped.
	 */
	public function test_drops_unknown_directives(): void {
		$result = YoastImporter::advanced_directives( 'noarchive,noodp,nosnippet' );

		$this->assertSame( array( 'noarchive', 'nosnippet' ), $result );
	}

	/**
	 * An empty value yields no directives.
	 */
	public function test_empty_value_yields_no_directives(): void {
		$this->assertSame( array(), YoastImporter::advanced_directives( '' ) );
	}

	/**
	 * Yoast's "none" sentinel yields no directives.
	 */
	public function test_none_sentinel_yields_no_directives(): void {
		$this->assertSame( array(), YoastImporter::advanced_directives( 'none' ) );
	}

	/**
	 * Surrounding whitespace around each entry is trimmed.
	 */
	public function test_trims_whitespace_around_entries(): void {
		$result = YoastImporter::advanced_directives( ' noarchive , nosnippet ' );

		$this->assertSame( array( 'noarchive', 'nosnippet' ), $result );
	}
}

<?php
/**
 * Tests for extracting external resource hosts and suggesting hints.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Performance\ResourceHintScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Performance\ResourceHintScanner
 */
final class ResourceHintScannerTest extends TestCase {

	/**
	 * External script/link hosts are counted; the site's own host is
	 * excluded.
	 */
	public function test_counts_external_hosts_and_excludes_own_host(): void {
		$html = '<script src="https://example.com/wp-includes/js/jquery.js"></script>'
			. '<script src="https://fonts.googleapis.com/css?family=Roboto"></script>'
			. '<link href="https://fonts.gstatic.com/s/roboto/font.woff2" />'
			. '<link href="https://fonts.googleapis.com/other.css" />';

		$counts = ResourceHintScanner::external_host_counts( $html, 'example.com' );

		$this->assertSame(
			array(
				'fonts.googleapis.com' => 2,
				'fonts.gstatic.com'    => 1,
			),
			$counts
		);
	}

	/**
	 * Duplicate identical URLs count once, not per occurrence.
	 */
	public function test_deduplicates_identical_urls(): void {
		$html = '<script src="https://cdn.example.net/a.js"></script>'
			. '<script src="https://cdn.example.net/a.js"></script>';

		$counts = ResourceHintScanner::external_host_counts( $html, 'example.com' );

		$this->assertSame( array( 'cdn.example.net' => 1 ), $counts );
	}

	/**
	 * A host with more than one resource is suggested as preconnect; a
	 * host with exactly one is suggested as the cheaper dns-prefetch.
	 */
	public function test_suggests_preconnect_for_multiple_resources_and_dns_prefetch_for_one(): void {
		$lines = ResourceHintScanner::suggest_hints(
			array(
				'fonts.googleapis.com' => 2,
				'cdn.example.net'      => 1,
			)
		);

		$this->assertSame(
			array(
				'preconnect https://fonts.googleapis.com',
				'dns-prefetch https://cdn.example.net',
			),
			$lines
		);
	}

	/**
	 * No external hosts yields no suggestions.
	 */
	public function test_returns_empty_array_when_nothing_external(): void {
		$html = '<script src="https://example.com/app.js"></script>';

		$counts = ResourceHintScanner::external_host_counts( $html, 'example.com' );

		$this->assertSame( array(), $counts );
		$this->assertSame( array(), ResourceHintScanner::suggest_hints( $counts ) );
	}
}

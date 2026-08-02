<?php
/**
 * Tests for 404 path normalisation.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Crawl\NotFoundMonitor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Crawl\NotFoundMonitor
 */
final class NotFoundMonitorTest extends TestCase {

	/**
	 * A bare path is returned with a single leading slash.
	 */
	public function test_normalizes_a_bare_path(): void {
		$this->assertSame( '/old-page', NotFoundMonitor::normalize_path( '/old-page' ) );
	}

	/**
	 * A trailing slash is stripped, except for the root path.
	 */
	public function test_strips_trailing_slash_except_root(): void {
		$this->assertSame( '/old-page', NotFoundMonitor::normalize_path( '/old-page/' ) );
		$this->assertSame( '/', NotFoundMonitor::normalize_path( '/' ) );
		$this->assertSame( '/', NotFoundMonitor::normalize_path( '' ) );
	}

	/**
	 * A full URL is reduced to just its path, dropping scheme/host/query.
	 */
	public function test_reduces_a_full_url_to_its_path(): void {
		$this->assertSame(
			'/old-page',
			NotFoundMonitor::normalize_path( 'https://example.com/old-page/?utm_source=x' )
		);
	}

	/**
	 * A path without a leading slash still gets exactly one.
	 */
	public function test_adds_a_missing_leading_slash(): void {
		$this->assertSame( '/old-page', NotFoundMonitor::normalize_path( 'old-page' ) );
	}
}

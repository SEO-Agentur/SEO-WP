<?php
/**
 * Tests for extracting checkable link targets from post content.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Admin\LinkAudit;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Admin\LinkAudit
 */
final class LinkAuditTest extends TestCase {

	/**
	 * A single external link is extracted.
	 */
	public function test_extracts_a_single_link(): void {
		$html = '<p>Text</p><a href="https://example.com/page">Link</a>';

		$this->assertSame( array( 'https://example.com/page' ), LinkAudit::extract_links( $html ) );
	}

	/**
	 * Multiple distinct links are all extracted, regardless of attribute
	 * order.
	 */
	public function test_extracts_multiple_links_regardless_of_attribute_order(): void {
		$html = '<a class="x" href="https://example.com/a">A</a><a href=\'https://example.com/b\' class="y">B</a>';

		$this->assertSame(
			array( 'https://example.com/a', 'https://example.com/b' ),
			LinkAudit::extract_links( $html )
		);
	}

	/**
	 * Duplicate hrefs are only returned once.
	 */
	public function test_deduplicates_repeated_links(): void {
		$html = '<a href="https://example.com/a">A</a><a href="https://example.com/a">A again</a>';

		$this->assertSame( array( 'https://example.com/a' ), LinkAudit::extract_links( $html ) );
	}

	/**
	 * Same-page anchors (#section) are skipped.
	 */
	public function test_skips_same_page_anchors(): void {
		$html = '<a href="#section">Jump</a><a href="https://example.com/page">Real link</a>';

		$this->assertSame( array( 'https://example.com/page' ), LinkAudit::extract_links( $html ) );
	}

	/**
	 * Non-http(s) schemes (mailto, tel, javascript) are skipped.
	 */
	public function test_skips_non_http_schemes(): void {
		$html = '<a href="mailto:test@example.com">Mail</a>'
			. '<a href="tel:+123456">Call</a>'
			. '<a href="javascript:void(0)">JS</a>'
			. '<a href="https://example.com/page">Real link</a>';

		$this->assertSame( array( 'https://example.com/page' ), LinkAudit::extract_links( $html ) );
	}

	/**
	 * A relative path (no scheme/host) is kept as-is — classified as
	 * internal by the caller, not filtered out here.
	 */
	public function test_keeps_relative_paths(): void {
		$html = '<a href="/some-page/">Relative</a>';

		$this->assertSame( array( '/some-page/' ), LinkAudit::extract_links( $html ) );
	}

	/**
	 * Content without any <a> tags yields an empty list.
	 */
	public function test_returns_empty_array_when_no_links(): void {
		$this->assertSame( array(), LinkAudit::extract_links( '<p>No links here.</p>' ) );
	}

	/**
	 * A fully-qualified Location header is returned as-is.
	 */
	public function test_resolve_redirect_url_keeps_absolute_url(): void {
		$this->assertSame(
			'https://other.example/target',
			LinkAudit::resolve_redirect_url( 'https://example.com/page', 'https://other.example/target' )
		);
	}

	/**
	 * An absolute-path Location header is resolved against the base URL's
	 * scheme and host.
	 */
	public function test_resolve_redirect_url_resolves_absolute_path(): void {
		$this->assertSame(
			'https://example.com/new-page/',
			LinkAudit::resolve_redirect_url( 'https://example.com/old-page/', '/new-page/' )
		);
	}

	/**
	 * A path-relative Location header is resolved against the base URL's
	 * directory.
	 */
	public function test_resolve_redirect_url_resolves_relative_path(): void {
		$this->assertSame(
			'https://example.com/blog/new-page',
			LinkAudit::resolve_redirect_url( 'https://example.com/blog/old-page', 'new-page' )
		);
	}

	/**
	 * A protocol-relative Location header ("//host/path") inherits the
	 * base URL's scheme.
	 */
	public function test_resolve_redirect_url_resolves_protocol_relative_url(): void {
		$this->assertSame(
			'https://cdn.example.com/asset',
			LinkAudit::resolve_redirect_url( 'https://example.com/page', '//cdn.example.com/asset' )
		);
	}

	/**
	 * An empty Location header falls back to the original base URL rather
	 * than resolving to something nonsensical (check_url() already treats
	 * a redirect response with no Location header as broken before ever
	 * calling this method, so this is a defensive guard, not a reachable
	 * production path — but it keeps the pure function safe to call on
	 * its own).
	 */
	public function test_resolve_redirect_url_falls_back_to_base_when_empty(): void {
		$this->assertSame(
			'https://example.com/page',
			LinkAudit::resolve_redirect_url( 'https://example.com/page', '' )
		);
	}
}

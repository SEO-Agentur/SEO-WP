<?php
/**
 * Tests for rewriting internal links after an address change.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Crawl\SlugRedirects;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Crawl\SlugRedirects
 */
final class SlugRedirectsTest extends TestCase {

	private const HOME = 'https://example.com/';

	/**
	 * Absolute (both schemes), protocol-relative and root-relative links to
	 * the old address are rewritten; the link text and other links are not.
	 */
	public function test_rewrites_every_link_form(): void {
		$pairs = array( 'https://example.com/old-page/' => 'https://example.com/new-page/' );

		$html = '<a href="https://example.com/old-page/">Old page</a> '
			. '<a href="http://example.com/old-page/#top">http</a> '
			. '<a href=\'//example.com/old-page/\'>proto-rel</a> '
			. '<a href="/old-page/">relative</a> '
			. '<a href="https://example.com/other/">other</a>';

		$out = SlugRedirects::replace_links( $html, $pairs, self::HOME );

		$this->assertStringContainsString( 'href="https://example.com/new-page/"', $out );
		$this->assertStringContainsString( 'href="http://example.com/new-page/#top"', $out );
		$this->assertStringContainsString( "href='//example.com/new-page/'", $out );
		$this->assertStringContainsString( 'href="/new-page/"', $out );
		$this->assertStringContainsString( 'href="https://example.com/other/"', $out );
		$this->assertStringNotContainsString( 'old-page', str_replace( '>Old page<', '', $out ) );
	}

	/**
	 * Only whole addresses match: a longer slug that starts the same, a
	 * child page and another site's identical path are left alone.
	 */
	public function test_only_whole_addresses_match(): void {
		$pairs = array( 'https://example.com/shoes/' => 'https://example.com/footwear/' );

		$html = '<a href="/shoes-sale/">a</a><a href="/shoes/red/">b</a><a href="https://other.com/shoes/">c</a><a href="/shoes/">d</a>';
		$out  = SlugRedirects::replace_links( $html, $pairs, self::HOME );

		$this->assertStringContainsString( '"/shoes-sale/"', $out );
		$this->assertStringContainsString( '"/shoes/red/"', $out );
		$this->assertStringContainsString( '"https://other.com/shoes/"', $out );
		$this->assertStringContainsString( '"/footwear/"', $out );
	}

	/**
	 * A moved parent page also moves its children: each child pair is
	 * applied, and a link to the parent does not swallow a link to a child.
	 */
	public function test_parent_and_child_pairs(): void {
		$pairs = array(
			'https://example.com/services/' => 'https://example.com/leistungen/',
			'https://example.com/services/seo/' => 'https://example.com/leistungen/seo/',
		);

		$out = SlugRedirects::replace_links( '<a href="/services/">p</a><a href="/services/seo/">c</a>', $pairs, self::HOME );

		$this->assertSame( '<a href="/leistungen/">p</a><a href="/leistungen/seo/">c</a>', $out );
	}

	/**
	 * A menu custom link is a bare URL, with no surrounding markup.
	 */
	public function test_bare_url(): void {
		$pairs = array( 'https://example.com/a/' => 'https://example.com/b/' );

		$this->assertSame( 'https://example.com/b/', SlugRedirects::replace_links( 'https://example.com/a/', $pairs, self::HOME ) );
		$this->assertSame( 'https://example.com/a-b/', SlugRedirects::replace_links( 'https://example.com/a-b/', $pairs, self::HOME ) );
	}

	/**
	 * Characters special to a regex replacement ($ and \) in the new address
	 * come through literally.
	 */
	public function test_new_address_is_inserted_literally(): void {
		$pairs = array( 'https://example.com/a/' => 'https://example.com/price-$1/' );

		$this->assertSame( '<a href="https://example.com/price-$1/">x</a>', SlugRedirects::replace_links( '<a href="https://example.com/a/">x</a>', $pairs, self::HOME ) );
	}

	/**
	 * Old addresses of pages below a moved parent.
	 */
	public function test_map_descendant(): void {
		$this->assertSame(
			'https://example.com/services/seo/audit/',
			SlugRedirects::map_descendant( 'https://example.com/leistungen/seo/audit/', 'https://example.com/leistungen/', 'https://example.com/services/' )
		);
		$this->assertSame( '', SlugRedirects::map_descendant( 'https://example.com/unrelated/', 'https://example.com/leistungen/', 'https://example.com/services/' ) );
	}
}

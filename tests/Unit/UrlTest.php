<?php
/**
 * Tests for shared URL normalisation and the WPML permalink passthrough.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Support\Url;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Support\Url
 */
final class UrlTest extends TestCase {

	/**
	 * Resets recorded test filter callbacks between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['seo_wp_test_filters'] = array();
	}

	/**
	 * A trailing slash is added when requested.
	 */
	public function test_with_trailing_slash_adds_slash(): void {
		$result = Url::with_trailing_slash( 'https://example.com/foo', true );

		$this->assertSame( 'https://example.com/foo/', $result );
	}

	/**
	 * A trailing slash is removed when not requested.
	 */
	public function test_with_trailing_slash_removes_slash(): void {
		$result = Url::with_trailing_slash( 'https://example.com/foo/', false );

		$this->assertSame( 'https://example.com/foo', $result );
	}

	/**
	 * The root path stays a single slash either way.
	 */
	public function test_with_trailing_slash_keeps_root_path(): void {
		$this->assertSame( 'https://example.com/', Url::with_trailing_slash( 'https://example.com/', true ) );
		$this->assertSame( 'https://example.com/', Url::with_trailing_slash( 'https://example.com/', false ) );
	}

	/**
	 * A query string is preserved when normalising the trailing slash.
	 */
	public function test_with_trailing_slash_preserves_query(): void {
		$result = Url::with_trailing_slash( 'https://example.com/foo?x=1', true );

		$this->assertSame( 'https://example.com/foo/?x=1', $result );
	}

	/**
	 * URLs that only differ by scheme or trailing slash are equal.
	 */
	public function test_equals_ignores_scheme_and_trailing_slash(): void {
		$this->assertTrue( Url::equals( 'https://example.com/foo', 'http://example.com/foo/' ) );
	}

	/**
	 * URLs with a genuinely different path are not equal.
	 */
	public function test_equals_detects_different_path(): void {
		$this->assertFalse( Url::equals( 'https://example.com/foo', 'https://example.com/bar' ) );
	}

	/**
	 * Without any listener, wpml_permalink() is a no-op passthrough.
	 */
	public function test_wpml_permalink_is_noop_without_a_listener(): void {
		$result = Url::wpml_permalink( 'https://example.com/de/beitrag/' );

		$this->assertSame( 'https://example.com/de/beitrag/', $result );
	}

	/**
	 * When something has hooked wpml_permalink, its filtered value wins.
	 */
	public function test_wpml_permalink_uses_registered_filter(): void {
		add_filter(
			'wpml_permalink',
			static function ( string $url, ?string $lang_code ): string {
				return $url . '?lang=' . ( $lang_code ?? 'current' );
			},
			10,
			2
		);

		$result = Url::wpml_permalink( 'https://example.com/post/', 'de' );

		$this->assertSame( 'https://example.com/post/?lang=de', $result );
	}
}

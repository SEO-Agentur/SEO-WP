<?php
/**
 * Tests for shared URL normalisation and the WPML permalink passthrough.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Support\Url;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Support\Url
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

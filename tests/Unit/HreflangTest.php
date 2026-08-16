<?php
/**
 * Tests for the slim-hreflang compatibility contract and WPML alternates mapping.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Wpml\Hreflang;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Wpml\Hreflang
 */
final class HreflangTest extends TestCase {

	/**
	 * Resets recorded test filter callbacks between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['seo_wp_test_filters'] = array();
	}

	/**
	 * With no listeners, the computed alternates pass through unchanged.
	 */
	public function test_apply_contract_passes_through_without_listeners(): void {
		$alternates = array(
			array(
				'hreflang' => 'en',
				'href'     => 'https://example.com/',
			),
		);

		$result = Hreflang::apply_contract( $alternates, array( 'url' => 'https://example.com/', 'object_id' => 1, 'lang' => 'en' ) );

		$this->assertSame( $alternates, $result );
	}

	/**
	 * A listener on seo_wp/hreflang/alternates can override the computed
	 * list entirely.
	 */
	public function test_alternates_filter_overrides_computed_output(): void {
		add_filter(
			'seo_wp/hreflang/alternates',
			static function ( array $alternates, array $context ): array {
				return array(
					array(
						'hreflang' => 'de-DE',
						'href'     => 'https://example.com/de/',
					),
				);
			},
			10,
			2
		);

		$computed = array(
			array(
				'hreflang' => 'en',
				'href'     => 'https://example.com/',
			),
		);

		$result = Hreflang::apply_contract( $computed, array( 'url' => 'https://example.com/', 'object_id' => 1, 'lang' => 'en' ) );

		$this->assertSame(
			array(
				array(
					'hreflang' => 'de-DE',
					'href'     => 'https://example.com/de/',
				),
			),
			$result
		);
	}

	/**
	 * managed_externally=true suppresses this plugin's own computation
	 * before the alternates filter runs, per the compatibility contract.
	 */
	public function test_managed_externally_suppresses_own_computation(): void {
		add_filter(
			'seo_wp/hreflang/managed_externally',
			static function (): bool {
				return true;
			}
		);

		$seen_by_alternates_filter = null;

		add_filter(
			'seo_wp/hreflang/alternates',
			static function ( array $alternates ) use ( &$seen_by_alternates_filter ): array {
				$seen_by_alternates_filter = $alternates;
				return $alternates;
			}
		);

		$computed = array(
			array(
				'hreflang' => 'en',
				'href'     => 'https://example.com/',
			),
		);

		Hreflang::apply_contract( $computed, array( 'url' => 'https://example.com/', 'object_id' => 1, 'lang' => 'en' ) );

		$this->assertSame( array(), $seen_by_alternates_filter );
	}

	/**
	 * WPML's active-languages result is turned into alternates, skipping
	 * entries without both a language code and a URL.
	 */
	public function test_alternates_from_wpml_languages_maps_valid_entries(): void {
		$languages = array(
			'en' => array(
				'language_code' => 'en',
				'url'           => 'https://example.com/',
			),
			'de' => array(
				'language_code' => 'de',
				'url'           => 'https://example.com/de/',
			),
			'fr' => array(
				'language_code' => 'fr',
				'url'           => '',
			),
		);

		$result = Hreflang::alternates_from_wpml_languages( $languages, '' );

		$this->assertSame(
			array(
				array(
					'hreflang' => 'en',
					'href'     => 'https://example.com/',
				),
				array(
					'hreflang' => 'de',
					'href'     => 'https://example.com/de/',
				),
			),
			$result
		);
	}

	/**
	 * When a language matches the configured x-default language, an extra
	 * x-default entry is emitted alongside its own hreflang entry.
	 */
	public function test_alternates_from_wpml_languages_adds_x_default(): void {
		$languages = array(
			'en' => array(
				'language_code' => 'en',
				'url'           => 'https://example.com/',
			),
			'de' => array(
				'language_code' => 'de',
				'url'           => 'https://example.com/de/',
			),
		);

		$result = Hreflang::alternates_from_wpml_languages( $languages, 'en' );

		$this->assertSame(
			array(
				array(
					'hreflang' => 'en',
					'href'     => 'https://example.com/',
				),
				array(
					'hreflang' => 'x-default',
					'href'     => 'https://example.com/',
				),
				array(
					'hreflang' => 'de',
					'href'     => 'https://example.com/de/',
				),
			),
			$result
		);
	}

	/**
	 * An empty x-default setting emits no x-default entry at all.
	 */
	public function test_alternates_from_wpml_languages_skips_x_default_when_off(): void {
		$languages = array(
			'en' => array(
				'language_code' => 'en',
				'url'           => 'https://example.com/',
			),
		);

		$result = Hreflang::alternates_from_wpml_languages( $languages, '' );

		$this->assertSame(
			array(
				array(
					'hreflang' => 'en',
					'href'     => 'https://example.com/',
				),
			),
			$result
		);
	}
}

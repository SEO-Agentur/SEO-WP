<?php
/**
 * Tests for the slim-hreflang compatibility contract and WPML alternates mapping.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Wpml\Hreflang;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Wpml\Hreflang
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
	 * A listener on seooptimierung/hreflang/alternates can override the computed
	 * list entirely.
	 */
	public function test_alternates_filter_overrides_computed_output(): void {
		add_filter(
			'seooptimierung/hreflang/alternates',
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
			'seooptimierung/hreflang/managed_externally',
			static function (): bool {
				return true;
			}
		);

		$seen_by_alternates_filter = null;

		add_filter(
			'seooptimierung/hreflang/alternates',
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

	/**
	 * A Polylang translations map (language slug => object ID) is turned
	 * into alternates via the injected URL resolver, skipping any ID the
	 * resolver can't resolve.
	 */
	public function test_alternates_from_polylang_translations_maps_valid_entries(): void {
		$translations = array(
			'en' => 1,
			'de' => 2,
			'fr' => 3,
		);

		$urls = array(
			1 => 'https://example.com/',
			2 => 'https://example.com/de/',
			3 => null,
		);

		$result = Hreflang::alternates_from_polylang_translations(
			$translations,
			static fn ( int $id ): ?string => $urls[ $id ] ?? null,
			''
		);

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
	 * A Polylang language matching the configured x-default language gets
	 * an extra x-default entry, same as the WPML path.
	 */
	public function test_alternates_from_polylang_translations_adds_x_default(): void {
		$translations = array(
			'en' => 1,
			'de' => 2,
		);

		$result = Hreflang::alternates_from_polylang_translations(
			$translations,
			static fn ( int $id ): string => 1 === $id ? 'https://example.com/' : 'https://example.com/de/',
			'en'
		);

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
	 * An entry with no language slug or a non-positive object ID is
	 * skipped rather than producing a malformed alternate.
	 */
	public function test_alternates_from_polylang_translations_skips_invalid_entries(): void {
		$translations = array(
			''   => 1,
			'de' => 0,
			'fr' => 3,
		);

		$result = Hreflang::alternates_from_polylang_translations(
			$translations,
			static fn ( int $id ): string => 'https://example.com/fr/',
			''
		);

		$this->assertSame(
			array(
				array(
					'hreflang' => 'fr',
					'href'     => 'https://example.com/fr/',
				),
			),
			$result
		);
	}
}

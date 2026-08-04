<?php
/**
 * Tests for DeepL free/pro tier endpoint selection.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Support\DeepLClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Support\DeepLClient
 */
final class DeepLClientTest extends TestCase {

	/**
	 * A key ending in ":fx" is a free-tier key.
	 */
	public function test_recognizes_free_tier_key(): void {
		$this->assertTrue( DeepLClient::is_free_tier_key( 'abc123:fx' ) );
	}

	/**
	 * A key without that suffix is a pro-tier key.
	 */
	public function test_recognizes_pro_tier_key(): void {
		$this->assertFalse( DeepLClient::is_free_tier_key( 'abc123' ) );
	}

	/**
	 * Free-tier keys resolve to the free-tier endpoint.
	 */
	public function test_endpoint_for_free_tier_key(): void {
		$this->assertSame( 'https://api-free.deepl.com/v2/translate', DeepLClient::endpoint_for_key( 'abc123:fx' ) );
	}

	/**
	 * Pro-tier keys resolve to the pro-tier endpoint.
	 */
	public function test_endpoint_for_pro_tier_key(): void {
		$this->assertSame( 'https://api.deepl.com/v2/translate', DeepLClient::endpoint_for_key( 'abc123' ) );
	}

	/**
	 * A plain code with no required regional variant is just uppercased.
	 */
	public function test_normalize_target_lang_uppercases_plain_codes(): void {
		$this->assertSame( 'DE', DeepLClient::normalize_target_lang( 'de' ) );
		$this->assertSame( 'FR', DeepLClient::normalize_target_lang( 'fr' ) );
	}

	/**
	 * English and Portuguese are mapped to their required regional variant.
	 */
	public function test_normalize_target_lang_maps_regional_variants(): void {
		$this->assertSame( 'EN-GB', DeepLClient::normalize_target_lang( 'en' ) );
		$this->assertSame( 'PT-PT', DeepLClient::normalize_target_lang( 'PT' ) );
	}

	/**
	 * An already-regional code is left as-is (just uppercased).
	 */
	public function test_normalize_target_lang_leaves_already_regional_codes(): void {
		$this->assertSame( 'EN-US', DeepLClient::normalize_target_lang( 'en-us' ) );
	}
}

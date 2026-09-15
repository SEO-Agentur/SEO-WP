<?php
/**
 * Tests for dedicated, non-autoloaded secret storage.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Support\Secrets;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Support\Secrets
 */
final class SecretsTest extends TestCase {

	/**
	 * Resets the in-memory options store before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['seo_wp_test_options'] = array();
	}

	/**
	 * An unset secret reads back as an empty string.
	 */
	public function test_get_returns_empty_string_when_unset(): void {
		$this->assertSame( '', Secrets::get( 'ai_anthropic_key' ) );
		$this->assertFalse( Secrets::has( 'ai_anthropic_key' ) );
	}

	/**
	 * A secret set via set() is read back via get(), and has() reports it
	 * as present.
	 */
	public function test_set_then_get_round_trips(): void {
		Secrets::set( 'ai_anthropic_key', 'sk-ant-test123' );

		$this->assertSame( 'sk-ant-test123', Secrets::get( 'ai_anthropic_key' ) );
		$this->assertTrue( Secrets::has( 'ai_anthropic_key' ) );
	}

	/**
	 * set() stores into its own dedicated option, not the general
	 * settings option, and always as non-autoloaded.
	 */
	public function test_set_writes_to_its_own_non_autoloaded_option(): void {
		Secrets::set( 'ai_openai_key', 'sk-test' );

		$this->assertArrayNotHasKey( 'seo_wp_settings', $GLOBALS['seo_wp_test_options'] );
		$this->assertSame(
			array( 'ai_openai_key' => 'sk-test' ),
			$GLOBALS['seo_wp_test_options']['seo_wp_secrets']
		);
	}

	/**
	 * Multiple keys can be stored independently without overwriting one
	 * another.
	 */
	public function test_set_preserves_other_keys(): void {
		Secrets::set( 'ai_anthropic_key', 'key-a' );
		Secrets::set( 'ai_openai_key', 'key-b' );

		$this->assertSame( 'key-a', Secrets::get( 'ai_anthropic_key' ) );
		$this->assertSame( 'key-b', Secrets::get( 'ai_openai_key' ) );
	}

	/**
	 * A value only present in the legacy seo_wp_settings location (as an
	 * older version of the plugin would have stored it) is still read
	 * back — the upgrade path for an existing installation.
	 */
	public function test_get_falls_back_to_legacy_settings_location(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array(
			'ai_deepl_key' => 'legacy-value',
		);

		$this->assertSame( 'legacy-value', Secrets::get( 'ai_deepl_key' ) );
		$this->assertTrue( Secrets::has( 'ai_deepl_key' ) );
	}

	/**
	 * Once a value exists in the dedicated option, it takes precedence
	 * over a stale legacy copy rather than the two being merged.
	 */
	public function test_dedicated_option_takes_precedence_over_legacy(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array(
			'ai_deepl_key' => 'legacy-value',
		);
		Secrets::set( 'ai_deepl_key', 'current-value' );

		$this->assertSame( 'current-value', Secrets::get( 'ai_deepl_key' ) );
	}

	/**
	 * get()/set() never write back to the legacy location — the
	 * migration is one-way and only the dedicated option changes.
	 */
	public function test_get_never_writes_back_to_legacy_location(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array(
			'ai_deepl_key' => 'legacy-value',
		);

		Secrets::get( 'ai_deepl_key' );

		$this->assertSame(
			array( 'ai_deepl_key' => 'legacy-value' ),
			$GLOBALS['seo_wp_test_options']['seo_wp_settings']
		);
		$this->assertArrayNotHasKey( 'seo_wp_secrets', $GLOBALS['seo_wp_test_options'] );
	}
}

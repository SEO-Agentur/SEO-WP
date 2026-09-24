<?php
/**
 * Tests for the Yoast/Rank Math coexistence mode.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Support\CoexistenceMode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Support\CoexistenceMode
 */
final class CoexistenceModeTest extends TestCase {

	/**
	 * Resets the in-memory options store before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['seo_wp_test_options'] = array();
	}

	/**
	 * Off by default: no setting saved at all.
	 */
	public function test_inactive_by_default(): void {
		$this->assertFalse( CoexistenceMode::active() );
		$this->assertSame( '', CoexistenceMode::source() );
		$this->assertNull( CoexistenceMode::meta_key( 'title' ) );
	}

	/**
	 * An unrecognised source value is treated as off.
	 */
	public function test_unrecognised_source_is_treated_as_off(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'coexistence_source' => 'aioseo' );

		$this->assertFalse( CoexistenceMode::active() );
		$this->assertSame( '', CoexistenceMode::source() );
	}

	/**
	 * Yoast mode reports its own meta keys.
	 */
	public function test_yoast_mode_reports_yoast_meta_keys(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'coexistence_source' => 'yoast' );

		$this->assertTrue( CoexistenceMode::active() );
		$this->assertSame( 'yoast', CoexistenceMode::source() );
		$this->assertSame( '_yoast_wpseo_title', CoexistenceMode::meta_key( 'title' ) );
		$this->assertSame( '_yoast_wpseo_metadesc', CoexistenceMode::meta_key( 'description' ) );
		$this->assertSame( '_yoast_wpseo_canonical', CoexistenceMode::meta_key( 'canonical' ) );
	}

	/**
	 * Rank Math mode reports its own meta keys.
	 */
	public function test_rank_math_mode_reports_rank_math_meta_keys(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'coexistence_source' => 'rank_math' );

		$this->assertTrue( CoexistenceMode::active() );
		$this->assertSame( 'rank_math', CoexistenceMode::source() );
		$this->assertSame( 'rank_math_title', CoexistenceMode::meta_key( 'title' ) );
		$this->assertSame( 'rank_math_description', CoexistenceMode::meta_key( 'description' ) );
		$this->assertSame( 'rank_math_canonical_url', CoexistenceMode::meta_key( 'canonical' ) );
	}

	/**
	 * A field with no counterpart returns null even when active.
	 */
	public function test_unknown_field_returns_null_when_active(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_settings'] = array( 'coexistence_source' => 'yoast' );

		$this->assertNull( CoexistenceMode::meta_key( 'og_image' ) );
	}
}

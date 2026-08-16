<?php
/**
 * Tests for the Content Freshness threshold/cutoff logic.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Admin\ContentFreshness;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Admin\ContentFreshness
 */
final class ContentFreshnessTest extends TestCase {

	/**
	 * A fixed "now" for deterministic assertions: 2026-06-15 12:00:00 UTC.
	 */
	private const NOW = 1781524800;

	/**
	 * 12 months before the fixed "now" resolves to the same day/time a
	 * year earlier.
	 */
	public function test_cutoff_timestamp_twelve_months(): void {
		$this->assertSame(
			strtotime( '2025-06-15 12:00:00 UTC' ),
			ContentFreshness::cutoff_timestamp( 12, self::NOW )
		);
	}

	/**
	 * 3 months before the fixed "now".
	 */
	public function test_cutoff_timestamp_three_months(): void {
		$this->assertSame(
			strtotime( '2026-03-15 12:00:00 UTC' ),
			ContentFreshness::cutoff_timestamp( 3, self::NOW )
		);
	}

	/**
	 * An unsupported threshold (not one of the offered choices) falls
	 * back to the 12-month default before computing the cutoff.
	 */
	public function test_cutoff_timestamp_normalises_unsupported_threshold(): void {
		$this->assertSame(
			ContentFreshness::cutoff_timestamp( 12, self::NOW ),
			ContentFreshness::cutoff_timestamp( 7, self::NOW )
		);
	}

	/**
	 * A negative reference timestamp is clamped to 0 rather than
	 * producing a negative cutoff.
	 */
	public function test_cutoff_timestamp_clamps_negative_reference(): void {
		$this->assertSame(
			ContentFreshness::cutoff_timestamp( 3, 0 ),
			ContentFreshness::cutoff_timestamp( 3, -1000 )
		);
	}

	/**
	 * Every offered choice is returned unchanged.
	 *
	 * @dataProvider offered_choices_provider
	 */
	public function test_normalise_threshold_keeps_offered_choices( int $choice ): void {
		$this->assertSame( $choice, ContentFreshness::normalise_threshold( $choice ) );
	}

	/**
	 * Data provider of the offered threshold choices.
	 *
	 * @return array<string,int[]>
	 */
	public static function offered_choices_provider(): array {
		return array(
			'3 months'  => array( 3 ),
			'6 months'  => array( 6 ),
			'12 months' => array( 12 ),
			'24 months' => array( 24 ),
			'36 months' => array( 36 ),
		);
	}

	/**
	 * An unsupported value falls back to the 12-month default.
	 */
	public function test_normalise_threshold_falls_back_to_default(): void {
		$this->assertSame( 12, ContentFreshness::normalise_threshold( 999 ) );
		$this->assertSame( 12, ContentFreshness::normalise_threshold( 0 ) );
		$this->assertSame( 12, ContentFreshness::normalise_threshold( -5 ) );
	}
}

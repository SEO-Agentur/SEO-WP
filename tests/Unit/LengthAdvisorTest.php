<?php
/**
 * Tests for the title/description length classification.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Support\LengthAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Support\LengthAdvisor
 */
final class LengthAdvisorTest extends TestCase {

	/**
	 * A zero-length value is always 'empty', even for an unknown field.
	 */
	public function test_zero_length_is_empty(): void {
		$this->assertSame( 'empty', LengthAdvisor::status( 'title', 0 ) );
		$this->assertSame( 'empty', LengthAdvisor::status( 'description', 0 ) );
	}

	/**
	 * Title band: below 30 is short, 30-60 is good, above 60 is long.
	 *
	 * @dataProvider title_lengths_provider
	 */
	public function test_title_band( int $length, string $expected ): void {
		$this->assertSame( $expected, LengthAdvisor::status( 'title', $length ) );
	}

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function title_lengths_provider(): array {
		return array(
			'well short'  => array( 5, 'short' ),
			'just short'  => array( 29, 'short' ),
			'lower bound' => array( 30, 'good' ),
			'middle'      => array( 45, 'good' ),
			'upper bound' => array( 60, 'good' ),
			'just long'   => array( 61, 'long' ),
			'well long'   => array( 90, 'long' ),
		);
	}

	/**
	 * Description band: below 70 is short, 70-155 is good, above 155 is long.
	 *
	 * @dataProvider description_lengths_provider
	 */
	public function test_description_band( int $length, string $expected ): void {
		$this->assertSame( $expected, LengthAdvisor::status( 'description', $length ) );
	}

	/**
	 * @return array<string,array{0:int,1:string}>
	 */
	public static function description_lengths_provider(): array {
		return array(
			'well short'  => array( 10, 'short' ),
			'just short'  => array( 69, 'short' ),
			'lower bound' => array( 70, 'good' ),
			'middle'      => array( 100, 'good' ),
			'upper bound' => array( 155, 'good' ),
			'just long'   => array( 156, 'long' ),
			'well long'   => array( 300, 'long' ),
		);
	}

	/**
	 * A field with no defined band always reads as 'good' once past zero.
	 */
	public function test_unknown_field_is_always_good_when_nonzero(): void {
		$this->assertSame( 'good', LengthAdvisor::status( 'og_title', 5 ) );
		$this->assertSame( 'good', LengthAdvisor::status( 'og_title', 5000 ) );
	}

	/**
	 * bands() exposes exactly the two fields status() special-cases.
	 */
	public function test_bands_exposes_title_and_description(): void {
		$bands = LengthAdvisor::bands();

		$this->assertSame( array( 'min' => 30, 'max' => 60 ), $bands['title'] );
		$this->assertSame( array( 'min' => 70, 'max' => 155 ), $bands['description'] );
	}
}

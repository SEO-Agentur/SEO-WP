<?php
/**
 * Tests for parsing the opening-hours settings field.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Schema\OpeningHours;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Schema\OpeningHours
 */
final class OpeningHoursTest extends TestCase {

	/**
	 * A single weekday-range line parses into one spec with every named
	 * day.
	 */
	public function test_parses_a_single_line(): void {
		$result = OpeningHours::parse( 'Mo,Tu,We,Th,Fr 09:00-18:00' );

		$this->assertSame(
			array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array(
						'https://schema.org/Monday',
						'https://schema.org/Tuesday',
						'https://schema.org/Wednesday',
						'https://schema.org/Thursday',
						'https://schema.org/Friday',
					),
					'opens'     => '09:00',
					'closes'    => '18:00',
				),
			),
			$result
		);
	}

	/**
	 * Multiple lines each produce their own spec.
	 */
	public function test_parses_multiple_lines(): void {
		$result = OpeningHours::parse( "Mo,Tu,We,Th,Fr 09:00-18:00\nSa 10:00-14:00" );

		$this->assertCount( 2, $result );
		$this->assertSame( array( 'https://schema.org/Saturday' ), $result[1]['dayOfWeek'] );
		$this->assertSame( '10:00', $result[1]['opens'] );
		$this->assertSame( '14:00', $result[1]['closes'] );
	}

	/**
	 * Blank lines are skipped.
	 */
	public function test_skips_blank_lines(): void {
		$result = OpeningHours::parse( "Mo 09:00-18:00\n\n\nTu 09:00-18:00" );

		$this->assertCount( 2, $result );
	}

	/**
	 * A line that doesn't match the expected format is skipped rather
	 * than breaking the whole field.
	 */
	public function test_skips_malformed_lines(): void {
		$result = OpeningHours::parse( "Monday to Friday, 9am-6pm\nMo 09:00-18:00" );

		$this->assertCount( 1, $result );
		$this->assertSame( '09:00', $result[0]['opens'] );
	}

	/**
	 * A line naming no recognised day is skipped.
	 */
	public function test_skips_lines_with_unrecognised_days(): void {
		$this->assertSame( array(), OpeningHours::parse( 'Xx 09:00-18:00' ) );
	}

	/**
	 * Empty input yields an empty array.
	 */
	public function test_empty_input_yields_empty_array(): void {
		$this->assertSame( array(), OpeningHours::parse( '' ) );
	}
}

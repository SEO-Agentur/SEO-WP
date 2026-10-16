<?php
/**
 * Tests for the LocalBusiness type list behind the Business type dropdown.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Schema\LocalBusinessTypes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Schema\LocalBusinessTypes
 */
final class LocalBusinessTypesTest extends TestCase {

	/**
	 * Every offered type is accepted by the same validator the settings
	 * save uses, so choosing one can never be silently reset.
	 */
	public function test_every_type_is_a_valid_schema_type(): void {
		foreach ( array_keys( LocalBusinessTypes::all() ) as $type ) {
			$this->assertTrue( GraphBuilder::is_valid_schema_type( $type ), $type );
		}
	}

	/**
	 * A type is listed in exactly one group, and the generic fallback
	 * and the most common types are present.
	 */
	public function test_types_are_unique_and_common_ones_present(): void {
		$count = 0;
		foreach ( LocalBusinessTypes::groups() as $types ) {
			$count += count( $types );
		}

		$this->assertSame( $count, count( LocalBusinessTypes::all() ) );

		foreach ( array( 'LocalBusiness', 'Restaurant', 'Store', 'ProfessionalService', 'Dentist', 'LegalService', 'RealEstateAgent', 'Attorney', 'AccountingService', 'HairSalon' ) as $type ) {
			$this->assertArrayHasKey( $type, LocalBusinessTypes::all() );
		}
	}
}

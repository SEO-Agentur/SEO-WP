<?php
/**
 * Tests for the import batch's "don't overwrite existing data" filter.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Import\Batch;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Import\Batch
 */
final class BatchTest extends TestCase {

	/**
	 * An empty string is empty.
	 */
	public function test_is_empty_value_treats_empty_string_as_empty(): void {
		$this->assertTrue( Batch::is_empty_value( '' ) );
	}

	/**
	 * The string '0' is a meaningful, non-empty value (e.g. an explicit
	 * "force indexed" robots override), not an empty one.
	 */
	public function test_is_empty_value_treats_string_zero_as_not_empty(): void {
		$this->assertFalse( Batch::is_empty_value( '0' ) );
	}

	/**
	 * An empty array is empty; a non-empty array is not.
	 */
	public function test_is_empty_value_handles_arrays(): void {
		$this->assertTrue( Batch::is_empty_value( array() ) );
		$this->assertFalse( Batch::is_empty_value( array( 'noarchive' ) ) );
	}

	/**
	 * A field whose target already has a non-empty value is dropped from
	 * the mapped set.
	 */
	public function test_filter_to_empty_fields_drops_already_set_fields(): void {
		$mapped = array(
			'title'       => 'New title',
			'description' => 'New description',
		);

		$existing = array(
			'title' => 'Existing title',
		);

		$result = Batch::filter_to_empty_fields(
			$mapped,
			static fn ( string $key ) => $existing[ $key ] ?? ''
		);

		$this->assertSame( array( 'description' => 'New description' ), $result );
	}

	/**
	 * When every field already has a value, nothing is left to import.
	 */
	public function test_filter_to_empty_fields_can_drop_everything(): void {
		$mapped = array( 'title' => 'New title' );

		$result = Batch::filter_to_empty_fields( $mapped, static fn ( string $key ) => 'Already set' );

		$this->assertSame( array(), $result );
	}

	/**
	 * When nothing exists yet, every field is kept.
	 */
	public function test_filter_to_empty_fields_keeps_everything_when_nothing_exists(): void {
		$mapped = array(
			'title'       => 'New title',
			'description' => 'New description',
		);

		$result = Batch::filter_to_empty_fields( $mapped, static fn ( string $key ) => '' );

		$this->assertSame( $mapped, $result );
	}
}

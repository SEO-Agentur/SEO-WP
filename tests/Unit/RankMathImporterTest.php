<?php
/**
 * Tests for unserialising and reading Rank Math's rank_math_robots field.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Import\RankMathImporter;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Import\RankMathImporter
 */
final class RankMathImporterTest extends TestCase {

	/**
	 * An already-unserialised array (what get_post_meta() with
	 * $single=true normally returns) passes through unchanged.
	 */
	public function test_returns_array_input_unchanged(): void {
		$result = RankMathImporter::unserialize_robots( array( 'noindex', 'nofollow' ) );

		$this->assertSame( array( 'noindex', 'nofollow' ), $result );
	}

	/**
	 * A raw serialized string (the defensive fallback case) is
	 * unserialised into an array.
	 */
	public function test_unserialises_a_serialized_string(): void {
		$serialized = serialize( array( 'noindex', 'noarchive' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- building fixture input for the stub, not plugin code.

		$result = RankMathImporter::unserialize_robots( $serialized );

		$this->assertSame( array( 'noindex', 'noarchive' ), $result );
	}

	/**
	 * An empty value yields an empty list.
	 */
	public function test_empty_value_yields_empty_array(): void {
		$this->assertSame( array(), RankMathImporter::unserialize_robots( '' ) );
	}

	/**
	 * A value that isn't serialized and isn't an array yields an empty
	 * list rather than a partial/garbage result.
	 */
	public function test_non_serialized_string_yields_empty_array(): void {
		$this->assertSame( array(), RankMathImporter::unserialize_robots( 'not serialized' ) );
	}
}

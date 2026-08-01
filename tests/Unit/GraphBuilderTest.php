<?php
/**
 * Tests for JSON-LD @graph node deduplication.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Schema\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Schema\GraphBuilder
 */
final class GraphBuilderTest extends TestCase {

	/**
	 * Nodes with distinct @id values are all kept, in order.
	 */
	public function test_keeps_all_nodes_with_distinct_ids(): void {
		$nodes = array(
			array(
				'@id' => 'https://example.com/#website',
				'@type' => 'WebSite',
			),
			array(
				'@id' => 'https://example.com/#identity',
				'@type' => 'Organization',
			),
		);

		$result = GraphBuilder::deduplicate( $nodes );

		$this->assertSame( $nodes, $result );
	}

	/**
	 * When two nodes share an @id, only the first is kept.
	 */
	public function test_drops_later_nodes_with_a_duplicate_id(): void {
		$first  = array(
			'@id'  => 'https://example.com/post/#webpage',
			'name' => 'First',
		);
		$second = array(
			'@id'  => 'https://example.com/post/#webpage',
			'name' => 'Second',
		);

		$result = GraphBuilder::deduplicate( array( $first, $second ) );

		$this->assertSame( array( $first ), $result );
	}

	/**
	 * Nodes without an @id are never treated as duplicates of one another.
	 */
	public function test_keeps_nodes_without_an_id(): void {
		$a = array( 'name' => 'A' );
		$b = array( 'name' => 'B' );

		$result = GraphBuilder::deduplicate( array( $a, $b ) );

		$this->assertSame( array( $a, $b ), $result );
	}

	/**
	 * An empty node list stays empty.
	 */
	public function test_empty_input_returns_empty_output(): void {
		$this->assertSame( array(), GraphBuilder::deduplicate( array() ) );
	}
}

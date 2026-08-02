<?php
/**
 * Tests for redirect rule storage and matching.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Crawl\Redirects;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Crawl\Redirects
 */
final class RedirectsTest extends TestCase {

	/**
	 * Resets the in-memory options store before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['seo_wp_test_options'] = array();
	}

	/**
	 * Saving a new rule assigns it an ID and normalises its source path.
	 */
	public function test_save_creates_a_rule_with_a_normalized_source(): void {
		$id = Redirects::save(
			0,
			array(
				'source' => 'old-page/',
				'target' => 'https://example.com/new-page/',
				'type'   => 301,
				'active' => true,
			)
		);

		$rules = Redirects::all();

		$this->assertSame( 1, $id );
		$this->assertSame( '/old-page', $rules[1]['source'] );
		$this->assertSame( 'https://example.com/new-page/', $rules[1]['target'] );
		$this->assertSame( 301, $rules[1]['type'] );
		$this->assertTrue( $rules[1]['active'] );
		$this->assertSame( 0, $rules[1]['hits'] );
	}

	/**
	 * Saving with an unknown type falls back to 301.
	 */
	public function test_save_falls_back_to_301_for_an_unknown_type(): void {
		$id = Redirects::save( 0, array( 'source' => '/x', 'type' => 999 ) );

		$this->assertSame( 301, Redirects::all()[ $id ]['type'] );
	}

	/**
	 * Updating an existing rule preserves its accumulated hit count.
	 */
	public function test_updating_a_rule_preserves_its_hit_count(): void {
		$GLOBALS['seo_wp_test_options']['seo_wp_redirects'] = array(
			5 => array(
				'source' => '/old',
				'target' => '/new',
				'type'   => 301,
				'active' => true,
				'hits'   => 42,
			),
		);

		Redirects::save( 5, array( 'source' => '/old', 'target' => '/newer', 'type' => 301, 'active' => true ) );

		$this->assertSame( 42, Redirects::all()[5]['hits'] );
		$this->assertSame( '/newer', Redirects::all()[5]['target'] );
	}

	/**
	 * An active rule is found for its exact normalised source path.
	 */
	public function test_match_finds_an_active_rule_by_source(): void {
		Redirects::save( 0, array( 'source' => '/old-page', 'target' => '/new-page', 'type' => 301, 'active' => true ) );

		$match = Redirects::match( '/old-page' );

		$this->assertNotNull( $match );
		$this->assertSame( '/new-page', $match['target'] );
	}

	/**
	 * An inactive rule is never matched.
	 */
	public function test_match_ignores_inactive_rules(): void {
		Redirects::save( 0, array( 'source' => '/old-page', 'target' => '/new-page', 'type' => 301, 'active' => false ) );

		$this->assertNull( Redirects::match( '/old-page' ) );
	}

	/**
	 * A path with no matching rule returns null.
	 */
	public function test_match_returns_null_when_nothing_matches(): void {
		$this->assertNull( Redirects::match( '/does-not-exist' ) );
	}

	/**
	 * Deleting a rule removes it.
	 */
	public function test_delete_removes_the_rule(): void {
		$id = Redirects::save( 0, array( 'source' => '/old', 'target' => '/new', 'type' => 301, 'active' => true ) );

		Redirects::delete( $id );

		$this->assertArrayNotHasKey( $id, Redirects::all() );
	}
}

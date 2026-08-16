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

	/**
	 * A regex rule's source is trimmed but not path-normalised, so
	 * intentional pattern syntax (anchors, quantifiers) survives.
	 */
	public function test_save_does_not_path_normalize_a_regex_source(): void {
		$id = Redirects::save(
			0,
			array(
				'source'   => '  ^/blog/(.+)$  ',
				'target'   => '/news/$1',
				'type'     => 301,
				'active'   => true,
				'is_regex' => true,
			)
		);

		$this->assertSame( '^/blog/(.+)$', Redirects::all()[ $id ]['source'] );
		$this->assertTrue( Redirects::all()[ $id ]['is_regex'] );
	}

	/**
	 * A well-formed pattern is valid once wrapped in delimiters.
	 */
	public function test_is_valid_regex_accepts_a_well_formed_pattern(): void {
		$this->assertTrue( Redirects::is_valid_regex( '^/blog/(.+)$' ) );
	}

	/**
	 * An empty pattern, and one with an unclosed group, are both invalid.
	 */
	public function test_is_valid_regex_rejects_empty_and_malformed_patterns(): void {
		$this->assertFalse( Redirects::is_valid_regex( '' ) );
		$this->assertFalse( Redirects::is_valid_regex( '   ' ) );
		$this->assertFalse( Redirects::is_valid_regex( '^/blog/(.+$' ) );
	}

	/**
	 * A regex rule matches the current path via its pattern, not an
	 * exact-string comparison.
	 */
	public function test_match_finds_an_active_regex_rule(): void {
		Redirects::save(
			0,
			array(
				'source'   => '^/blog/(.+)$',
				'target'   => '/news/$1',
				'type'     => 301,
				'active'   => true,
				'is_regex' => true,
			)
		);

		$match = Redirects::match( '/blog/my-post' );

		$this->assertNotNull( $match );
		$this->assertSame( '/news/$1', $match['target'] );
	}

	/**
	 * A regex rule with an invalid pattern never matches anything, rather
	 * than raising a warning or matching everything.
	 */
	public function test_match_never_matches_an_invalid_regex_rule(): void {
		Redirects::save(
			0,
			array(
				'source'   => '^/blog/(.+$',
				'target'   => '/news/$1',
				'type'     => 301,
				'active'   => true,
				'is_regex' => true,
			)
		);

		$this->assertNull( Redirects::match( '/blog/my-post' ) );
	}

	/**
	 * resolve_target() substitutes the regex rule's capture groups from
	 * the matched path into the target's backreferences.
	 */
	public function test_resolve_target_substitutes_regex_backreferences(): void {
		$rule = array(
			'source'   => '^/blog/(.+)$',
			'target'   => '/news/$1',
			'is_regex' => true,
		);

		$this->assertSame( '/news/my-post', Redirects::resolve_target( $rule, '/blog/my-post' ) );
	}

	/**
	 * resolve_target() returns the target unchanged for a non-regex rule.
	 */
	public function test_resolve_target_returns_target_unchanged_for_exact_rule(): void {
		$rule = array(
			'source'   => '/old',
			'target'   => '/new',
			'is_regex' => false,
		);

		$this->assertSame( '/new', Redirects::resolve_target( $rule, '/old' ) );
	}

	/**
	 * A CSV row with a source parses into a clean save() payload, with
	 * sensible defaults for missing columns.
	 */
	public function test_parse_csv_row_maps_a_complete_row(): void {
		$parsed = Redirects::parse_csv_row(
			array(
				'source'   => '/old',
				'target'   => '/new',
				'type'     => '302',
				'active'   => 'yes',
				'is_regex' => '0',
			)
		);

		$this->assertSame(
			array(
				'source'   => '/old',
				'target'   => '/new',
				'type'     => 302,
				'active'   => true,
				'is_regex' => false,
			),
			$parsed
		);
	}

	/**
	 * A row with no source (or a blank one) is skipped, not turned into
	 * an empty rule.
	 */
	public function test_parse_csv_row_returns_null_for_missing_source(): void {
		$this->assertNull( Redirects::parse_csv_row( array( 'target' => '/new' ) ) );
		$this->assertNull( Redirects::parse_csv_row( array( 'source' => '   ' ) ) );
	}

	/**
	 * An unknown type falls back to 301, same as save().
	 */
	public function test_parse_csv_row_falls_back_to_301_for_unknown_type(): void {
		$parsed = Redirects::parse_csv_row( array( 'source' => '/old', 'type' => '999' ) );

		$this->assertSame( 301, $parsed['type'] );
	}

	/**
	 * to_csv_row() is the exact counterpart of parse_csv_row(): its
	 * output, fed back through parse_csv_row(), reproduces the original
	 * rule's meaningful fields.
	 */
	public function test_to_csv_row_round_trips_through_parse_csv_row(): void {
		$rule = array(
			'source'   => '/old',
			'target'   => '/new',
			'type'     => 302,
			'active'   => false,
			'is_regex' => true,
			'hits'     => 7,
		);

		$round_tripped = Redirects::parse_csv_row( Redirects::to_csv_row( $rule ) );

		$this->assertSame(
			array(
				'source'   => '/old',
				'target'   => '/new',
				'type'     => 302,
				'active'   => false,
				'is_regex' => true,
			),
			$round_tripped
		);
	}

	/**
	 * The CSV header matches the keys to_csv_row() actually produces.
	 */
	public function test_csv_header_matches_to_csv_row_keys(): void {
		$row = Redirects::to_csv_row(
			array(
				'source'   => '/old',
				'target'   => '/new',
				'type'     => 301,
				'active'   => true,
				'is_regex' => false,
				'hits'     => 0,
			)
		);

		$this->assertSame( Redirects::csv_header(), array_keys( $row ) );
	}
}

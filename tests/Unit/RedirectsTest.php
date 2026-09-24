<?php
/**
 * Tests for redirect rule storage and matching.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Crawl\Redirects;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Crawl\Redirects
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
	 * A pattern longer than the accepted maximum is rejected outright,
	 * without ever reaching preg_match().
	 */
	public function test_is_valid_regex_rejects_an_overlong_pattern(): void {
		$this->assertFalse( Redirects::is_valid_regex( str_repeat( 'a', 501 ) ) );
		$this->assertTrue( Redirects::is_valid_regex( str_repeat( 'a', 500 ) ) );
	}

	/**
	 * Classic nested-quantifier shapes known to cause catastrophic
	 * backtracking are rejected, even though they compile as valid PCRE.
	 */
	public function test_is_valid_regex_rejects_catastrophic_backtracking_shapes(): void {
		$this->assertFalse( Redirects::is_valid_regex( '^(a+)+$' ) );
		$this->assertFalse( Redirects::is_valid_regex( '^(a*)*$' ) );
		$this->assertFalse( Redirects::is_valid_regex( '^(a+)*$' ) );
		$this->assertFalse( Redirects::is_valid_regex( '^(ab+c*)+$' ) );
	}

	/**
	 * Ordinary redirect patterns using + or * outside of a nested-group
	 * shape are unaffected by the backtracking guard.
	 */
	public function test_is_valid_regex_still_accepts_ordinary_quantifiers(): void {
		$this->assertTrue( Redirects::is_valid_regex( '^/blog/(.+)$' ) );
		$this->assertTrue( Redirects::is_valid_regex( '^/old-.*-page$' ) );
		$this->assertTrue( Redirects::is_valid_regex( '^/products/([a-z0-9-]+)/reviews$' ) );
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

	/**
	 * Recording one execution-limit failure increments the counter but
	 * leaves the rule active — it takes MAX_REGEX_FAILURES to quarantine.
	 */
	public function test_record_regex_execution_failure_increments_without_quarantining_early(): void {
		$id = Redirects::save(
			0,
			array( 'source' => '^/blog/(.+)$', 'target' => '/news/$1', 'type' => 301, 'active' => true, 'is_regex' => true )
		);

		Redirects::record_regex_execution_failure( $id );

		$rule = Redirects::all()[ $id ];
		$this->assertSame( 1, $rule['regex_failures'] );
		$this->assertTrue( $rule['active'] );
		$this->assertArrayNotHasKey( 'regex_quarantined', $rule );
	}

	/**
	 * A rule is deactivated and flagged quarantined once it reaches
	 * MAX_REGEX_FAILURES execution-limit failures.
	 */
	public function test_record_regex_execution_failure_quarantines_after_threshold(): void {
		$id = Redirects::save(
			0,
			array( 'source' => '^/blog/(.+)$', 'target' => '/news/$1', 'type' => 301, 'active' => true, 'is_regex' => true )
		);

		Redirects::record_regex_execution_failure( $id );
		Redirects::record_regex_execution_failure( $id );
		Redirects::record_regex_execution_failure( $id );

		$rule = Redirects::all()[ $id ];
		$this->assertSame( 3, $rule['regex_failures'] );
		$this->assertFalse( $rule['active'] );
		$this->assertTrue( $rule['regex_quarantined'] );
	}

	/**
	 * A quarantined rule is no longer matched — the deactivation from
	 * record_regex_execution_failure() takes effect immediately.
	 */
	public function test_quarantined_rule_is_never_matched(): void {
		$id = Redirects::save(
			0,
			array( 'source' => '^/blog/(.+)$', 'target' => '/news/$1', 'type' => 301, 'active' => true, 'is_regex' => true )
		);

		for ( $i = 0; $i < 3; $i++ ) {
			Redirects::record_regex_execution_failure( $id );
		}

		$this->assertNull( Redirects::match( '/blog/my-post' ) );
	}

	/**
	 * A no-op for an unknown rule ID rather than corrupting the stored
	 * option with a bogus entry.
	 */
	public function test_record_regex_execution_failure_ignores_unknown_id(): void {
		Redirects::record_regex_execution_failure( 999999 );

		$this->assertArrayNotHasKey( 999999, Redirects::all() );
	}

	/**
	 * Re-saving a quarantined rule (e.g. after fixing the pattern) clears
	 * its failure history and quarantine flag — a fresh start, since
	 * save() rebuilds the stored row from scratch rather than carrying
	 * those fields forward.
	 */
	public function test_saving_a_rule_clears_prior_quarantine_state(): void {
		$id = Redirects::save(
			0,
			array( 'source' => '^/blog/(.+)$', 'target' => '/news/$1', 'type' => 301, 'active' => true, 'is_regex' => true )
		);
		for ( $i = 0; $i < 3; $i++ ) {
			Redirects::record_regex_execution_failure( $id );
		}

		Redirects::save( $id, array( 'source' => '^/blog/(.+)$', 'target' => '/news/$1', 'type' => 301, 'active' => true, 'is_regex' => true ) );

		$rule = Redirects::all()[ $id ];
		$this->assertTrue( $rule['active'] );
		$this->assertArrayNotHasKey( 'regex_quarantined', $rule );
		$this->assertArrayNotHasKey( 'regex_failures', $rule );
	}
}

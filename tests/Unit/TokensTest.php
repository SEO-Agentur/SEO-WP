<?php
/**
 * Tests for internal token resolution and cross-plugin template conversion.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Templates\Tokens;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Templates\Tokens
 */
final class TokensTest extends TestCase {

	/**
	 * Known tokens are substituted with their supplied values.
	 */
	public function test_resolve_substitutes_known_tokens(): void {
		$result = Tokens::resolve(
			'{title} {sep} {sitename}',
			array(
				'title'    => 'Hello World',
				'sep'      => '-',
				'sitename' => 'My Site',
			)
		);

		$this->assertSame( 'Hello World - My Site', $result );
	}

	/**
	 * A token missing from the value map is left untouched.
	 */
	public function test_resolve_leaves_unmapped_tokens_untouched(): void {
		$result = Tokens::resolve( '{title} {unknown}', array( 'title' => 'Hi' ) );

		$this->assertSame( 'Hi {unknown}', $result );
	}

	/**
	 * Yoast's double-percent syntax converts to internal {token} syntax.
	 */
	public function test_from_yoast_converts_known_tokens(): void {
		$result = Tokens::from_yoast( '%%title%% %%sep%% %%sitename%%' );

		$this->assertSame( '{title} {sep} {sitename}', $result );
	}

	/**
	 * Rank Math's single-percent syntax converts to internal {token} syntax.
	 */
	public function test_from_rank_math_converts_known_tokens(): void {
		$result = Tokens::from_rank_math( '%title% %sep% %category%' );

		$this->assertSame( '{title} {sep} {category}', $result );
	}

	/**
	 * AIOSEO's hash syntax converts to internal {token} syntax.
	 */
	public function test_from_aioseo_converts_known_tokens(): void {
		$result = Tokens::from_aioseo( '#post_title #separator_sa #site_title' );

		$this->assertSame( '{title} {sep} {sitename}', $result );
	}

	/**
	 * Unknown vendor-syntax tokens are reported and left unchanged.
	 */
	public function test_unknown_token_is_reported_and_left_unchanged(): void {
		$reported = array();

		$result = Tokens::from_yoast(
			'%%title%% %%parent_title%%',
			static function ( string $token ) use ( &$reported ): void {
				$reported[] = $token;
			}
		);

		$this->assertSame( '{title} %%parent_title%%', $result );
		$this->assertSame( array( '%%parent_title%%' ), $reported );
	}

	/**
	 * Every source's own "current year" placeholder converts to the same
	 * internal {year} token.
	 */
	public function test_year_token_converts_from_every_source(): void {
		$this->assertSame( '{year}', Tokens::from_yoast( '%%currentyear%%' ) );
		$this->assertSame( '{year}', Tokens::from_rank_math( '%year%' ) );
		$this->assertSame( '{year}', Tokens::from_aioseo( '#current_year' ) );
		$this->assertSame( '%year%', Tokens::to_ui( '{year}' ) );
		$this->assertSame( '{year}', Tokens::from_ui( '%year%' ) );
	}

	/**
	 * Internal {token} syntax round-trips through the UI's %token% syntax.
	 */
	public function test_ui_round_trip(): void {
		$internal = '{title} {sep} {sitename}';

		$ui = Tokens::to_ui( $internal );
		$this->assertSame( '%title% %sep% %sitename%', $ui );

		$back = Tokens::from_ui( $ui );
		$this->assertSame( $internal, $back );
	}

	/**
	 * from_ui() only converts known token names, leaving stray percent
	 * signs in free text alone.
	 */
	public function test_from_ui_ignores_unknown_percent_tokens(): void {
		$result = Tokens::from_ui( '100% %title% off' );

		$this->assertSame( '100% {title} off', $result );
	}
}

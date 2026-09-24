<?php
/**
 * Tests for the pure override-vs-computed precedence used by Canonical.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Meta\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Meta\Canonical
 */
final class CanonicalTest extends TestCase {

	/**
	 * A non-empty override always wins over the computed value.
	 */
	public function test_override_wins_when_present(): void {
		$result = Canonical::pick( 'https://example.com/override/', 'https://example.com/computed/' );

		$this->assertSame( 'https://example.com/override/', $result );
	}

	/**
	 * With no override, the computed value is used.
	 */
	public function test_computed_value_used_when_no_override(): void {
		$result = Canonical::pick( '', 'https://example.com/computed/' );

		$this->assertSame( 'https://example.com/computed/', $result );
	}

	/**
	 * A well-formed absolute http(s) URL is accepted as-is.
	 */
	public function test_sanitize_override_accepts_a_well_formed_url(): void {
		$this->assertSame(
			'https://example.com/canonical-target/',
			Canonical::sanitize_override( 'https://example.com/canonical-target/' )
		);
	}

	/**
	 * An empty submission stays empty (no override set).
	 */
	public function test_sanitize_override_accepts_empty_string(): void {
		$this->assertSame( '', Canonical::sanitize_override( '' ) );
	}

	/**
	 * A non-http(s) scheme is rejected outright, not just left unescaped —
	 * this value is later echoed as <link rel="canonical" href="...">, and
	 * this field is reachable by any role with the (by default Author/
	 * Contributor-level) seo_wp_edit_meta capability.
	 *
	 * @dataProvider invalid_override_provider
	 */
	public function test_sanitize_override_rejects_non_http_values( string $raw ): void {
		$this->assertSame( '', Canonical::sanitize_override( $raw ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function invalid_override_provider(): array {
		return array(
			'javascript scheme' => array( 'javascript:alert(1)' ),
			'not a url at all'  => array( 'this is not a url' ),
			'bare host'         => array( 'attacker.example' ),
		);
	}
}

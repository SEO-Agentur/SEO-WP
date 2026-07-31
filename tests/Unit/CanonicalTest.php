<?php
/**
 * Tests for the pure override-vs-computed precedence used by Canonical.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Meta\Canonical;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Meta\Canonical
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
}

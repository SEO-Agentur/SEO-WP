<?php
/**
 * Tests for reading a verification code out of pasted input.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Crawl\Verification;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Crawl\Verification
 */
final class VerificationTest extends TestCase {

	/**
	 * A bare code is kept, a whole meta tag (as the consoles hand it out)
	 * is reduced to its content value.
	 */
	public function test_extracts_the_code_from_a_pasted_meta_tag(): void {
		$this->assertSame( 'abc123', Verification::extract_code( ' abc123 ' ) );
		$this->assertSame( 'abc-123_XYZ', Verification::extract_code( '<meta name="google-site-verification" content="abc-123_XYZ" />' ) );
		$this->assertSame( 'A1B2', Verification::extract_code( "<meta name='msvalidate.01' content='A1B2'>" ) );
		$this->assertSame( '', Verification::extract_code( '' ) );
	}
}

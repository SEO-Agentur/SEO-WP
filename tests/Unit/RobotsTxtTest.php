<?php
/**
 * Tests for the pure AI-crawler robots.txt block builder.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Crawl\RobotsTxt;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Crawl\RobotsTxt
 */
final class RobotsTxtTest extends TestCase {

	/**
	 * Every known AI crawler gets its own User-agent/Disallow pair.
	 */
	public function test_blocks_every_known_ai_crawler(): void {
		$block = RobotsTxt::ai_crawler_block();

		$this->assertStringContainsString( "User-agent: GPTBot\nDisallow: /", $block );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nDisallow: /", $block );
		$this->assertStringContainsString( "User-agent: CCBot\nDisallow: /", $block );
	}

	/**
	 * The block has no leading or trailing blank lines, since the caller
	 * controls spacing around it.
	 */
	public function test_block_has_no_leading_or_trailing_whitespace(): void {
		$block = RobotsTxt::ai_crawler_block();

		$this->assertSame( trim( $block ), $block );
	}

	/**
	 * Every "User-agent:" line is immediately followed by "Disallow: /".
	 */
	public function test_every_user_agent_line_is_followed_by_disallow(): void {
		$lines = explode( "\n", RobotsTxt::ai_crawler_block() );

		foreach ( $lines as $index => $line ) {
			if ( 0 !== strpos( $line, 'User-agent:' ) ) {
				continue;
			}

			$this->assertSame( 'Disallow: /', $lines[ $index + 1 ] ?? null );
		}
	}
}

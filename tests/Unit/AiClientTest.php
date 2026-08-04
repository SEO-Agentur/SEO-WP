<?php
/**
 * Tests for parsing an AI model's title/description JSON reply.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Support\AiClient;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Support\AiClient
 */
final class AiClientTest extends TestCase {

	/**
	 * A plain JSON reply parses directly.
	 */
	public function test_parses_plain_json(): void {
		$reply = '{"title": "SEO: Fast | Reliable > Get a quote", "description": "SEO: Fast | Reliable | Proven > Get a quote"}';

		$result = AiClient::parse_title_description_reply( $reply );

		$this->assertSame(
			array(
				'title'       => 'SEO: Fast | Reliable > Get a quote',
				'description' => 'SEO: Fast | Reliable | Proven > Get a quote',
			),
			$result
		);
	}

	/**
	 * A reply wrapped in a markdown code fence (models do this even when
	 * told not to) still parses.
	 */
	public function test_parses_reply_wrapped_in_markdown_fence(): void {
		$reply = "```json\n{\"title\": \"A\", \"description\": \"B\"}\n```";

		$result = AiClient::parse_title_description_reply( $reply );

		$this->assertSame( array( 'title' => 'A', 'description' => 'B' ), $result );
	}

	/**
	 * Invalid JSON returns null rather than throwing.
	 */
	public function test_returns_null_for_invalid_json(): void {
		$this->assertNull( AiClient::parse_title_description_reply( 'not json at all' ) );
	}

	/**
	 * A JSON object missing one of the two required keys returns null.
	 */
	public function test_returns_null_when_a_key_is_missing(): void {
		$this->assertNull( AiClient::parse_title_description_reply( '{"title": "Only title"}' ) );
	}

	/**
	 * A JSON object with an empty value for either key returns null.
	 */
	public function test_returns_null_when_a_value_is_empty(): void {
		$this->assertNull( AiClient::parse_title_description_reply( '{"title": "", "description": "B"}' ) );
	}
}

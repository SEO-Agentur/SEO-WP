<?php
/**
 * Tests for template rendering and separator cleanup.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Tests\Unit;

use EasyWordpressSeo\Templates\Renderer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \EasyWordpressSeo\Templates\Renderer
 */
final class RendererTest extends TestCase {

	/**
	 * A fully populated template renders with single separators.
	 */
	public function test_renders_fully_populated_template(): void {
		$result = Renderer::render(
			'{title} {sep} {sitename}',
			array(
				'title'    => 'Hello',
				'sep'      => '-',
				'sitename' => 'My Site',
			),
			'-'
		);

		$this->assertSame( 'Hello - My Site', $result );
	}

	/**
	 * Adjacent empty tokens collapse to a single separator instead of a
	 * run of repeated ones.
	 */
	public function test_collapses_repeated_separators_from_empty_tokens(): void {
		$result = Renderer::render(
			'{title} {sep} {category} {sep} {sitename}',
			array(
				'title'    => 'Hello',
				'sep'      => '-',
				'category' => '',
				'sitename' => 'My Site',
			),
			'-'
		);

		$this->assertSame( 'Hello - My Site', $result );
	}

	/**
	 * A leading or trailing separator caused by an empty edge token is
	 * trimmed away.
	 */
	public function test_trims_leading_and_trailing_separators(): void {
		$result = Renderer::render(
			'{category} {sep} {title}',
			array(
				'category' => '',
				'sep'      => '-',
				'title'    => 'Hello',
			),
			'-'
		);

		$this->assertSame( 'Hello', $result );
	}

	/**
	 * A multi-character separator is cleaned up the same way.
	 */
	public function test_handles_multi_character_separator(): void {
		$result = Renderer::render(
			'{title} {sep} {category} {sep} {sitename}',
			array(
				'title'    => 'Hello',
				'sep'      => '::',
				'category' => '',
				'sitename' => 'My Site',
			),
			'::'
		);

		$this->assertSame( 'Hello :: My Site', $result );
	}
}

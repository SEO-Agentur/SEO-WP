<?php
/**
 * Tests for the plugin's PSR-4 style autoloader.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Seooptimierung\Toolkit\Templates\Tokens;
use Seooptimierung\Toolkit\Schema\Nodes\WebPage;

/**
 * @covers \Seooptimierung\Toolkit\Autoloader
 */
final class AutoloaderTest extends TestCase {

	/**
	 * A root-namespace class resolves to src/<Class>.php.
	 */
	public function test_autoloads_top_level_class(): void {
		$this->assertTrue( class_exists( Tokens::class ) );
	}

	/**
	 * A nested-namespace class resolves to src/<Sub>/<Path>/<Class>.php.
	 */
	public function test_autoloads_nested_namespace_class(): void {
		$this->assertTrue( class_exists( WebPage::class ) );
	}

	/**
	 * Classes outside the plugin's namespace are ignored.
	 */
	public function test_ignores_foreign_namespace(): void {
		$this->assertFalse( class_exists( 'Some\\Other\\Vendor\\ClassName', false ) );
	}

	/**
	 * A crafted class name containing path-traversal segments never
	 * reaches is_readable()/require — the string handed to a registered
	 * autoloader isn't required to look like a real class declaration, and
	 * autoload() rejects anything outside [A-Za-z0-9_\\] before building a
	 * file path from it.
	 */
	public function test_rejects_path_traversal_in_class_name(): void {
		$this->assertFalse( class_exists( 'Seooptimierung\Toolkit\\..\\..\\..\\..\\wp-config', true ) );
	}
}

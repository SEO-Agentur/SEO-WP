<?php
/**
 * Tests for extracting external resource hosts and suggesting hints.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Performance\ResourceHintScanner;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Performance\ResourceHintScanner
 */
final class ResourceHintScannerTest extends TestCase {

	/**
	 * Resources of every kind are attributed to their host, with the
	 * kinds counted; the site's own non-font assets are ignored.
	 */
	public function test_analyse_groups_resource_kinds_per_host(): void {
		$page = '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">'
			. '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>'
			. '<img src="https://cdn.example.net/a.jpg" srcset="https://cdn.example.net/a-2x.jpg 2x, https://cdn.example.net/a-3x.jpg 3x">'
			. '<iframe src="https://www.youtube.com/embed/abc"></iframe>'
			. '<img src="https://example.com/own.jpg">';

		$rows = ResourceHintScanner::analyse( array( $page ), array(), 'example.com' );
		$by   = array_column( $rows, null, 'host' );

		$this->assertArrayNotHasKey( 'example.com', $by );
		$this->assertSame( array( 'preconnect https://fonts.googleapis.com' ), $by['fonts.googleapis.com']['suggestions'] );
		$this->assertSame( array( 'preconnect https://www.googletagmanager.com' ), $by['www.googletagmanager.com']['suggestions'] );
		$this->assertSame( 3, $by['cdn.example.net']['total'] );
		$this->assertSame( array( 'image' => 3 ), $by['cdn.example.net']['types'] );
		$this->assertSame( array( 'dns-prefetch https://cdn.example.net' ), $by['cdn.example.net']['suggestions'] );
		$this->assertSame( array( 'dns-prefetch https://www.youtube.com' ), $by['www.youtube.com']['suggestions'] );
	}

	/**
	 * Font files found inside a stylesheet yield a crossorigin
	 * preconnect and a preload line; relative url() references resolve
	 * against the stylesheet's own URL.
	 */
	public function test_analyse_finds_fonts_in_stylesheets(): void {
		$css = array(
			'https://example.com/wp-content/themes/t/css/style.css' => "@font-face{src:url('../fonts/body.woff2') format('woff2')}"
				. '@font-face{src:url(https://fonts.gstatic.com/s/inter/v1/x.woff2)}',
		);

		$rows = ResourceHintScanner::analyse( array( '<html></html>' ), $css, 'example.com' );
		$by   = array_column( $rows, null, 'host' );

		$this->assertSame(
			array( 'preload https://example.com/wp-content/themes/t/fonts/body.woff2 as=font type=font/woff2 crossorigin' ),
			$by['example.com']['suggestions']
		);
		$this->assertSame(
			array(
				'preconnect https://fonts.gstatic.com crossorigin',
				'preload https://fonts.gstatic.com/s/inter/v1/x.woff2 as=font type=font/woff2 crossorigin',
			),
			$by['fonts.gstatic.com']['suggestions']
		);
	}

	/**
	 * Hosts that already have a configured hint are flagged, and the
	 * Resource Hints field's hosts are read correctly.
	 */
	public function test_analyse_flags_already_hinted_hosts(): void {
		$rows = ResourceHintScanner::analyse(
			array( '<script src="https://cdn.example.net/a.js"></script>' ),
			array(),
			'example.com',
			ResourceHintScanner::hinted_hosts_from_text( "# note\npreconnect https://cdn.example.net\n\ndns-prefetch https://other.test crossorigin" )
		);

		$this->assertTrue( $rows[0]['hinted'] );
		$this->assertSame( array( 'cdn.example.net', 'other.test' ), ResourceHintScanner::hinted_hosts_from_text( "# note\npreconnect https://cdn.example.net\n\ndns-prefetch https://other.test crossorigin" ) );
	}

	/**
	 * Existing hint tags in the markup are not themselves reported as
	 * resources.
	 */
	public function test_analyse_ignores_existing_hint_tags(): void {
		$rows = ResourceHintScanner::analyse( array( '<link rel="preconnect" href="https://fonts.googleapis.com">' ), array(), 'example.com' );

		$this->assertSame( array(), $rows );
	}

	/**
	 * A Google Fonts stylesheet implies the gstatic font host, which only
	 * appears inside that (never fetched) third-party stylesheet.
	 */
	public function test_analyse_implies_gstatic_for_google_fonts(): void {
		$rows = ResourceHintScanner::analyse( array( '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter">' ), array(), 'example.com' );
		$by   = array_column( $rows, null, 'host' );

		$this->assertSame( array( 'preconnect https://fonts.gstatic.com crossorigin' ), $by['fonts.gstatic.com']['suggestions'] );
	}
}

<?php
/**
 * Tests for the alt text helpers.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Admin\ImageSeo;
use Seooptimierung\Toolkit\Media\AltText;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Media\AltText
 * @covers \Seooptimierung\Toolkit\Admin\ImageSeo
 */
final class AltTextTest extends TestCase {

	/**
	 * File names become readable words; WordPress' size and "-scaled"
	 * suffixes and the extension disappear.
	 */
	public function test_humanize_makes_words_from_file_names(): void {
		$this->assertSame( 'schaltbares glas buero', AltText::humanize( 'schaltbares-glas_buero.webp' ) );
		$this->assertSame( 'Rote Rosen im Garten', AltText::humanize( 'Rote-Rosen-im-Garten-scaled.jpg' ) );
		$this->assertSame( 'hero banner', AltText::humanize( 'hero-banner-1024x768.png' ) );
	}

	/**
	 * Names that only say "camera counter" or "screenshot" yield nothing,
	 * so no meaningless alt text is written.
	 */
	public function test_humanize_skips_meaningless_names(): void {
		foreach ( array( 'IMG_1234.jpg', 'DSC0042.JPG', 'Screenshot 2026-10-09 at 10.11.12.png', 'image-3.png', 'Unbenannt-1.jpg', '' ) as $name ) {
			$this->assertSame( '', AltText::humanize( $name ), $name );
		}
	}

	/**
	 * The model's reply is reduced to one clean line of at most 125
	 * characters, cut at a word boundary.
	 */
	public function test_clean_reply(): void {
		$this->assertSame( 'Ein rotes Fahrrad vor einer Backsteinwand', AltText::clean_reply( "  \"Ein rotes Fahrrad vor einer Backsteinwand\"\n" ) );
		$this->assertSame( 'Büro mit Glaswand', AltText::clean_reply( 'Alt text: Büro mit Glaswand' ) );

		$long = AltText::clean_reply( str_repeat( 'Glaswand im Büro ', 20 ) );
		$this->assertLessThanOrEqual( 125, mb_strlen( $long ) );
		$this->assertStringEndsNotWith( ' ', $long );
	}

	/**
	 * The prompt carries the rules, the language and whatever context exists.
	 */
	public function test_build_prompt(): void {
		$prompt = AltText::build_prompt( 'Rosen', '', 'Gartenpflege', 'German (de_DE)' );

		$this->assertStringContainsString( 'German (de_DE)', $prompt );
		$this->assertStringContainsString( 'File title: Rosen', $prompt );
		$this->assertStringContainsString( 'Gartenpflege', $prompt );
		$this->assertStringNotContainsString( 'Caption:', $prompt );
	}

	/**
	 * Only an img with no alt attribute at all counts; an empty alt is a
	 * deliberate decision.
	 */
	public function test_counts_only_images_without_an_alt_attribute(): void {
		$html = '<img src="a.jpg"><img src="b.jpg" alt=""><img src="c.jpg" alt="Ein Bild"><IMG SRC="d.jpg" class="x"><img data-alt="n" src="e.jpg">';

		$this->assertSame( 3, ImageSeo::count_images_without_alt( $html ) );
		$this->assertSame( 0, ImageSeo::count_images_without_alt( '<p>kein Bild</p>' ) );
	}
}

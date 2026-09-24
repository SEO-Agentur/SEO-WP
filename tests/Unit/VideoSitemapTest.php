<?php
/**
 * Tests for the video:video sitemap extraction logic.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Sitemaps\VideoSitemap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Sitemaps\VideoSitemap
 */
final class VideoSitemapTest extends TestCase {

	/**
	 * A <video src poster> single-tag form is recognised.
	 */
	public function test_extract_self_hosted_videos_finds_src_attribute_form(): void {
		$html = '<p>Intro</p><video src="https://example.com/clip.mp4" poster="https://example.com/poster.jpg" controls></video>';

		$videos = VideoSitemap::extract_self_hosted_videos( $html );

		$this->assertSame(
			array(
				array(
					'thumbnail_loc' => 'https://example.com/poster.jpg',
					'content_loc'   => 'https://example.com/clip.mp4',
				),
			),
			$videos
		);
	}

	/**
	 * A <video poster><source src></video> child-element form is
	 * recognised too.
	 */
	public function test_extract_self_hosted_videos_finds_source_child_form(): void {
		$html = '<video poster="https://example.com/poster.jpg"><source src="https://example.com/clip.webm" type="video/webm" /></video>';

		$videos = VideoSitemap::extract_self_hosted_videos( $html );

		$this->assertSame(
			array(
				array(
					'thumbnail_loc' => 'https://example.com/poster.jpg',
					'content_loc'   => 'https://example.com/clip.webm',
				),
			),
			$videos
		);
	}

	/**
	 * A <video> with no poster is skipped: the required thumbnail can't
	 * be satisfied without guessing.
	 */
	public function test_extract_self_hosted_videos_skips_video_without_poster(): void {
		$html = '<video src="https://example.com/clip.mp4"></video>';

		$this->assertSame( array(), VideoSitemap::extract_self_hosted_videos( $html ) );
	}

	/**
	 * A <video poster> with no resolvable source is skipped too.
	 */
	public function test_extract_self_hosted_videos_skips_video_without_source(): void {
		$html = '<video poster="https://example.com/poster.jpg"></video>';

		$this->assertSame( array(), VideoSitemap::extract_self_hosted_videos( $html ) );
	}

	/**
	 * A youtube.com/embed/ iframe resolves to its known thumbnail and the
	 * full iframe src as the player URL.
	 *
	 * @dataProvider youtube_embed_url_provider
	 */
	public function test_extract_youtube_videos_finds_embed( string $src ): void {
		$html = '<iframe src="' . $src . '" width="560" height="315"></iframe>';

		$videos = VideoSitemap::extract_youtube_videos( $html );

		$this->assertCount( 1, $videos );
		$this->assertSame( $src, $videos[0]['player_loc'] );
		$this->assertSame( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $videos[0]['thumbnail_loc'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function youtube_embed_url_provider(): array {
		return array(
			'youtube.com'          => array( 'https://www.youtube.com/embed/dQw4w9WgXcQ' ),
			'youtube-nocookie.com' => array( 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' ),
			'with query string'    => array( 'https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0' ),
		);
	}

	/**
	 * An iframe from an unrelated host is not treated as a video.
	 */
	public function test_extract_youtube_videos_ignores_other_iframes(): void {
		$html = '<iframe src="https://player.vimeo.com/video/12345"></iframe>';

		$this->assertSame( array(), VideoSitemap::extract_youtube_videos( $html ) );
	}

	/**
	 * The static thumbnail URL pattern is exactly YouTube's documented one.
	 */
	public function test_youtube_thumbnail_url(): void {
		$this->assertSame(
			'https://i.ytimg.com/vi/abc123/hqdefault.jpg',
			VideoSitemap::youtube_thumbnail_url( 'abc123' )
		);
	}
}

<?php
/**
 * Sitemap index (/index_sitemap.xml, also served at /sitemap.xml).
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

/**
 * Renders the top-level sitemap index: every enabled post type and
 * taxonomy sub-sitemap, the image and video sitemaps when they have
 * entries, and the news sitemap when it has entries. Each sitemap is
 * listed under its configured name (see SitemapNames).
 */
final class Index {

	/**
	 * Builds the sitemap index.
	 */
	public function render(): string {
		$sitemaps = array();

		$post_sitemap     = new PostTypeSitemap();
		$taxonomy_sitemap = new TaxonomySitemap();
		$media_sitemap    = new MediaSitemap();

		foreach ( SitemapNames::entries() as $entry ) {
			if ( ! $entry['enabled'] ) {
				continue;
			}

			switch ( $entry['kind'] ) {
				case SitemapNames::KIND_POST_TYPE:
					$pages = $post_sitemap->max_pages( $entry['slug'] );
					for ( $page = 1; $page <= $pages; $page++ ) {
						$sitemaps[] = SitemapNames::url( $entry['stem'], $page );
					}
					break;

				case SitemapNames::KIND_TAXONOMY:
					$pages = $taxonomy_sitemap->max_pages( $entry['slug'] );
					for ( $page = 1; $page <= $pages; $page++ ) {
						$sitemaps[] = SitemapNames::url( $entry['stem'], $page );
					}
					break;

				case SitemapNames::KIND_IMAGES:
				case SitemapNames::KIND_VIDEO:
					$pages = $media_sitemap->max_pages( $entry['kind'] );
					for ( $page = 1; $page <= $pages; $page++ ) {
						// A page of posts without any media renders as null; listing it
						// would hand search engines a URL that answers 404.
						if ( null !== $media_sitemap->render( $entry['kind'], $page ) ) {
							$sitemaps[] = SitemapNames::url( $entry['stem'], $page );
						}
					}
					break;

				case SitemapNames::KIND_NEWS:
					if ( ( new NewsSitemap() )->has_entries() ) {
						$sitemaps[] = SitemapNames::url( $entry['stem'] );
					}
					break;
			}
		}

		return $this->xml( $sitemaps );
	}

	/**
	 * Builds the sitemapindex XML for a list of sub-sitemap URLs.
	 *
	 * @param string[] $sitemaps Sub-sitemap URLs.
	 */
	private function xml( array $sitemaps ): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $sitemaps as $loc ) {
			$xml .= '<sitemap><loc>' . esc_url( $loc ) . '</loc></sitemap>' . "\n";
		}

		$xml .= '</sitemapindex>';

		return $xml;
	}
}

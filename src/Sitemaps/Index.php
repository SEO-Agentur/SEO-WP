<?php
/**
 * Sitemap index (/sitemap.xml).
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

/**
 * Renders the top-level sitemap index listing every post type and
 * taxonomy sub-sitemap, plus the news sitemap when it has entries.
 */
final class Index {

	/**
	 * Builds the sitemap index.
	 */
	public function render(): string {
		$sitemaps = array();

		$post_sitemap = new PostTypeSitemap();
		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			$pages = $post_sitemap->max_pages( $post_type );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$sitemaps[] = home_url( "/sitemap-posts-{$post_type}-{$page}.xml" );
			}
		}

		$taxonomy_sitemap = new TaxonomySitemap();
		foreach ( get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			$pages = $taxonomy_sitemap->max_pages( $taxonomy );
			for ( $page = 1; $page <= $pages; $page++ ) {
				$sitemaps[] = home_url( "/sitemap-taxonomies-{$taxonomy}-{$page}.xml" );
			}
		}

		if ( ( new NewsSitemap() )->has_entries() ) {
			$sitemaps[] = home_url( '/sitemap-news.xml' );
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

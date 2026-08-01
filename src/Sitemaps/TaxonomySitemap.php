<?php
/**
 * Generic sitemap for a single public taxonomy.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Sitemaps;

/**
 * Renders a sitemap for any public taxonomy's terms, excluding empty and
 * noindexed terms.
 */
final class TaxonomySitemap {

	/**
	 * Maximum number of URLs per sitemap page.
	 */
	private const ITEMS_PER_PAGE = 2000;

	/**
	 * Term meta key marking a term as excluded from indexing.
	 */
	private const META_NOINDEX = '_seo_wp_robots_noindex';

	/**
	 * Number of sitemap pages a taxonomy needs, given the exclusion rules.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function max_pages( string $taxonomy ): int {
		return (int) ceil( count( $this->eligible_term_ids( $taxonomy ) ) / self::ITEMS_PER_PAGE );
	}

	/**
	 * Renders one page of a taxonomy's sitemap, or null when the taxonomy
	 * is not public or the page has nothing to show.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @param int    $page     Page number, starting at 1.
	 */
	public function render( string $taxonomy, int $page ): ?string {
		if ( ! in_array( $taxonomy, get_taxonomies( array( 'public' => true ), 'names' ), true ) ) {
			return null;
		}

		$ids   = $this->eligible_term_ids( $taxonomy );
		$slice = array_slice( $ids, ( $page - 1 ) * self::ITEMS_PER_PAGE, self::ITEMS_PER_PAGE );

		if ( array() === $slice ) {
			return null;
		}

		$hreflang = new HreflangAlternates();
		$urls     = array();

		foreach ( $slice as $term_id ) {
			$link = get_term_link( $term_id, $taxonomy );
			if ( is_wp_error( $link ) ) {
				continue;
			}

			$urls[] = array(
				'loc'        => (string) $link,
				'alternates' => $hreflang->for_term( $term_id, $taxonomy ),
			);
		}

		return $this->xml( $urls );
	}

	/**
	 * Terms eligible for the sitemap: non-empty and not noindexed.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 * @return int[]
	 */
	private function eligible_term_ids( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'fields'     => 'ids',
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_NOINDEX,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_NOINDEX,
						'value'   => '1',
						'compare' => '!=',
					),
				),
			)
		);

		return is_array( $terms ) ? array_map( 'intval', $terms ) : array();
	}

	/**
	 * Builds the urlset XML for a list of URL entries.
	 *
	 * @param array<int,array{loc: string, alternates: array<int,array{hreflang:string,href:string}>}> $urls URL entries.
	 */
	private function xml( array $urls ): string {
		$hreflang_renderer = new HreflangAlternates();

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

		foreach ( $urls as $url ) {
			$xml .= '<url>' . "\n";
			$xml .= '<loc>' . esc_url( $url['loc'] ) . '</loc>' . "\n";
			$xml .= $hreflang_renderer->xml( $url['alternates'] );
			$xml .= '</url>' . "\n";
		}

		$xml .= '</urlset>';

		return $xml;
	}
}

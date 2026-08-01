<?php
/**
 * Builds the deduplicated JSON-LD @graph for the current request.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema;

use SeoWp\Schema\Nodes\Article;
use SeoWp\Schema\Nodes\BreadcrumbList;
use SeoWp\Schema\Nodes\FaqPage;
use SeoWp\Schema\Nodes\Organization;
use SeoWp\Schema\Nodes\Person;
use SeoWp\Schema\Nodes\Product;
use SeoWp\Schema\Nodes\WebPage;
use SeoWp\Schema\Nodes\WebSite;
use SeoWp\Support\FragmentCache;

/**
 * Collects schema nodes from every registered builder, deduplicates them
 * by @id and prints a single @graph script tag.
 */
final class GraphBuilder {

	/**
	 * Post/term meta key for a per-object schema type override (e.g.
	 * "Article", "Product", "FAQPage"). Reserved for a future per-object
	 * schema type picker; not yet read by any node builder here — Import
	 * (M6) already populates it from Yoast/Rank Math/AIOSEO so that data
	 * isn't lost before that feature exists.
	 */
	public const META_SCHEMA_TYPE = '_seo_wp_schema_type';

	/**
	 * Registers the wp_head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 4 );
	}

	/**
	 * Echoes the JSON-LD @graph for the current request, when there is
	 * anything to say. Routed through the fragment cache since building
	 * the graph (ancestors, attachment lookups) is the priciest part of
	 * the plugin's head output.
	 */
	public function output(): void {
		if ( is_admin() ) {
			return;
		}

		$json = ( new FragmentCache() )->remember( 'schema', array( $this, 'render' ) );

		if ( '' === $json ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() returns wp_json_encode() output, already safe inside a script tag.
		printf( '<script type="application/ld+json">%s</script>' . "\n", $json );
	}

	/**
	 * Builds the JSON-encoded @graph for the current request.
	 */
	public function render(): string {
		$nodes = self::deduplicate( $this->collect_nodes() );

		if ( array() === $nodes ) {
			return '';
		}

		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);

		return (string) wp_json_encode( $graph );
	}

	/**
	 * Runs every node builder for the current request and collects the
	 * non-null results.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_nodes(): array {
		$builders = array(
			new WebSite(),
			new Organization(),
			new Person(),
			new WebPage(),
			new Article(),
			new Product(),
			new BreadcrumbList(),
			new FaqPage(),
		);

		$nodes = array();
		foreach ( $builders as $builder ) {
			$node = $builder->build();
			if ( null !== $node ) {
				$nodes[] = $node;
			}
		}

		return $nodes;
	}

	/**
	 * Removes nodes sharing an @id, keeping the first occurrence. Nodes
	 * without an @id are always kept.
	 *
	 * @param array<int,array<string,mixed>> $nodes Nodes to deduplicate.
	 * @return array<int,array<string,mixed>>
	 */
	public static function deduplicate( array $nodes ): array {
		$seen   = array();
		$unique = array();

		foreach ( $nodes as $node ) {
			$id = $node['@id'] ?? null;

			if ( null !== $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
			}

			$unique[] = $node;
		}

		return $unique;
	}
}

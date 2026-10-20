<?php
/**
 * Builds the deduplicated JSON-LD @graph for the current request.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

use Seooptimierung\Toolkit\Schema\Nodes\Article;
use Seooptimierung\Toolkit\Schema\Nodes\BreadcrumbList;
use Seooptimierung\Toolkit\Schema\Nodes\FaqPage;
use Seooptimierung\Toolkit\Schema\Nodes\Organization;
use Seooptimierung\Toolkit\Schema\Nodes\Person;
use Seooptimierung\Toolkit\Schema\Nodes\Product;
use Seooptimierung\Toolkit\Schema\Nodes\WebPage;
use Seooptimierung\Toolkit\Schema\Nodes\WebSite;
use Seooptimierung\Toolkit\Support\CoexistenceMode;
use Seooptimierung\Toolkit\Support\FragmentCache;
use WP_Post;
use WP_Term;

/**
 * Collects schema nodes from every registered builder, deduplicates them
 * by @id and prints a single @graph script tag.
 */
final class GraphBuilder {

	/**
	 * Post/term meta key for a per-object schema type override (e.g.
	 * "Article", "AboutPage"), applied to the @type of the Article or
	 * WebPage node by those classes themselves. Freely editable from the
	 * metabox (Admin\Metabox) on both post and term edit screens, and
	 * populated by Import from Yoast/Rank Math/AIOSEO for posts.
	 */
	public const META_SCHEMA_TYPE = '_seo_wp_schema_type';

	/**
	 * Common Schema.org type names offered as autocomplete suggestions in
	 * the metabox; any value passing is_valid_schema_type() is accepted,
	 * this list exists purely to help admins discover options, not to
	 * restrict them.
	 *
	 * @var string[]
	 */
	public const SUGGESTED_TYPES = array(
		'Article',
		'BlogPosting',
		'NewsArticle',
		'WebPage',
		'AboutPage',
		'ContactPage',
		'CollectionPage',
		'ProfilePage',
		'Product',
		'Event',
		'Recipe',
		'JobPosting',
		'LocalBusiness',
		'Review',
		'VideoObject',
		'SoftwareApplication',
		'Book',
		'Course',
		'Person',
		'Organization',
		'Service',
		'Place',
		'TouristAttraction',
		'Restaurant',
		'Hotel',
		'Store',
		'Movie',
		'MusicAlbum',
		'PodcastEpisode',
		'Dataset',
		'QAPage',
		'FAQPage',
		'HowTo',
		'ClaimReview',
		'RealEstateListing',
		'Vehicle',
		'LearningResource',
		'ItemList',
		'ImageObject',
		'DiscussionForumPosting',
		'MedicalWebPage',
		'CheckoutPage',
	);

	/**
	 * Resolves a post's schema type override, or '' when there is none or
	 * it doesn't look like a valid Schema.org type name. The validity
	 * check matters because not every source's raw value is one: Rank
	 * Math's are translated on import (RankMathImporter), but an
	 * unrecognised or untranslated value must never reach @type as-is —
	 * emitting invalid markup would be worse than not overriding at all.
	 *
	 * @param WP_Post $post Post to check.
	 */
	public static function schema_type_override( WP_Post $post ): string {
		return self::valid_or_empty( (string) get_post_meta( $post->ID, self::META_SCHEMA_TYPE, true ) );
	}

	/**
	 * Resolves a term's schema type override, applied to the WebPage node
	 * built for its archive. Same validity rule as the post variant.
	 *
	 * @param WP_Term $term Term to check.
	 */
	public static function schema_type_override_for_term( WP_Term $term ): string {
		return self::valid_or_empty( (string) get_term_meta( $term->term_id, self::META_SCHEMA_TYPE, true ) );
	}

	/**
	 * Returns $value when it's a valid Schema.org type name, else ''.
	 *
	 * @param string $value Candidate type name.
	 */
	private static function valid_or_empty( string $value ): string {
		return self::is_valid_schema_type( $value ) ? $value : '';
	}

	/**
	 * Whether a string looks like a valid Schema.org type name: letters
	 * only, starting with a capital (e.g. "Article", "JobPosting"). Pure
	 * string logic, independent of WordPress, for easy unit testing.
	 *
	 * @param string $type Candidate type name.
	 */
	public static function is_valid_schema_type( string $type ): bool {
		return 1 === preg_match( '/^[A-Z][A-Za-z]*$/', $type );
	}

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
		if ( is_admin() || CoexistenceMode::active() ) {
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
		$nodes = $this->collect_nodes();

		/**
		 * Filters the collected schema nodes before @id deduplication —
		 * add, remove or change any node (e.g. inject a Review node from
		 * another plugin). A node added here with the same @id as a
		 * built-in one and placed first in the array wins the dedup below.
		 *
		 * @param array<int,array<string,mixed>> $nodes   Nodes Seooptimierung Toolkit collected from its own builders.
		 * @param mixed                          $queried The current request's queried object (WP_Post, WP_Term, ...), or null.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		$nodes = (array) apply_filters( 'seooptimierung/schema/graph', $nodes, get_queried_object() );

		$nodes = self::deduplicate( $nodes );

		if ( array() === $nodes ) {
			return '';
		}

		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);

		// HEX_TAG / HEX_AMP: a stray "</script>" or "<!--" in any text value cannot break out of the script element.
		return (string) wp_json_encode( $graph, JSON_HEX_TAG | JSON_HEX_AMP );
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

		// Content blocks placed inside the post (recipe, how-to, event, ...).
		$post = is_singular() ? get_queried_object() : null;
		if ( $post instanceof WP_Post ) {
			$nodes = array_merge( $nodes, EmbeddedSchema::nodes_for( $post ) );
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

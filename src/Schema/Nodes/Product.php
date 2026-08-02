<?php
/**
 * Product schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

use SeoWp\Schema\GraphBuilder;
use SeoWp\Social\OpenGraph;
use WC_Product;
use WP_Post;

/**
 * Builds the Product JSON-LD node. Only builds a node when WooCommerce is
 * active and the current request is a single product; safe to call
 * unconditionally otherwise.
 */
final class Product {

	/**
	 * Builds the node, or null when WooCommerce is not active, the current
	 * request is not a single product, or the product can't be loaded.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( ! function_exists( 'is_product' ) || ! function_exists( 'wc_get_product' ) || ! is_product() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		$url = (string) get_permalink( $post );

		$node = array(
			'@type'       => 'Product',
			'@id'         => $url . '#product',
			'name'        => $product->get_name(),
			'url'         => $url,
			'description' => wp_strip_all_tags( (string) $product->get_short_description() ),
		);

		$sku = $product->get_sku();
		if ( '' !== $sku ) {
			$node['sku'] = $sku;
		}

		// Same resolution as Open Graph (override -> featured/product image
		// -> site logo -> site icon), memoised there per request — so the
		// og:image override field also controls the schema image here.
		$image = ( new OpenGraph() )->image();
		if ( '' !== $image['url'] ) {
			$node['image'] = $image['url'];
		}

		$node['offers'] = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'price'         => (string) $product->get_price(),
			'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		);

		$review_count = (int) $product->get_review_count();
		if ( $review_count > 0 ) {
			$node['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (string) $product->get_average_rating(),
				'reviewCount' => $review_count,
			);
		}

		$type_override = GraphBuilder::schema_type_override( $post );
		if ( '' !== $type_override ) {
			$node['@type'] = $type_override;
		}

		return $node;
	}
}

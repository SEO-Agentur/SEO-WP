<?php
/**
 * Product schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

use SeoWp\Schema\GraphBuilder;
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

		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			$src = wp_get_attachment_image_src( $image_id, 'full' );
			if ( is_array( $src ) ) {
				$node['image'] = (string) $src[0];
			}
		}

		$node['offers'] = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'price'         => (string) $product->get_price(),
			'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		);

		$type_override = GraphBuilder::schema_type_override( $post );
		if ( '' !== $type_override ) {
			$node['@type'] = $type_override;
		}

		return $node;
	}
}

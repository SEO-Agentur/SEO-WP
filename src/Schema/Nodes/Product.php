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
 * unconditionally otherwise. Brand/GTIN/MPN/price-valid-until have no
 * reliable WooCommerce-core equivalent (core has no such fields), so
 * they're this plugin's own optional post meta, editable in the SEO-WP
 * metabox's Schema tab for products.
 */
final class Product {

	/**
	 * Post meta key for a manual brand name override.
	 */
	public const META_BRAND = '_seo_wp_product_brand';

	/**
	 * Post meta key for a GTIN (any length — 8/12/13/14 are all accepted
	 * as the single generic "gtin" property).
	 */
	public const META_GTIN = '_seo_wp_product_gtin';

	/**
	 * Post meta key for a manufacturer part number.
	 */
	public const META_MPN = '_seo_wp_product_mpn';

	/**
	 * Post meta key for the offer's priceValidUntil date (Y-m-d).
	 */
	public const META_PRICE_VALID_UNTIL = '_seo_wp_product_price_valid_until';

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
		// og:image override field also controls the primary schema image
		// here. WooCommerce's own gallery images are appended after it.
		$image      = ( new OpenGraph() )->image();
		$image_urls = self::merge_image_urls( $image['url'], self::gallery_urls( $product ) );
		if ( array() !== $image_urls ) {
			$node['image'] = $image_urls;
		}

		$brand = self::brand_node( (string) get_post_meta( $post->ID, self::META_BRAND, true ) );
		if ( null !== $brand ) {
			$node['brand'] = $brand;
		}

		$gtin = trim( (string) get_post_meta( $post->ID, self::META_GTIN, true ) );
		if ( '' !== $gtin ) {
			$node['gtin'] = $gtin;
		}

		$mpn = trim( (string) get_post_meta( $post->ID, self::META_MPN, true ) );
		if ( '' !== $mpn ) {
			$node['mpn'] = $mpn;
		}

		$node['offers'] = array(
			'@type'         => 'Offer',
			'url'           => $url,
			'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'price'         => (string) $product->get_price(),
			'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		);

		$price_valid_until = self::normalise_price_valid_until( (string) get_post_meta( $post->ID, self::META_PRICE_VALID_UNTIL, true ) );
		if ( '' !== $price_valid_until ) {
			$node['offers']['priceValidUntil'] = $price_valid_until;
		}

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

	/**
	 * Builds the Brand sub-node from a manual brand name, or null when
	 * empty. Pure, for easy unit testing.
	 *
	 * @param string $brand_name Manual brand name override.
	 * @return array{"@type":string,name:string}|null
	 */
	public static function brand_node( string $brand_name ): ?array {
		$brand_name = trim( $brand_name );

		return '' !== $brand_name
			? array(
				'@type' => 'Brand',
				'name'  => $brand_name,
			)
			: null;
	}

	/**
	 * Validates a priceValidUntil candidate: must be a real calendar date
	 * in Y-m-d form, or it's dropped rather than emitting a malformed
	 * date into the offer. Pure, for easy unit testing.
	 *
	 * @param string $date Candidate date string.
	 */
	public static function normalise_price_valid_until( string $date ): string {
		$date = trim( $date );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) ) {
			return '';
		}

		return checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ? $date : '';
	}

	/**
	 * Merges a primary image URL with a list of gallery URLs, dropping
	 * blanks and duplicates while keeping the primary image first. Pure,
	 * for easy unit testing.
	 *
	 * @param string   $primary Primary image URL, or '' for none.
	 * @param string[] $gallery Gallery image URLs.
	 * @return string[]
	 */
	public static function merge_image_urls( string $primary, array $gallery ): array {
		$urls = array();

		foreach ( array_merge( array( $primary ), $gallery ) as $candidate ) {
			$candidate = trim( (string) $candidate );
			if ( '' !== $candidate && ! in_array( $candidate, $urls, true ) ) {
				$urls[] = $candidate;
			}
		}

		return $urls;
	}

	/**
	 * Resolves a product's WooCommerce gallery image URLs.
	 *
	 * @param WC_Product $product Product to read the gallery from.
	 * @return string[]
	 */
	private static function gallery_urls( WC_Product $product ): array {
		$urls = array();

		foreach ( $product->get_gallery_image_ids() as $attachment_id ) {
			$image_url = wp_get_attachment_image_url( (int) $attachment_id, 'full' );
			if ( is_string( $image_url ) && '' !== $image_url ) {
				$urls[] = $image_url;
			}
		}

		return $urls;
	}
}

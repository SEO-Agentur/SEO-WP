<?php
/**
 * Organization schema node.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema\Nodes;

use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Schema\OpeningHours;

/**
 * Builds the Organization JSON-LD node: the site's publisher identity,
 * used unless Settings configures a Person identity instead. When
 * configured as a local business, adds address/geo/telephone/opening-
 * hours/price-range and swaps @type to the configured business type —
 * LocalBusiness (and its subtypes, e.g. Restaurant, Store) is itself an
 * Organization subtype in Schema.org's own hierarchy, so this stays one
 * node rather than a second, competing one.
 */
final class Organization {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Builds the node, or null when a Person identity is configured
	 * instead.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		$settings = get_option( self::OPTION_KEY, array() );

		$identity_type = (string) ( $settings['identity_type'] ?? 'organization' );
		if ( 'person' === $identity_type ) {
			return null;
		}

		$node = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#identity' ),
			'name'  => isset( $settings['identity_name'] ) && '' !== $settings['identity_name']
				? (string) $settings['identity_name']
				: (string) get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo = $this->logo_url();
		if ( '' !== $logo ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => $logo,
			);
		}

		if ( 'local_business' === $identity_type ) {
			$node = $this->add_local_business_fields( $node, $settings );
		}

		return $node;
	}

	/**
	 * Adds local-business-specific fields on top of the base Organization
	 * node, and swaps @type to the configured business type.
	 *
	 * @param array<string,mixed> $node     Node built so far.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<string,mixed>
	 */
	private function add_local_business_fields( array $node, array $settings ): array {
		$type = isset( $settings['local_business_type'] ) ? (string) $settings['local_business_type'] : '';
		if ( '' !== $type && GraphBuilder::is_valid_schema_type( $type ) ) {
			$node['@type'] = $type;
		} else {
			$node['@type'] = 'LocalBusiness';
		}

		$address = $this->address( $settings );
		if ( array() !== $address ) {
			$node['address'] = $address;
		}

		$geo = $this->geo( $settings );
		if ( null !== $geo ) {
			$node['geo'] = $geo;
		}

		$phone = isset( $settings['local_business_phone'] ) ? trim( (string) $settings['local_business_phone'] ) : '';
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}

		$price_range = isset( $settings['local_business_price_range'] ) ? trim( (string) $settings['local_business_price_range'] ) : '';
		if ( '' !== $price_range ) {
			$node['priceRange'] = $price_range;
		}

		$hours = OpeningHours::parse( isset( $settings['local_business_opening_hours'] ) ? (string) $settings['local_business_opening_hours'] : '' );
		if ( array() !== $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}

		return $node;
	}

	/**
	 * Builds the PostalAddress node from the configured fields, or an
	 * empty array when none are set.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return array<string,mixed>
	 */
	private function address( array $settings ): array {
		$map = array(
			'streetAddress'   => 'local_business_street_address',
			'addressLocality' => 'local_business_locality',
			'addressRegion'   => 'local_business_region',
			'postalCode'      => 'local_business_postal_code',
			'addressCountry'  => 'local_business_country',
		);

		$address = array();
		foreach ( $map as $schema_key => $settings_key ) {
			$value = isset( $settings[ $settings_key ] ) ? trim( (string) $settings[ $settings_key ] ) : '';
			if ( '' !== $value ) {
				$address[ $schema_key ] = $value;
			}
		}

		if ( array() === $address ) {
			return array();
		}

		return array_merge( array( '@type' => 'PostalAddress' ), $address );
	}

	/**
	 * Builds the GeoCoordinates node when both latitude and longitude are
	 * set and numeric, or null otherwise.
	 *
	 * @param array<string,mixed> $settings Plugin settings.
	 */
	private function geo( array $settings ): ?array {
		$lat = isset( $settings['local_business_latitude'] ) ? trim( (string) $settings['local_business_latitude'] ) : '';
		$lng = isset( $settings['local_business_longitude'] ) ? trim( (string) $settings['local_business_longitude'] ) : '';

		if ( '' === $lat || '' === $lng || ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}

		return array(
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) $lat,
			'longitude' => (float) $lng,
		);
	}

	/**
	 * Resolves the site logo URL from the active theme's custom logo, if
	 * any.
	 */
	private function logo_url(): string {
		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id <= 0 ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $logo_id, 'full' );

		return is_array( $src ) ? (string) $src[0] : '';
	}
}

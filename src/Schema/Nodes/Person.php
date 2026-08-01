<?php
/**
 * Person schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

/**
 * Builds the Person JSON-LD node, used as the site's publisher identity
 * instead of Organization when Settings (M7) configures a Person identity.
 */
final class Person {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Builds the node, or null when an Organization identity is configured
	 * (the default) or no name is available.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		$settings = get_option( self::OPTION_KEY, array() );

		if ( 'person' !== ( $settings['identity_type'] ?? 'organization' ) ) {
			return null;
		}

		$name = isset( $settings['identity_name'] ) ? trim( (string) $settings['identity_name'] ) : '';
		if ( '' === $name ) {
			return null;
		}

		return array(
			'@type' => 'Person',
			'@id'   => home_url( '/#identity' ),
			'name'  => $name,
			'url'   => home_url( '/' ),
		);
	}
}

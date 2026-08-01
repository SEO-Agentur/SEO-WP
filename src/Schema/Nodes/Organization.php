<?php
/**
 * Organization schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

/**
 * Builds the Organization JSON-LD node: the site's publisher identity,
 * used unless Settings (M7) configures a Person identity instead.
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

		if ( 'person' === ( $settings['identity_type'] ?? 'organization' ) ) {
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

		return $node;
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

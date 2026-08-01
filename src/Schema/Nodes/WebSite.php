<?php
/**
 * WebSite schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

/**
 * Builds the WebSite JSON-LD node.
 */
final class WebSite {

	/**
	 * Builds the node. Always present, on every front-end request.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		return array(
			'@type'       => 'WebSite',
			'@id'         => home_url( '/#website' ),
			'url'         => home_url( '/' ),
			'name'        => (string) get_bloginfo( 'name' ),
			'description' => (string) get_bloginfo( 'description' ),
			'inLanguage'  => str_replace( '_', '-', get_locale() ),
			'publisher'   => array( '@id' => home_url( '/#identity' ) ),
		);
	}
}

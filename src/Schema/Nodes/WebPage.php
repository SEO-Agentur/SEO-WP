<?php
/**
 * WebPage schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

use SeoWp\Support\Url;

/**
 * Builds the WebPage JSON-LD node for any indexable front-end request.
 */
final class WebPage {

	/**
	 * Builds the node, or null on search/404 pages.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( is_search() || is_404() ) {
			return null;
		}

		$url = $this->current_url();
		if ( '' === $url ) {
			return null;
		}

		return array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => wp_strip_all_tags( (string) wp_get_document_title() ),
			'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
			'inLanguage' => str_replace( '_', '-', get_locale() ),
		);
	}

	/**
	 * Resolves the current request's URL.
	 */
	private function current_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();
			return is_string( $permalink ) ? $permalink : '';
		}

		return Url::current();
	}
}

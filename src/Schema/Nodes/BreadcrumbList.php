<?php
/**
 * BreadcrumbList schema node.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema\Nodes;

use Seooptimierung\Toolkit\Support\Breadcrumbs;
use Seooptimierung\Toolkit\Support\Url;

/**
 * Builds the BreadcrumbList JSON-LD node from the same trail the visible
 * breadcrumbs show (Support\Breadcrumbs): home down to the current
 * object, through the posts page or archive, category chain or page
 * ancestors, for any public post type or taxonomy.
 */
final class BreadcrumbList {

	/**
	 * Builds the node, or null when there is nothing beyond the home page
	 * to list.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		$items = Breadcrumbs::items();

		if ( count( $items ) < 2 ) {
			return null;
		}

		$list = array();
		foreach ( $items as $index => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $index + 1,
				'name'     => $item['name'],
				'item'     => $item['url'],
			);
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => Url::current() . '#breadcrumb',
			'itemListElement' => $list,
		);
	}
}

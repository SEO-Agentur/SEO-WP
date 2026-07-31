<?php
/**
 * Collects and renders the plugin's wp_head output.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Frontend;

use SeoWp\Meta\Canonical;
use SeoWp\Meta\Robots;
use SeoWp\Meta\TitleDescription;

/**
 * Registers the head-rendering modules. Social, schema and hreflang
 * modules join this pipeline in milestones M2 and M4; fragment caching is
 * added in M4.
 */
final class Head {

	/**
	 * Registers every module that contributes to the document head.
	 */
	public function register(): void {
		( new TitleDescription() )->register();
		( new Robots() )->register();
		( new Canonical() )->register();
	}
}

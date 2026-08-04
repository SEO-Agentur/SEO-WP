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
use SeoWp\Schema\GraphBuilder;
use SeoWp\Social\OpenGraph;
use SeoWp\Social\TwitterCards;
use SeoWp\Support\FragmentCache;
use SeoWp\Wpml\Hreflang;

/**
 * Registers every module that contributes to the document head, including
 * the fragment cache the schema and hreflang modules are built on.
 */
final class Head {

	/**
	 * Registers every module that contributes to the document head.
	 */
	public function register(): void {
		( new FragmentCache() )->register();

		( new TitleDescription() )->register();
		( new Robots() )->register();
		( new Canonical() )->register();
		( new OpenGraph() )->register();
		( new TwitterCards() )->register();
		( new GraphBuilder() )->register();
		( new Hreflang() )->register();
		( new CriticalCss() )->register();
	}
}

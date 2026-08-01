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

/**
 * Registers the head-rendering modules. The hreflang module joins this
 * pipeline in milestone M4; fragment caching is added in M4.
 */
final class Head {

	/**
	 * Registers every module that contributes to the document head.
	 */
	public function register(): void {
		( new TitleDescription() )->register();
		( new Robots() )->register();
		( new Canonical() )->register();
		( new OpenGraph() )->register();
		( new TwitterCards() )->register();
		( new GraphBuilder() )->register();
	}
}

<?php
/**
 * Collects and renders the plugin's wp_head output.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Frontend;

use Seooptimierung\Toolkit\Meta\Canonical;
use Seooptimierung\Toolkit\Meta\Robots;
use Seooptimierung\Toolkit\Meta\TitleDescription;
use Seooptimierung\Toolkit\Schema\GraphBuilder;
use Seooptimierung\Toolkit\Social\OpenGraph;
use Seooptimierung\Toolkit\Social\TwitterCards;
use Seooptimierung\Toolkit\Support\FragmentCache;
use Seooptimierung\Toolkit\Wpml\Hreflang;

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

		( new ResourceHints() )->register();
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

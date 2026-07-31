<?php
/**
 * Collects and renders the plugin's wp_head output.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Frontend;

use EasyWordpressSeo\Meta\Canonical;
use EasyWordpressSeo\Meta\Robots;
use EasyWordpressSeo\Meta\TitleDescription;

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

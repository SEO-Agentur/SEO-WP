<?php
/**
 * Robots meta directive overrides.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Meta;

/**
 * Adds noindex/nofollow/noarchive/nosnippet/noimageindex directives on top
 * of WordPress core's wp_robots output, without duplicating what core
 * already sets.
 * Implemented in milestone M1.
 */
final class Robots {
}

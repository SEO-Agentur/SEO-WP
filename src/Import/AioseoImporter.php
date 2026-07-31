<?php
/**
 * Imports object data written by AIOSEO v4.
 *
 * @package EasyWordpressSeo
 */

declare(strict_types=1);

namespace EasyWordpressSeo\Import;

/**
 * Reads AIOSEO's custom wp_aioseo_posts table (not postmeta) and maps it
 * to this plugin's meta keys, non-destructively. robots_default(bool)
 * true means type defaults apply and individual robots columns are
 * ignored.
 * Implemented in milestone M6.
 */
final class AioseoImporter {
}

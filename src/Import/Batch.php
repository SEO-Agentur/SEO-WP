<?php
/**
 * Batched, cron/AJAX-driven import runner.
 *
 * @package SlimSeoWpml
 */

declare(strict_types=1);

namespace SlimSeoWpml\Import;

/**
 * Runs importers in batches with dry-run support and a log of what was
 * imported or skipped (e.g. redirects, which are never migrated).
 * Implemented in milestone M6.
 */
final class Batch {
}

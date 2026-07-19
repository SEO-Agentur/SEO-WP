<?php
/**
 * Imports taxonomy term SEO data from Yoast, Rank Math and AIOSEO.
 *
 * @package SlimSeoWpml
 */

declare(strict_types=1);

namespace SlimSeoWpml\Import;

/**
 * Reads Yoast's wpseo_taxonomy_meta option (taxonomy => term ID => fields,
 * not termmeta), Rank Math's termmeta, and AIOSEO's wp_aioseo_terms table.
 * Implemented in milestone M6.
 */
final class TermImporter {
}

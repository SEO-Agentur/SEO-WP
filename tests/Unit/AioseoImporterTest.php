<?php
/**
 * Tests for mapping a raw wp_aioseo_posts row to this plugin's meta keys.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Import\AioseoImporter;
use Seooptimierung\Toolkit\Meta\Canonical;
use Seooptimierung\Toolkit\Meta\Robots;
use Seooptimierung\Toolkit\Meta\TitleDescription;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Import\AioseoImporter
 */
final class AioseoImporterTest extends TestCase {

	/**
	 * Title, description and canonical map straight across.
	 */
	public function test_maps_title_description_and_canonical(): void {
		$result = AioseoImporter::map_row(
			array(
				'title'         => 'Custom Title',
				'description'   => 'Custom description.',
				'canonical_url' => 'https://example.com/canonical/',
			)
		);

		$this->assertSame( 'Custom Title', $result[ TitleDescription::META_TITLE ] );
		$this->assertSame( 'Custom description.', $result[ TitleDescription::META_DESCRIPTION ] );
		$this->assertSame( 'https://example.com/canonical/', $result[ Canonical::META_CANONICAL ] );
	}

	/**
	 * robots_default = true means the individual robots_* columns are
	 * ignored entirely, per AIOSEO's own documented behaviour.
	 */
	public function test_ignores_robots_columns_when_robots_default_is_true(): void {
		$result = AioseoImporter::map_row(
			array(
				'robots_default'  => 1,
				'robots_noindex'  => 1,
				'robots_nofollow' => 1,
			)
		);

		$this->assertArrayNotHasKey( Robots::META_NOINDEX, $result );
		$this->assertArrayNotHasKey( Robots::META_NOFOLLOW, $result );
	}

	/**
	 * robots_default = false means the individual robots_* columns are
	 * read.
	 */
	public function test_reads_robots_columns_when_robots_default_is_false(): void {
		$result = AioseoImporter::map_row(
			array(
				'robots_default'   => 0,
				'robots_noindex'   => 1,
				'robots_nofollow'  => 1,
				'robots_noarchive' => 1,
			)
		);

		$this->assertSame( '1', $result[ Robots::META_NOINDEX ] );
		$this->assertSame( '1', $result[ Robots::META_NOFOLLOW ] );
		$this->assertSame( array( 'noarchive' ), $result[ Robots::META_ADVANCED ] );
	}

	/**
	 * A missing robots_default column defaults to "use type defaults",
	 * the safer assumption when a row is incomplete.
	 */
	public function test_missing_robots_default_column_defaults_to_ignoring_robots(): void {
		$result = AioseoImporter::map_row(
			array(
				'robots_noindex' => 1,
			)
		);

		$this->assertArrayNotHasKey( Robots::META_NOINDEX, $result );
	}

	/**
	 * Empty/absent fields are simply omitted, never written as empty
	 * strings that would otherwise clear an existing Seooptimierung Toolkit value.
	 */
	public function test_empty_fields_are_omitted(): void {
		$result = AioseoImporter::map_row( array( 'title' => '' ) );

		$this->assertArrayNotHasKey( TitleDescription::META_TITLE, $result );
	}

	/**
	 * The schema type is extracted from the first graph node in the JSON
	 * schema column.
	 */
	public function test_extracts_schema_type_from_json(): void {
		$json = json_encode( array( 'graphs' => array( array( 'type' => 'Product' ) ) ) );

		$this->assertSame( 'Product', AioseoImporter::schema_type_from_json( $json ) );
	}

	/**
	 * Invalid or empty JSON yields an empty schema type rather than an
	 * error.
	 */
	public function test_invalid_json_yields_empty_schema_type(): void {
		$this->assertSame( '', AioseoImporter::schema_type_from_json( '' ) );
		$this->assertSame( '', AioseoImporter::schema_type_from_json( 'not json' ) );
	}
}

<?php
/**
 * Tests for the structured-data content types: JSON-LD nodes, markup and
 * the value helpers.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Schema\ContentTypes;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Schema\ContentTypes
 */
final class ContentTypesTest extends TestCase {

	/**
	 * Page facts as EmbeddedSchema passes them in.
	 *
	 * @return array<string,string>
	 */
	private function ctx(): array {
		return array(
			'url'       => 'https://example.com/pizza/',
			'title'     => 'Pizza',
			'author'    => 'Anna',
			'image'     => 'https://example.com/pizza.jpg',
			'published' => '2026-10-01T10:00:00+02:00',
			'modified'  => '2026-10-02T10:00:00+02:00',
		);
	}

	/**
	 * Every registered type has fields, and every field type is one the
	 * editor script can draw.
	 */
	public function test_registry_is_complete(): void {
		$known = array( 'text', 'textarea', 'number', 'date', 'datetime', 'url', 'select', 'toggle', 'image', 'list', 'qa' );

		foreach ( ContentTypes::all() as $slug => $definition ) {
			$this->assertNotSame( '', $definition['type'], $slug );
			$this->assertNotEmpty( $definition['fields'], $slug );
			foreach ( $definition['fields'] as $field ) {
				$this->assertContains( $field['type'], $known, $slug . '.' . $field['key'] );
			}
			$this->assertSame( array_keys( ContentTypes::block_attributes( $slug ) ), array_column( $definition['fields'], 'key' ) );
		}
	}

	/**
	 * Durations and dates become valid ISO 8601.
	 */
	public function test_iso_helpers(): void {
		$this->assertSame( 'PT1H30M', ContentTypes::duration_from_minutes( 90 ) );
		$this->assertSame( 'PT45M', ContentTypes::duration_from_minutes( '45' ) );
		$this->assertSame( 'PT2H', ContentTypes::duration_from_minutes( 120 ) );
		$this->assertSame( '', ContentTypes::duration_from_minutes( 0 ) );
		$this->assertSame( 'PT1M30S', ContentTypes::duration_from_seconds( 90 ) );
		$this->assertSame( 'PT1H', ContentTypes::duration_from_seconds( 3600 ) );

		$berlin = new \DateTimeZone( 'Europe/Berlin' );
		$this->assertSame( '2026-10-09T18:30:00+02:00', ContentTypes::iso_datetime( '2026-10-09T18:30', $berlin ) );
		$this->assertSame( '2026-12-24T18:30:00+01:00', ContentTypes::iso_datetime( '2026-12-24T18:30', $berlin ) );
		$this->assertSame( '2026-10-09', ContentTypes::iso_datetime( '2026-10-09', $berlin ) );
		$this->assertSame( '', ContentTypes::iso_datetime( 'not a date', $berlin ) );
		$this->assertSame( '', ContentTypes::iso_datetime( '', $berlin ) );
	}

	/**
	 * A block with nothing filled in produces neither markup nor a node.
	 */
	public function test_empty_blocks_produce_nothing(): void {
		$this->assertSame( '', ContentTypes::html( 'recipe', array() ) );
		$this->assertNull( ContentTypes::node( 'recipe', array( 'ingredients' => array( '  ', '' ) ), $this->ctx() ) );
		$this->assertSame( array(), ContentTypes::faq_entities( array( 'items' => array( array( 'question' => 'Q?', 'answer' => '' ) ) ) ) );
	}

	/**
	 * A recipe: total time is prep plus cook, the page image and author
	 * fill in, instructions become HowToSteps, and the node points back at
	 * the page.
	 */
	public function test_recipe_node(): void {
		$node = ContentTypes::node(
			'recipe',
			array(
				'name'        => 'Pizza Margherita',
				'prepTime'    => '20',
				'cookTime'    => '10',
				'recipeYield' => '2 pizzas',
				'ingredients' => array( 'Dough', '  Tomatoes ', '' ),
				'steps'       => array( 'Roll out the dough.', 'Bake.' ),
			),
			$this->ctx()
		);

		$this->assertSame( 'Recipe', $node['@type'] );
		$this->assertSame( 'https://example.com/pizza/#recipe', $node['@id'] );
		$this->assertSame( array( '@id' => 'https://example.com/pizza/#webpage' ), $node['mainEntityOfPage'] );
		$this->assertSame( 'PT20M', $node['prepTime'] );
		$this->assertSame( 'PT30M', $node['totalTime'] );
		$this->assertSame( array( 'Dough', 'Tomatoes' ), $node['recipeIngredient'] );
		$this->assertSame( 'HowToStep', $node['recipeInstructions'][1]['@type'] );
		$this->assertSame( array( 'https://example.com/pizza.jpg' ), $node['image'] );
		$this->assertSame( 'Anna', $node['author']['name'] );
		$this->assertArrayNotHasKey( 'nutrition', $node );
	}

	/**
	 * A second block of the same kind gets its own @id.
	 */
	public function test_second_block_gets_a_numbered_id(): void {
		$attrs = array( 'name' => 'Course A', 'provider' => 'Uni' );

		$this->assertSame( 'https://example.com/pizza/#course', ContentTypes::node( 'course', $attrs, $this->ctx(), 1 )['@id'] );
		$this->assertSame( 'https://example.com/pizza/#course-2', ContentTypes::node( 'course', $attrs, $this->ctx(), 2 )['@id'] );
	}

	/**
	 * An event: ISO dates with the site's offset, enum URLs, a venue and
	 * an offer.
	 */
	public function test_event_node(): void {
		$node = ContentTypes::node(
			'event',
			array(
				'name'           => 'Workshop',
				'startDate'      => '2026-11-05T18:00',
				'status'         => 'EventScheduled',
				'attendanceMode' => 'OfflineEventAttendanceMode',
				'locationName'   => 'Stadthalle',
				'address'        => 'Hauptstr. 1, Berlin',
				'price'          => '25',
				'currency'       => 'eur',
			),
			$this->ctx()
		);

		$this->assertSame( '2026-11-05T18:00:00+01:00', $node['startDate'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $node['eventStatus'] );
		$this->assertSame( 'https://schema.org/OfflineEventAttendanceMode', $node['eventAttendanceMode'] );
		$this->assertSame( 'Place', $node['location']['@type'] );
		$this->assertSame( 'EUR', $node['offers']['priceCurrency'] );
	}

	/**
	 * A job posting: a remote job gets TELECOMMUTE and no jobLocation
	 * without an address; a salary range becomes a MonetaryAmount.
	 */
	public function test_job_posting_node(): void {
		$node = ContentTypes::node(
			'job-posting',
			array(
				'title'          => 'Developer',
				'description'    => 'Build things.',
				'datePosted'     => '2026-10-01',
				'company'        => 'Acme',
				'remote'         => true,
				'country'        => 'de',
				'salaryMin'      => '50000',
				'salaryMax'      => '70000',
				'salaryCurrency' => 'eur',
				'salaryUnit'     => 'YEAR',
				'employmentType' => 'FULL_TIME',
			),
			$this->ctx()
		);

		$this->assertSame( 'JobPosting', $node['@type'] );
		$this->assertSame( 'TELECOMMUTE', $node['jobLocationType'] );
		$this->assertSame( 'DE', $node['applicantLocationRequirements']['name'] );
		$this->assertArrayNotHasKey( 'jobLocation', $node );
		$this->assertSame( 'EUR', $node['baseSalary']['currency'] );
		$this->assertSame( 50000.0, $node['baseSalary']['value']['minValue'] );
		$this->assertSame( '2026-10-01', $node['datePosted'] );
	}

	/**
	 * A live stream adds a BroadcastEvent publication.
	 */
	public function test_video_live_stream_has_broadcast_event(): void {
		$node = ContentTypes::node(
			'video',
			array(
				'name'      => 'Launch',
				'embedUrl'  => 'https://www.youtube.com/embed/abc',
				'isLive'    => true,
				'liveStart' => '2026-11-05T18:00',
				'duration'  => '90',
			),
			$this->ctx()
		);

		$this->assertSame( 'VideoObject', $node['@type'] );
		$this->assertSame( 'BroadcastEvent', $node['publication']['@type'] );
		$this->assertTrue( $node['publication']['isLiveBroadcast'] );
		$this->assertSame( 'PT1M30S', $node['duration'] );
	}

	/**
	 * Ratings of a software block appear only with both a value and a
	 * count — never a made-up rating.
	 */
	public function test_software_rating_needs_value_and_count(): void {
		$without = ContentTypes::node( 'software-application', array( 'name' => 'App', 'ratingValue' => '4.5' ), $this->ctx() );
		$with    = ContentTypes::node( 'software-application', array( 'name' => 'App', 'ratingValue' => '4.5', 'ratingCount' => '120' ), $this->ctx() );

		$this->assertArrayNotHasKey( 'aggregateRating', $without );
		$this->assertSame( 120, $with['aggregateRating']['ratingCount'] );
	}

	/**
	 * Visible markup carries the same facts and is escaped.
	 */
	public function test_markup_is_visible_and_escaped(): void {
		$html = ContentTypes::html(
			'recipe',
			array(
				'name'        => 'Fish & Chips <script>x</script>',
				'ingredients' => array( 'Fish', 'Potatoes' ),
				'steps'       => array( 'Fry.' ),
				'prepTime'    => '75',
			)
		);

		$this->assertStringContainsString( 'seooptimierung-schema--recipe', $html );
		$this->assertStringContainsString( 'Fish &amp; Chips', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '<li>Potatoes</li>', $html );
		$this->assertStringContainsString( '1 h 15 min', $html );
	}

	/**
	 * FAQ questions become Question/Answer entities.
	 */
	public function test_faq_entities(): void {
		$entities = ContentTypes::faq_entities(
			array(
				'items' => array(
					array( 'question' => 'Is it free?', 'answer' => 'Yes.' ),
					array( 'question' => '', 'answer' => 'orphan' ),
				),
			)
		);

		$this->assertCount( 1, $entities );
		$this->assertSame( 'Question', $entities[0]['@type'] );
		$this->assertSame( 'Yes.', $entities[0]['acceptedAnswer']['text'] );
	}

	/**
	 * The shortcode fallback maps flat attributes onto block attributes.
	 */
	public function test_shortcode_attributes(): void {
		$faq = ContentTypes::attributes_from_shortcode( 'faq', array( 'q1' => 'A?', 'a1' => 'B', 'q2' => 'C?', 'a2' => 'D' ) );
		$this->assertCount( 2, $faq['items'] );

		$howto = ContentTypes::attributes_from_shortcode( 'howto', array( 'name' => 'Fix it', 'steps' => 'One | Two |Three' ) );
		$this->assertSame( array( 'One', 'Two', 'Three' ), $howto['steps'] );
	}
}

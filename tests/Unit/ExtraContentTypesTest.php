<?php
/**
 * Tests for the declared structured-data content types (product, review,
 * Q&A page, ...): the generic JSON-LD builder and the generic markup.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Schema\ContentTypes;
use Seooptimierung\Toolkit\Schema\ExtraContentTypes;
use Seooptimierung\Toolkit\Schema\GraphBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Schema\ExtraContentTypes
 * @covers \Seooptimierung\Toolkit\Schema\ContentTypes
 */
final class ExtraContentTypesTest extends TestCase {

	/**
	 * Page facts as EmbeddedSchema passes them in.
	 *
	 * @return array<string,string>
	 */
	private function ctx(): array {
		return array(
			'url'       => 'https://example.com/page/',
			'title'     => 'Page title',
			'author'    => 'Anna',
			'image'     => 'https://example.com/page.jpg',
			'published' => '2026-10-01T10:00:00+02:00',
			'modified'  => '2026-10-02T10:00:00+02:00',
		);
	}

	/**
	 * The new types are part of the registry next to the coded ones.
	 */
	public function test_registry_contains_the_declared_types(): void {
		$all = ContentTypes::all();

		$this->assertGreaterThanOrEqual( 42, count( $all ) );
		foreach ( array_keys( ExtraContentTypes::all() ) as $slug ) {
			$this->assertArrayHasKey( $slug, $all );
			$this->assertArrayHasKey( 'build', $all[ $slug ], $slug );
		}
		foreach ( array( 'faq', 'recipe', 'video', 'product', 'review', 'qa-page' ) as $slug ) {
			$this->assertArrayHasKey( $slug, $all );
		}
	}

	/**
	 * Every property of every build map is something the builder knows, and
	 * every field a rule reads exists in the type's field list.
	 */
	public function test_build_maps_only_read_existing_fields(): void {
		$kinds = array( 'text', 'price', 'url', 'image', 'date', 'datetime', 'minutes', 'seconds', 'number', 'posint', 'itemcount', 'list', 'enum', 'boolenum', 'bool', 'upper', 'currency', 'type', 'countif', 'const', 'ctx', 'listitems', 'entities', 'entity', 'constif', 'urltemplate', 'imagelist', 'features', 'flashcards', 'enumlist', 'variants', 'menusections' );

		$check = function ( array $rule, array $keys, string $where ) use ( &$check, $kinds ): void {
			$kind = $rule[0];
			$this->assertContains( $kind, $kinds, $where );

			if ( 'entity' === $kind ) {
				if ( is_array( $rule[1] ) ) {
					$check( $rule[1], $keys, $where . ' (type)' );
				}
				foreach ( $rule[2] as $property => $sub ) {
					$check( $sub, $keys, $where . '.' . $property );
				}
				return;
			}
			if ( ! in_array( $kind, array( 'const', 'ctx' ), true ) ) {
				$this->assertContains( $rule[1], $keys, $where );
			}
			if ( in_array( $kind, array( 'imagelist', 'variants' ), true ) ) {
				$this->assertContains( $rule[2], $keys, $where . ' (second field)' );
			}
		};

		foreach ( ExtraContentTypes::all() as $slug => $definition ) {
			$keys = array_column( $definition['fields'], 'key' );
			foreach ( $definition['build'] as $property => $rule ) {
				$check( $rule, $keys, $slug . '.' . $property );
			}
		}
	}

	/**
	 * A product: offer with currency default and availability, rating only
	 * with both value and count.
	 */
	public function test_product_node(): void {
		$node = ContentTypes::node(
			'product',
			array(
				'name'         => 'Lamp',
				'brand'        => 'Lumo',
				'price'        => '49,90',
				'availability' => 'InStock',
				'ratingValue'  => '4.5',
				'ratingCount'  => '12',
			),
			$this->ctx()
		);

		$this->assertSame( 'Product', $node['@type'] );
		$this->assertSame( 'https://example.com/page/#product', $node['@id'] );
		$this->assertSame( 'Lumo', $node['brand']['name'] );
		$this->assertSame( 'Offer', $node['offers']['@type'] );
		$this->assertSame( '49.90', $node['offers']['price'] );
		$this->assertSame( 'EUR', $node['offers']['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $node['offers']['availability'] );
		$this->assertSame( 12, $node['aggregateRating']['ratingCount'] );
		$this->assertSame( array( 'https://example.com/page.jpg' ), $node['image'] );

		$no_count = ContentTypes::node( 'product', array( 'name' => 'Lamp', 'ratingValue' => '5' ), $this->ctx() );
		$this->assertArrayNotHasKey( 'aggregateRating', $no_count );
		$this->assertArrayNotHasKey( 'offers', $no_count );
	}

	/**
	 * A review: the reviewed item gets the type chosen in the editor, the
	 * best rating defaults to 5, the reviewer falls back to the page author.
	 */
	public function test_review_node(): void {
		$node = ContentTypes::node(
			'review',
			array(
				'name'        => 'Great',
				'itemType'    => 'Book',
				'itemName'    => 'Dune',
				'body'        => 'Loved it.',
				'ratingValue' => '4',
			),
			$this->ctx()
		);

		$this->assertSame( 'Review', $node['@type'] );
		$this->assertSame( 'Book', $node['itemReviewed']['@type'] );
		$this->assertSame( 'Dune', $node['itemReviewed']['name'] );
		$this->assertSame( 4, $node['reviewRating']['ratingValue'] );
		$this->assertSame( 5, $node['reviewRating']['bestRating'] );
		$this->assertSame( 'Anna', $node['author']['name'] );

		$bad = ContentTypes::node( 'review', array( 'itemType' => 'x</script>', 'itemName' => 'Y', 'body' => 'z' ), $this->ctx() );
		$this->assertSame( 'Thing', $bad['itemReviewed']['@type'], 'an unknown or invalid type falls back to Thing' );
		$this->assertArrayNotHasKey( 'reviewRating', $bad );
	}

	/**
	 * A Q&A page is a web page itself, so it does not claim to be the main
	 * entity of one, and an unanswered question has no accepted answer.
	 */
	public function test_qa_page_node(): void {
		$node = ContentTypes::node(
			'qa-page',
			array(
				'question' => 'How long does it take?',
				'answer'   => 'About a week.',
				'upvotes'  => '3',
			),
			$this->ctx()
		);

		$this->assertSame( 'QAPage', $node['@type'] );
		$this->assertArrayNotHasKey( 'mainEntityOfPage', $node );
		$this->assertSame( 'Question', $node['mainEntity']['@type'] );
		$this->assertSame( 1, $node['mainEntity']['answerCount'] );
		$this->assertSame( 3, $node['mainEntity']['acceptedAnswer']['upvoteCount'] );

		$open = ContentTypes::node( 'qa-page', array( 'question' => 'Why?' ), $this->ctx() );
		$this->assertSame( 0, $open['mainEntity']['answerCount'] );
		$this->assertArrayNotHasKey( 'acceptedAnswer', $open['mainEntity'] );
	}

	/**
	 * A fact check needs both the claim's author and a verdict to qualify.
	 */
	public function test_claim_review_node(): void {
		$node = ContentTypes::node(
			'claim-review',
			array(
				'claim'       => 'The moon is cheese.',
				'claimant'    => 'Someone',
				'verdict'     => 'False',
				'ratingValue' => '1',
			),
			$this->ctx()
		);

		$this->assertSame( 'ClaimReview', $node['@type'] );
		$this->assertSame( 'https://example.com/page/', $node['url'] );
		$this->assertSame( 'False', $node['reviewRating']['alternateName'] );
		$this->assertSame( 'Someone', $node['itemReviewed']['author']['name'] );

		$partial = ContentTypes::node( 'claim-review', array( 'claim' => 'X', 'claimant' => 'Y' ), $this->ctx() );
		$this->assertArrayNotHasKey( 'reviewRating', $partial );
	}

	/**
	 * Local business: the chosen subtype becomes the @type; coordinates are
	 * emitted only as a pair; the address is built from its parts.
	 */
	public function test_local_business_node(): void {
		$node = ContentTypes::node(
			'local-business',
			array(
				'name'          => 'Pizzeria',
				'businessType'  => 'Restaurant',
				'streetAddress' => 'Hauptstr. 1',
				'locality'      => 'Berlin',
				'country'       => 'de',
				'latitude'      => '52.52',
				'openingHours'  => array( 'Mo-Fr 09:00-18:00', '' ),
			),
			$this->ctx()
		);

		$this->assertSame( 'Restaurant', $node['@type'] );
		$this->assertSame( 'DE', $node['address']['addressCountry'] );
		$this->assertSame( array( 'Mo-Fr 09:00-18:00' ), $node['openingHours'] );
		$this->assertArrayNotHasKey( 'geo', $node );

		$geo = ContentTypes::node( 'local-business', array( 'name' => 'X', 'latitude' => '52.52', 'longitude' => '13,40' ), $this->ctx() );
		$this->assertSame( 13.4, $geo['geo']['longitude'] );
		$this->assertSame( 'LocalBusiness', $geo['@type'] );
	}

	/**
	 * A list of items becomes ListItems with positions; the address is optional.
	 */
	public function test_item_list_node(): void {
		$node = ContentTypes::node(
			'item-list',
			array(
				'name'    => 'Top 3',
				'ordered' => true,
				'items'   => array( 'Alpha | https://example.com/a/', 'Beta', ' ' ),
			),
			$this->ctx()
		);

		$this->assertSame( 'ItemList', $node['@type'] );
		$this->assertSame( 2, $node['numberOfItems'] );
		$this->assertSame( 'https://schema.org/ItemListOrderAscending', $node['itemListOrder'] );
		$this->assertSame( 1, $node['itemListElement'][0]['position'] );
		$this->assertSame( 'https://example.com/a/', $node['itemListElement'][0]['url'] );
		$this->assertArrayNotHasKey( 'url', $node['itemListElement'][1] );
	}

	/**
	 * Nested measurements: a property listing carries its floor size in
	 * square metres inside the "about" object.
	 */
	public function test_real_estate_node(): void {
		$node = ContentTypes::node(
			'real-estate-listing',
			array(
				'name'         => 'Bright flat',
				'propertyType' => 'Apartment',
				'price'        => '1200',
				'floorSize'    => '64',
				'rooms'        => '3',
				'locality'     => 'Hamburg',
			),
			$this->ctx()
		);

		$this->assertSame( 'RealEstateListing', $node['@type'] );
		$this->assertArrayNotHasKey( 'mainEntityOfPage', $node );
		$this->assertSame( 'Apartment', $node['about']['@type'] );
		$this->assertSame( 'MTK', $node['about']['floorSize']['unitCode'] );
		$this->assertSame( 3, $node['about']['numberOfRooms'] );
		$this->assertSame( 'Hamburg', $node['about']['address']['addressLocality'] );
	}

	/**
	 * Empty blocks give neither a node nor markup, and nothing entered by
	 * an editor can leave a tag or an unsafe address in the markup.
	 */
	public function test_empty_and_unsafe_input(): void {
		foreach ( array_keys( ExtraContentTypes::all() ) as $slug ) {
			$this->assertNull( ContentTypes::node( $slug, array(), $this->ctx() ), $slug );
			$this->assertSame( '', ContentTypes::html( $slug, array() ), $slug );
		}

		$html = ContentTypes::html(
			'product',
			array(
				'name'  => 'Lamp <script>alert(1)</script>',
				'url'   => 'javascript:alert(1)',
				'price' => '9',
			)
		);
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringContainsString( 'seooptimierung-schema--product', $html );
	}

	/**
	 * The markup shows what the node says: select labels, links, an audio
	 * player and a numbered, linked list.
	 */
	public function test_markup(): void {
		$list = ContentTypes::html( 'item-list', array( 'name' => 'Top', 'ordered' => true, 'items' => array( 'A | https://example.com/a/', 'B' ) ) );
		$this->assertStringContainsString( '<ol', $list );
		$this->assertStringContainsString( '<a href="https://example.com/a/">A</a>', $list );

		$podcast = ContentTypes::html( 'podcast-episode', array( 'name' => 'Ep 1', 'audioUrl' => 'https://example.com/ep1.mp3' ) );
		$this->assertStringContainsString( '<audio controls', $podcast );

		$image = ContentTypes::html( 'image-license', array( 'name' => 'Sunset', 'contentUrl' => 'https://example.com/s.jpg', 'license' => 'https://example.com/license/' ) );
		$this->assertStringContainsString( '<img src="https://example.com/s.jpg" alt="Sunset"', $image );
	}

	/**
	 * Shortcode attributes reach the new types too.
	 */
	public function test_shortcode_attributes_for_new_types(): void {
		$attrs = ContentTypes::attributes_from_shortcode( 'item-list', array( 'name' => 'Top', 'items' => 'A | https://e.com/a/ | B', 'ordered' => 'true' ) );

		$this->assertSame( 'Top', $attrs['name'] );
		$this->assertTrue( $attrs['ordered'] );
		$this->assertCount( 3, $attrs['items'] );
	}

	/**
	 * Typed prices become plain decimals; text that is not a number is dropped.
	 */
	public function test_price_normalisation(): void {
		$this->assertSame( '49.90', ExtraContentTypes::normalise_price( '49,90' ) );
		$this->assertSame( '1299.00', ExtraContentTypes::normalise_price( '1.299,00' ) );
		$this->assertSame( '1299.00', ExtraContentTypes::normalise_price( '1,299.00' ) );
		$this->assertSame( '49', ExtraContentTypes::normalise_price( '€ 49' ) );
		$this->assertSame( '0', ExtraContentTypes::normalise_price( '0' ) );
		$this->assertSame( '', ExtraContentTypes::normalise_price( 'on request' ) );
		$this->assertSame( '', ExtraContentTypes::normalise_price( '' ) );
	}

	/**
	 * A holiday rental: main image first, then the others without duplicates;
	 * the accommodation carries guests, rooms, size and amenities.
	 */
	public function test_vacation_rental_node(): void {
		$node = ContentTypes::node(
			'vacation-rental',
			array(
				'name'              => 'Sea view flat',
				'image'             => 'https://example.com/1.jpg',
				'moreImages'        => array( 'https://example.com/2.jpg', 'https://example.com/1.jpg', 'javascript:alert(1)' ),
				'accommodationType' => 'EntirePlace',
				'maxGuests'         => '4',
				'bedrooms'          => '2',
				'bathrooms'         => '1',
				'floorSize'         => '55',
				'amenities'         => array( 'Wi-Fi', 'Pool' ),
				'latitude'          => '54.1',
				'longitude'         => '10.5',
				'locality'          => 'Kiel',
			),
			$this->ctx()
		);

		$this->assertSame( 'VacationRental', $node['@type'] );
		$this->assertSame( array( 'https://example.com/1.jpg', 'https://example.com/2.jpg' ), $node['image'] );
		$this->assertSame( 'EntirePlace', $node['containsPlace']['additionalType'] );
		$this->assertSame( 4, $node['containsPlace']['occupancy']['value'] );
		$this->assertSame( 2, $node['containsPlace']['numberOfBedrooms'] );
		$this->assertSame( 'Wi-Fi', $node['containsPlace']['amenityFeature'][0]['name'] );
		$this->assertTrue( $node['containsPlace']['amenityFeature'][0]['value'] );
		$this->assertSame( 54.1, $node['latitude'] );
	}

	/**
	 * Flashcards need both a front and a back.
	 */
	public function test_quiz_node(): void {
		$node = ContentTypes::node(
			'quiz',
			array(
				'name'    => 'Cells',
				'subject' => 'Biology',
				'cards'   => array( 'Powerhouse of the cell? | Mitochondria', 'Only a front', ' | only a back' ),
			),
			$this->ctx()
		);

		$this->assertSame( 'Quiz', $node['@type'] );
		$this->assertSame( 'Biology', $node['about']['name'] );
		$this->assertCount( 1, $node['hasPart'] );
		$this->assertSame( 'Flashcard', $node['hasPart'][0]['eduQuestionType'] );
		$this->assertSame( 'Mitochondria', $node['hasPart'][0]['acceptedAnswer']['text'] );
	}

	/**
	 * A maths solver has two types; the address pattern is only accepted
	 * as a web address, with its {placeholder}.
	 */
	public function test_math_solver_node(): void {
		$node = ContentTypes::node(
			'math-solver',
			array(
				'name'          => 'Solver',
				'url'           => 'https://example.com/solver/',
				'target'        => 'https://example.com/solve?q={math_expression_string}',
				'questionTypes' => array( 'Derivative' ),
			),
			$this->ctx()
		);

		$this->assertSame( array( 'MathSolver', 'LearningResource' ), $node['@type'] );
		$this->assertSame( 'https://example.com/solve?q={math_expression_string}', $node['potentialAction']['target'] );
		$this->assertSame( 'required name=math_expression_string', $node['potentialAction']['mathExpression-input'] );

		$bad = ContentTypes::node( 'math-solver', array( 'name' => 'S', 'target' => 'javascript:alert({math_expression_string})' ), $this->ctx() );
		$this->assertArrayNotHasKey( 'potentialAction', $bad );
	}

	/**
	 * A profile page is a web page with the person as its main entity.
	 */
	public function test_profile_page_node(): void {
		$node = ContentTypes::node(
			'profile-page',
			array(
				'name'      => 'Anna',
				'sameAs'    => array( 'https://example.com/anna' ),
				'followers' => '120',
			),
			$this->ctx()
		);

		$this->assertSame( 'ProfilePage', $node['@type'] );
		$this->assertArrayNotHasKey( 'mainEntityOfPage', $node );
		$this->assertSame( 'Person', $node['mainEntity']['@type'] );
		$this->assertSame( 120, $node['mainEntity']['interactionStatistic']['userInteractionCount'] );
	}

	/**
	 * Estimated salary needs the median; without it there is no salary node.
	 */
	public function test_estimated_salary_node(): void {
		$node = ContentTypes::node(
			'estimated-salary',
			array(
				'name'     => 'Nurse',
				'location' => 'Hamburg',
				'period'   => 'P1Y',
				'median'   => '48000',
				'percentile10' => '38000',
			),
			$this->ctx()
		);

		$this->assertSame( 'Occupation', $node['@type'] );
		$this->assertSame( 'City', $node['occupationLocation']['@type'] );
		$this->assertSame( 48000, $node['estimatedSalary']['median'] );
		$this->assertSame( 'P1Y', $node['estimatedSalary']['duration'] );
		$this->assertSame( 'EUR', $node['estimatedSalary']['currency'] );

		$no_median = ContentTypes::node( 'estimated-salary', array( 'name' => 'Nurse', 'period' => 'P1Y', 'percentile10' => '1' ), $this->ctx() );
		$this->assertArrayNotHasKey( 'estimatedSalary', $no_median );
	}

	/**
	 * Variants: the fifth column fills the first "varies by" property.
	 */
	public function test_product_group_node(): void {
		$node = ContentTypes::node(
			'product-group',
			array(
				'name'         => 'T-shirt',
				'groupId'      => 'TS-1',
				'variesBy'     => array( 'color', 'size' ),
				'currency'     => 'eur',
				'availability' => 'OutOfStock',
				'variants'     => array(
					'T-shirt red | TS-R | 19,90 | https://example.com/r/ | Red',
					'T-shirt blue | TS-B',
					' | no name',
				),
			),
			$this->ctx()
		);

		$this->assertSame( 'ProductGroup', $node['@type'] );
		$this->assertSame( array( 'https://schema.org/color', 'https://schema.org/size' ), $node['variesBy'] );
		$this->assertCount( 2, $node['hasVariant'] );
		$this->assertSame( 'Red', $node['hasVariant'][0]['color'] );
		$this->assertSame( '19.90', $node['hasVariant'][0]['offers']['price'] );
		$this->assertSame( 'https://schema.org/OutOfStock', $node['hasVariant'][0]['offers']['availability'] );
		$this->assertArrayNotHasKey( 'offers', $node['hasVariant'][1] );
	}

	/**
	 * Paywall markup is a patch: it needs a selector, is not a node of its
	 * own, and lands on the Article (or else the WebPage).
	 */
	public function test_paywall_patch(): void {
		$patch = ContentTypes::node( 'paywall', array( 'cssSelector' => '.paywall' ), $this->ctx() );

		$this->assertTrue( $patch['@patch'] );
		$this->assertArrayNotHasKey( '@id', $patch );
		$this->assertFalse( $patch['isAccessibleForFree'] );
		$this->assertSame( '.paywall', $patch['hasPart']['cssSelector'] );
		$this->assertFalse( $patch['hasPart']['isAccessibleForFree'] );

		$nodes = GraphBuilder::apply_patches(
			array(
				array( '@type' => 'WebPage', '@id' => 'https://example.com/page/#webpage' ),
				array( '@type' => 'Article', '@id' => 'https://example.com/page/#article', 'headline' => 'H' ),
				$patch,
			)
		);

		$this->assertCount( 2, $nodes );
		$this->assertFalse( $nodes[1]['isAccessibleForFree'] );
		$this->assertSame( 'H', $nodes[1]['headline'] );
		$this->assertArrayNotHasKey( 'isAccessibleForFree', $nodes[0] );
		$this->assertArrayNotHasKey( '@patch', $nodes[1] );

		$page_only = GraphBuilder::apply_patches( array( array( '@type' => 'WebPage', '@id' => 'https://example.com/page/#webpage' ), $patch ) );
		$this->assertFalse( $page_only[0]['isAccessibleForFree'] );

		$orphan = GraphBuilder::apply_patches( array( $patch ) );
		$this->assertSame( array(), $orphan, 'a patch with nothing to patch is dropped' );

		$this->assertNull( ContentTypes::node( 'paywall', array(), $this->ctx() ) );
		$this->assertArrayNotHasKey( 'hasPart', ContentTypes::node( 'paywall', array( 'notice' => 'Members only' ), $this->ctx() ) );
	}

	/**
	 * A menu groups its dishes by section, in order of appearance.
	 */
	public function test_menu_node(): void {
		$node = ContentTypes::node(
			'menu',
			array(
				'name'   => 'Dinner',
				'dishes' => array(
					'Starters | Soup | 6,50 | Tomato',
					'Mains | Pasta | 12',
					'Starters | Salad',
					'Desserts',
				),
			),
			$this->ctx()
		);

		$this->assertSame( 'Menu', $node['@type'] );
		$this->assertCount( 2, $node['hasMenuSection'] );
		$this->assertSame( 'Starters', $node['hasMenuSection'][0]['name'] );
		$this->assertCount( 2, $node['hasMenuSection'][0]['hasMenuItem'] );
		$this->assertSame( '6.50', $node['hasMenuSection'][0]['hasMenuItem'][0]['offers']['price'] );
		$this->assertSame( 'Tomato', $node['hasMenuSection'][0]['hasMenuItem'][0]['description'] );
		$this->assertArrayNotHasKey( 'offers', $node['hasMenuSection'][0]['hasMenuItem'][1] );
	}

	/**
	 * The simpler content types: series, trip, sports event, certificate,
	 * paper and law each produce their own type with their own properties.
	 */
	public function test_remaining_types(): void {
		$series = ContentTypes::node( 'tv-series', array( 'name' => 'Show', 'seasons' => '3', 'actors' => array( 'A', 'B' ), 'startDate' => '2020-05-01' ), $this->ctx() );
		$this->assertSame( 'TVSeries', $series['@type'] );
		$this->assertSame( 3, $series['numberOfSeasons'] );
		$this->assertCount( 2, $series['actor'] );

		$trip = ContentTypes::node( 'trip', array( 'name' => 'Harbour tour', 'stops' => array( 'Pier | https://example.com/pier/', 'Lighthouse' ), 'price' => '25', 'provider' => 'Tours Ltd' ), $this->ctx() );
		$this->assertSame( 'TouristTrip', $trip['@type'] );
		$this->assertSame( 'ItemList', $trip['itinerary']['@type'] );
		$this->assertCount( 2, $trip['itinerary']['itemListElement'] );
		$this->assertSame( '25', $trip['offers']['price'] );

		$match = ContentTypes::node( 'sports-event', array( 'name' => 'Final', 'homeTeam' => 'A', 'awayTeam' => 'B', 'startDate' => '2026-11-05T18:00', 'locationName' => 'Arena' ), $this->ctx() );
		$this->assertSame( 'SportsEvent', $match['@type'] );
		$this->assertSame( 'SportsTeam', $match['homeTeam']['@type'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $match['eventStatus'] );

		$cert = ContentTypes::node( 'certification', array( 'name' => 'ISO 9001', 'issuedBy' => 'TÜV', 'identification' => '123', 'expires' => '2027-01-01' ), $this->ctx() );
		$this->assertSame( 'Certification', $cert['@type'] );
		$this->assertSame( 'TÜV', $cert['issuedBy']['name'] );
		$this->assertSame( '123', $cert['certificationIdentification'] );

		$paper = ContentTypes::node( 'scholarly-article', array( 'name' => 'On cells', 'authors' => array( 'A. Smith', 'B. Jones' ), 'journal' => 'Nature', 'doi' => '10.1000/x1' ), $this->ctx() );
		$this->assertSame( 'ScholarlyArticle', $paper['@type'] );
		$this->assertSame( 'On cells', $paper['headline'] );
		$this->assertCount( 2, $paper['author'] );
		$this->assertSame( 'Periodical', $paper['isPartOf']['@type'] );
		$this->assertSame( '10.1000/x1', $paper['identifier']['value'] );

		$law = ContentTypes::node( 'legislation', array( 'name' => 'Data Act', 'identifier' => 'X 1', 'legalForce' => 'InForce', 'legislationDate' => '2023-12-01' ), $this->ctx() );
		$this->assertSame( 'Legislation', $law['@type'] );
		$this->assertSame( 'https://schema.org/InForce', $law['legislationLegalForce'] );
		$this->assertSame( '2023-12-01', $law['legislationDate'] );
	}

	/**
	 * Flashcards and variants read as lists in the markup.
	 */
	public function test_markup_of_pair_lists(): void {
		$quiz = ContentTypes::html( 'quiz', array( 'name' => 'Cells', 'cards' => array( 'Front | Back' ) ) );
		$this->assertStringContainsString( '<strong>Front</strong> – Back', $quiz );

		$group = ContentTypes::html( 'product-group', array( 'name' => 'T-shirt', 'variants' => array( 'Red | TS-R | 19,90' ) ) );
		$this->assertStringContainsString( 'Red · TS-R · 19,90', $group );

		$paywall = ContentTypes::html( 'paywall', array( 'cssSelector' => '.paywall', 'notice' => 'For subscribers' ) );
		$this->assertStringContainsString( 'For subscribers', $paywall );
		$this->assertStringNotContainsString( '.paywall', $paywall, 'the selector is not shown to visitors' );
	}
}

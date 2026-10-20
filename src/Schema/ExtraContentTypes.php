<?php
/**
 * More structured-data content types, described as data.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

/**
 * The content types of ContentTypes that are declared rather than coded:
 * each one lists its editor fields and a "build" map — Schema.org property
 * => rule — and one generic builder turns the entered values into the
 * JSON-LD node and the visible markup. Adding a type means adding one
 * entry here.
 *
 * Rules (first element is the kind):
 *
 * - text, key[, ctx key]    plain text; falls back to a page fact (title, author, ...)
 * - url, key                a URL
 * - image, key[, ctx]       a list holding the image URL (page image as fallback)
 * - date / datetime, key    ISO 8601
 * - minutes / seconds, key  ISO 8601 duration
 * - number, key[, default]  a number
 * - posint, key             a whole number above zero
 * - list, key               a list of strings
 * - enum, key[, default]    a Schema.org enumeration URL
 * - bool, key               true when the toggle is on, otherwise left out
 * - boolenum, key, Name     an enumeration URL when the toggle is on
 * - price, key              a price as Google wants it: digits with a dot ("1299.00"), or left out
 * - upper, key              upper-case text (country codes)
 * - currency, key[, default]  upper-case currency code
 * - type, key, default      a Schema.org type name chosen in the editor
 * - countif, key            1 when the field has text, otherwise 0
 * - const, value            a fixed value
 * - listitems, key          ListItem entries from "Name | URL" lines
 * - itemcount, key          the number of entries of a list
 * - entities, key, Type     one {Type, name} entity per list entry
 * - entity, Type, props[, required props]   a nested object; left out when empty
 *
 * Because the markup and the node read the same values, what visitors see
 * and what search engines are told cannot drift apart.
 */
final class ExtraContentTypes {

	/**
	 * The declared types: slug => definition (see ContentTypes::all()).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$offer = self::offer_rule( true );
		$price = self::offer_rule( false );

		return array(
			'product'             => array(
				'type'        => 'Product',
				'title'       => __( 'Product (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A product with price and availability, shown on the page and marked up as Product.', 'seooptimierung-toolkit' ),
				'icon'        => 'cart',
				'fields'      => array(
					self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'brand', __( 'Brand', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'sku', __( 'SKU', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'gtin', __( 'GTIN / EAN', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'mpn', __( 'Manufacturer part number', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( '0 for free', 'seooptimierung-toolkit' ) ) ),
					self::f( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::f( 'availability', __( 'Availability', 'seooptimierung-toolkit' ), 'select', array( 'options' => self::availability_options() ) ),
					self::f( 'condition', __( 'Condition', 'seooptimierung-toolkit' ), 'select', array( 'options' => self::condition_options() ) ),
					self::f( 'url', __( 'Buy link', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'ratingValue', __( 'Average rating', 'seooptimierung-toolkit' ), 'number', array( 'help' => self::rating_help() ) ),
					self::f( 'ratingCount', __( 'Number of ratings', 'seooptimierung-toolkit' ), 'number' ),
				),
				'build'       => array(
					'@type'           => array( 'const', 'Product' ),
					'name'            => array( 'text', 'name', 'title' ),
					'description'     => array( 'text', 'description' ),
					'image'           => array( 'image', 'image', 'ctx' ),
					'brand'           => array( 'entity', 'Brand', array( 'name' => array( 'text', 'brand' ) ) ),
					'sku'             => array( 'text', 'sku' ),
					'gtin'            => array( 'text', 'gtin' ),
					'mpn'             => array( 'text', 'mpn' ),
					'offers'          => $offer,
					'aggregateRating' => array(
						'entity',
						'AggregateRating',
						array(
							'ratingValue' => array( 'number', 'ratingValue' ),
							'ratingCount' => array( 'posint', 'ratingCount' ),
						),
						array( 'ratingValue', 'ratingCount' ),
					),
				),
			),
			'review'              => array(
				'type'        => 'Review',
				'title'       => __( 'Review (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'Your own review of a product, book, film or business, marked up as Review.', 'seooptimierung-toolkit' ),
				'icon'        => 'star-filled',
				'fields'      => array(
					self::f( 'name', __( 'Headline', 'seooptimierung-toolkit' ), 'text' ),
					self::f(
						'itemType',
						__( 'What is reviewed', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'Product'             => __( 'Product', 'seooptimierung-toolkit' ),
								'Book'                => __( 'Book', 'seooptimierung-toolkit' ),
								'Movie'               => __( 'Movie', 'seooptimierung-toolkit' ),
								'Restaurant'          => __( 'Restaurant', 'seooptimierung-toolkit' ),
								'LocalBusiness'       => __( 'Local business', 'seooptimierung-toolkit' ),
								'Organization'        => __( 'Organization', 'seooptimierung-toolkit' ),
								'SoftwareApplication' => __( 'Software', 'seooptimierung-toolkit' ),
								'Course'              => __( 'Course', 'seooptimierung-toolkit' ),
								'Event'               => __( 'Event', 'seooptimierung-toolkit' ),
								'Recipe'              => __( 'Recipe', 'seooptimierung-toolkit' ),
								'Thing'               => __( 'Other', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::f( 'itemName', __( 'Name of the reviewed item', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'body', __( 'Review text', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'author', __( 'Reviewer', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'ratingValue', __( 'Rating', 'seooptimierung-toolkit' ), 'number', array( 'help' => __( 'Your own verdict for this item, for example 4 out of 5.', 'seooptimierung-toolkit' ) ) ),
					self::f( 'bestRating', __( 'Best possible rating', 'seooptimierung-toolkit' ), 'number', array( 'placeholder' => '5' ) ),
				),
				'build'       => array(
					'@type'         => array( 'const', 'Review' ),
					'name'          => array( 'text', 'name' ),
					'reviewBody'    => array( 'text', 'body' ),
					'datePublished' => array( 'date', 'datePublished' ),
					'author'        => array( 'entity', 'Person', array( 'name' => array( 'text', 'author', 'author' ) ) ),
					'reviewRating'  => array(
						'entity',
						'Rating',
						array(
							'ratingValue' => array( 'number', 'ratingValue' ),
							'bestRating'  => array( 'number', 'bestRating', 5 ),
						),
						array( 'ratingValue' ),
					),
					'itemReviewed'  => array( 'entity', array( 'type', 'itemType', 'Thing' ), array( 'name' => array( 'text', 'itemName' ) ), array( 'name' ) ),
				),
			),
			'book'                => array(
				'type'        => 'Book',
				'title'       => __( 'Book (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A book with author and publisher, marked up as Book.', 'seooptimierung-toolkit' ),
				'icon'        => 'book',
				'fields'      => array(
					self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Cover', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'author', __( 'Author', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'isbn', __( 'ISBN', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'publisher', __( 'Publisher', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'pages', __( 'Number of pages', 'seooptimierung-toolkit' ), 'number' ),
					self::f(
						'bookFormat',
						__( 'Format', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'Hardcover'       => __( 'Hardcover', 'seooptimierung-toolkit' ),
								'Paperback'       => __( 'Paperback', 'seooptimierung-toolkit' ),
								'EBook'           => __( 'E-book', 'seooptimierung-toolkit' ),
								'AudiobookFormat' => __( 'Audiobook', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::f( 'language', __( 'Language (code)', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'de' ) ),
					self::f( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::f( 'url', __( 'Buy link', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'         => array( 'const', 'Book' ),
					'name'          => array( 'text', 'name', 'title' ),
					'description'   => array( 'text', 'description' ),
					'image'         => array( 'image', 'image' ),
					'author'        => array( 'entity', 'Person', array( 'name' => array( 'text', 'author' ) ) ),
					'isbn'          => array( 'text', 'isbn' ),
					'publisher'     => array( 'entity', 'Organization', array( 'name' => array( 'text', 'publisher' ) ) ),
					'datePublished' => array( 'date', 'datePublished' ),
					'numberOfPages' => array( 'posint', 'pages' ),
					'bookFormat'    => array( 'enum', 'bookFormat' ),
					'inLanguage'    => array( 'text', 'language' ),
					'offers'        => $price,
				),
			),
			'movie'               => array(
				'type'        => 'Movie',
				'title'       => __( 'Movie (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A film with director and cast, marked up as Movie.', 'seooptimierung-toolkit' ),
				'icon'        => 'video-alt2',
				'fields'      => array(
					self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Poster', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'director', __( 'Director', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'actors', __( 'Cast', 'seooptimierung-toolkit' ), 'list' ),
					self::f( 'datePublished', __( 'Release date', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'duration', __( 'Length (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'genre', __( 'Genre', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'contentRating', __( 'Age rating', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'FSK 12' ) ),
				),
				'build'       => array(
					'@type'         => array( 'const', 'Movie' ),
					'name'          => array( 'text', 'name', 'title' ),
					'description'   => array( 'text', 'description' ),
					'image'         => array( 'image', 'image' ),
					'director'      => array( 'entity', 'Person', array( 'name' => array( 'text', 'director' ) ) ),
					'actor'         => array( 'entities', 'actors', 'Person' ),
					'datePublished' => array( 'date', 'datePublished' ),
					'duration'      => array( 'minutes', 'duration' ),
					'genre'         => array( 'text', 'genre' ),
					'contentRating' => array( 'text', 'contentRating' ),
				),
			),
			'person'              => array(
				'type'        => 'Person',
				'title'       => __( 'Person (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A person with job title and profiles, marked up as Person.', 'seooptimierung-toolkit' ),
				'icon'        => 'admin-users',
				'fields'      => array(
					self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'jobTitle', __( 'Job title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Photo', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'worksFor', __( 'Employer', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'url', __( 'Website', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'sameAs', __( 'Profiles on other sites', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One address per entry, for example a LinkedIn or Wikipedia page.', 'seooptimierung-toolkit' ) ) ),
					self::f( 'knowsAbout', __( 'Topics', 'seooptimierung-toolkit' ), 'list' ),
					self::f( 'email', __( 'Email', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'telephone', __( 'Phone', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'locality', __( 'City', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'country', __( 'Country (2-letter code)', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'DE' ) ),
				),
				'build'       => array(
					'@type'       => array( 'const', 'Person' ),
					'name'        => array( 'text', 'name' ),
					'jobTitle'    => array( 'text', 'jobTitle' ),
					'description' => array( 'text', 'description' ),
					'image'       => array( 'url', 'image' ),
					'worksFor'    => array( 'entity', 'Organization', array( 'name' => array( 'text', 'worksFor' ) ) ),
					'url'         => array( 'url', 'url' ),
					'sameAs'      => array( 'list', 'sameAs' ),
					'knowsAbout'  => array( 'list', 'knowsAbout' ),
					'email'       => array( 'text', 'email' ),
					'telephone'   => array( 'text', 'telephone' ),
					'address'     => array(
						'entity',
						'PostalAddress',
						array(
							'addressLocality' => array( 'text', 'locality' ),
							'addressCountry'  => array( 'upper', 'country' ),
						),
					),
				),
			),
			'organization'        => array(
				'type'        => 'Organization',
				'title'       => __( 'Organization (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A company, club or institution with contact details, marked up as Organization.', 'seooptimierung-toolkit' ),
				'icon'        => 'building',
				'fields'      => array_merge(
					array(
						self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
						self::f(
							'orgType',
							__( 'Kind of organization', 'seooptimierung-toolkit' ),
							'select',
							array(
								'options' => array(
									'Organization'        => __( 'Organization (generic)', 'seooptimierung-toolkit' ),
									'Corporation'         => __( 'Company', 'seooptimierung-toolkit' ),
									'NGO'                 => __( 'Non-profit', 'seooptimierung-toolkit' ),
									'EducationalOrganization' => __( 'School or university', 'seooptimierung-toolkit' ),
									'GovernmentOrganization' => __( 'Government body', 'seooptimierung-toolkit' ),
									'SportsOrganization'  => __( 'Sports club', 'seooptimierung-toolkit' ),
									'MedicalOrganization' => __( 'Medical organization', 'seooptimierung-toolkit' ),
								),
							)
						),
						self::f( 'legalName', __( 'Legal name', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
						self::f( 'url', __( 'Website', 'seooptimierung-toolkit' ), 'url' ),
						self::f( 'logo', __( 'Logo', 'seooptimierung-toolkit' ), 'image' ),
						self::f( 'foundingDate', __( 'Founded', 'seooptimierung-toolkit' ), 'date' ),
						self::f( 'vatID', __( 'VAT ID', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'email', __( 'Email', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'telephone', __( 'Phone', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'sameAs', __( 'Profiles on other sites', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One address per entry, for example a LinkedIn or Wikipedia page.', 'seooptimierung-toolkit' ) ) ),
					),
					self::address_fields()
				),
				'build'       => array(
					'@type'        => array( 'type', 'orgType', 'Organization' ),
					'name'         => array( 'text', 'name' ),
					'legalName'    => array( 'text', 'legalName' ),
					'description'  => array( 'text', 'description' ),
					'url'          => array( 'url', 'url' ),
					'logo'         => array( 'url', 'logo' ),
					'foundingDate' => array( 'date', 'foundingDate' ),
					'vatID'        => array( 'text', 'vatID' ),
					'email'        => array( 'text', 'email' ),
					'telephone'    => array( 'text', 'telephone' ),
					'sameAs'       => array( 'list', 'sameAs' ),
					'address'      => self::address_rule(),
				),
			),
			'local-business'      => array(
				'type'        => 'LocalBusiness',
				'title'       => __( 'Local business (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A shop, restaurant or practice with address and opening hours, marked up as LocalBusiness.', 'seooptimierung-toolkit' ),
				'icon'        => 'store',
				'fields'      => array_merge(
					array(
						self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'businessType', __( 'Business type', 'seooptimierung-toolkit' ), 'select', array( 'options' => self::business_type_options() ) ),
						self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
						self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
						self::f( 'url', __( 'Website', 'seooptimierung-toolkit' ), 'url' ),
						self::f( 'telephone', __( 'Phone', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'email', __( 'Email', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'priceRange', __( 'Price range', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '€€' ) ),
						self::f( 'servesCuisine', __( 'Cuisine', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Italian', 'seooptimierung-toolkit' ) ) ),
						self::f( 'openingHours', __( 'Opening hours', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One entry per line, for example Mo-Fr 09:00-18:00.', 'seooptimierung-toolkit' ) ) ),
					),
					self::address_fields(),
					array(
						self::f( 'latitude', __( 'Latitude', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '52.5200' ) ),
						self::f( 'longitude', __( 'Longitude', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '13.4050' ) ),
					)
				),
				'build'       => array(
					'@type'         => array( 'type', 'businessType', 'LocalBusiness' ),
					'name'          => array( 'text', 'name', 'title' ),
					'description'   => array( 'text', 'description' ),
					'image'         => array( 'image', 'image' ),
					'url'           => array( 'url', 'url' ),
					'telephone'     => array( 'text', 'telephone' ),
					'email'         => array( 'text', 'email' ),
					'priceRange'    => array( 'text', 'priceRange' ),
					'servesCuisine' => array( 'text', 'servesCuisine' ),
					'openingHours'  => array( 'list', 'openingHours' ),
					'address'       => self::address_rule(),
					'geo'           => self::geo_rule(),
				),
			),
			'service'             => array(
				'type'        => 'Service',
				'title'       => __( 'Service (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A service you offer, with provider and area, marked up as Service.', 'seooptimierung-toolkit' ),
				'icon'        => 'admin-tools',
				'fields'      => array(
					self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'serviceType', __( 'Kind of service', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'provider', __( 'Provider', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'providerUrl', __( 'Provider website', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'areaServed', __( 'Area served', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Berlin', 'seooptimierung-toolkit' ) ) ),
					self::f( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::f( 'url', __( 'Order or contact link', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'       => array( 'const', 'Service' ),
					'name'        => array( 'text', 'name', 'title' ),
					'serviceType' => array( 'text', 'serviceType' ),
					'description' => array( 'text', 'description' ),
					'image'       => array( 'image', 'image' ),
					'provider'    => array(
						'entity',
						'Organization',
						array(
							'name' => array( 'text', 'provider' ),
							'url'  => array( 'url', 'providerUrl' ),
						),
						array( 'name' ),
					),
					'areaServed'  => array( 'text', 'areaServed' ),
					'offers'      => array(
						'entity',
						'Offer',
						array(
							'price'         => array( 'price', 'price' ),
							'priceCurrency' => array( 'currency', 'currency', 'EUR' ),
							'url'           => array( 'url', 'url' ),
						),
						array( 'price' ),
					),
				),
			),
			'place'               => array(
				'type'        => 'Place',
				'title'       => __( 'Place (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A place or sight with address and opening hours, marked up as Place.', 'seooptimierung-toolkit' ),
				'icon'        => 'location',
				'fields'      => array_merge(
					array(
						self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
						self::f(
							'placeType',
							__( 'Kind of place', 'seooptimierung-toolkit' ),
							'select',
							array(
								'options' => array(
									'Place'             => __( 'Place (generic)', 'seooptimierung-toolkit' ),
									'TouristAttraction' => __( 'Tourist attraction', 'seooptimierung-toolkit' ),
									'Park'              => __( 'Park', 'seooptimierung-toolkit' ),
									'Museum'            => __( 'Museum', 'seooptimierung-toolkit' ),
									'LandmarksOrHistoricalBuildings' => __( 'Landmark', 'seooptimierung-toolkit' ),
									'Beach'             => __( 'Beach', 'seooptimierung-toolkit' ),
									'Playground'        => __( 'Playground', 'seooptimierung-toolkit' ),
								),
							)
						),
						self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
						self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
						self::f( 'telephone', __( 'Phone', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'openingHours', __( 'Opening hours', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One entry per line, for example Mo-Fr 09:00-18:00.', 'seooptimierung-toolkit' ) ) ),
						self::f( 'free', __( 'Free to visit', 'seooptimierung-toolkit' ), 'toggle' ),
					),
					self::address_fields(),
					array(
						self::f( 'latitude', __( 'Latitude', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '52.5200' ) ),
						self::f( 'longitude', __( 'Longitude', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '13.4050' ) ),
					)
				),
				'build'       => array(
					'@type'               => array( 'type', 'placeType', 'Place' ),
					'name'                => array( 'text', 'name', 'title' ),
					'description'         => array( 'text', 'description' ),
					'image'               => array( 'image', 'image' ),
					'telephone'           => array( 'text', 'telephone' ),
					'openingHours'        => array( 'list', 'openingHours' ),
					'isAccessibleForFree' => array( 'bool', 'free' ),
					'address'             => self::address_rule(),
					'geo'                 => self::geo_rule(),
				),
			),
			'podcast-episode'     => array(
				'type'        => 'PodcastEpisode',
				'title'       => __( 'Podcast episode (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'One episode of a podcast with its audio, marked up as PodcastEpisode.', 'seooptimierung-toolkit' ),
				'icon'        => 'microphone',
				'fields'      => array(
					self::f( 'name', __( 'Episode title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Cover', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'audioUrl', __( 'Audio file URL', 'seooptimierung-toolkit' ), 'url', array( 'render' => 'audio' ) ),
					self::f( 'duration', __( 'Length (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'episodeNumber', __( 'Episode number', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'seriesName', __( 'Podcast name', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'seriesUrl', __( 'Podcast website', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'           => array( 'const', 'PodcastEpisode' ),
					'name'            => array( 'text', 'name', 'title' ),
					'description'     => array( 'text', 'description' ),
					'image'           => array( 'image', 'image' ),
					'datePublished'   => array( 'date', 'datePublished' ),
					'timeRequired'    => array( 'minutes', 'duration' ),
					'episodeNumber'   => array( 'posint', 'episodeNumber' ),
					'associatedMedia' => array( 'entity', 'AudioObject', array( 'contentUrl' => array( 'url', 'audioUrl' ) ) ),
					'partOfSeries'    => array(
						'entity',
						'PodcastSeries',
						array(
							'name' => array( 'text', 'seriesName' ),
							'url'  => array( 'url', 'seriesUrl' ),
						),
						array( 'name' ),
					),
				),
			),
			'dataset'             => array(
				'type'        => 'Dataset',
				'title'       => __( 'Dataset (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A downloadable data set with licence and creator, marked up as Dataset.', 'seooptimierung-toolkit' ),
				'icon'        => 'database',
				'fields'      => array(
					self::f( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'creator', __( 'Creator', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'creatorUrl', __( 'Creator website', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'license', __( 'Licence link', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'keywords', __( 'Keywords', 'seooptimierung-toolkit' ), 'list' ),
					self::f( 'downloadUrl', __( 'Download link', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'format', __( 'File format', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'CSV' ) ),
					self::f( 'temporalCoverage', __( 'Period covered', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '2020-01-01/2025-12-31' ) ),
					self::f( 'spatialCoverage', __( 'Region covered', 'seooptimierung-toolkit' ), 'text' ),
				),
				'build'       => array(
					'@type'            => array( 'const', 'Dataset' ),
					'name'             => array( 'text', 'name', 'title' ),
					'description'      => array( 'text', 'description' ),
					'creator'          => array(
						'entity',
						'Organization',
						array(
							'name' => array( 'text', 'creator' ),
							'url'  => array( 'url', 'creatorUrl' ),
						),
						array( 'name' ),
					),
					'license'          => array( 'url', 'license' ),
					'datePublished'    => array( 'date', 'datePublished' ),
					'keywords'         => array( 'list', 'keywords' ),
					'distribution'     => array(
						'entity',
						'DataDownload',
						array(
							'contentUrl'     => array( 'url', 'downloadUrl' ),
							'encodingFormat' => array( 'text', 'format' ),
						),
						array( 'contentUrl' ),
					),
					'temporalCoverage' => array( 'text', 'temporalCoverage' ),
					'spatialCoverage'  => array( 'text', 'spatialCoverage' ),
				),
			),
			'qa-page'             => array(
				'type'        => 'QAPage',
				'page'        => true,
				'title'       => __( 'Question and answer page (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'One question with its accepted answer, marked up as QAPage.', 'seooptimierung-toolkit' ),
				'icon'        => 'format-chat',
				'fields'      => array(
					self::f( 'question', __( 'Question', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'details', __( 'Question details', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'askedBy', __( 'Asked by', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'dateAsked', __( 'Asked on', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'answer', __( 'Answer', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'answerAuthor', __( 'Answered by', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'dateAnswered', __( 'Answered on', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'upvotes', __( 'Upvotes of the answer', 'seooptimierung-toolkit' ), 'number' ),
				),
				'build'       => array(
					'@type'      => array( 'const', 'QAPage' ),
					'mainEntity' => array(
						'entity',
						'Question',
						array(
							'name'           => array( 'text', 'question' ),
							'text'           => array( 'text', 'details' ),
							'answerCount'    => array( 'countif', 'answer' ),
							'datePublished'  => array( 'date', 'dateAsked' ),
							'author'         => array( 'entity', 'Person', array( 'name' => array( 'text', 'askedBy' ) ), array( 'name' ) ),
							'acceptedAnswer' => array(
								'entity',
								'Answer',
								array(
									'text'          => array( 'text', 'answer' ),
									'datePublished' => array( 'date', 'dateAnswered' ),
									'upvoteCount'   => array( 'posint', 'upvotes' ),
									'author'        => array( 'entity', 'Person', array( 'name' => array( 'text', 'answerAuthor' ) ), array( 'name' ) ),
								),
								array( 'text' ),
							),
						),
						array( 'name' ),
					),
				),
			),
			'claim-review'        => array(
				'type'        => 'ClaimReview',
				'title'       => __( 'Fact check (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A fact check of a claim with a verdict, marked up as ClaimReview.', 'seooptimierung-toolkit' ),
				'icon'        => 'yes-alt',
				'fields'      => array(
					self::f( 'claim', __( 'Claim that was checked', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'claimant', __( 'Who made the claim', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'claimDate', __( 'Claim made on', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'verdict', __( 'Verdict', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. False', 'seooptimierung-toolkit' ) ) ),
					self::f(
						'ratingValue',
						__( 'Verdict on a scale', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'1' => __( '1 – false', 'seooptimierung-toolkit' ),
								'2' => __( '2 – mostly false', 'seooptimierung-toolkit' ),
								'3' => __( '3 – mixed', 'seooptimierung-toolkit' ),
								'4' => __( '4 – mostly true', 'seooptimierung-toolkit' ),
								'5' => __( '5 – true', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::f( 'reviewer', __( 'Fact-checking organization', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
				),
				'build'       => array(
					'@type'         => array( 'const', 'ClaimReview' ),
					'url'           => array( 'ctx', 'url' ),
					'claimReviewed' => array( 'text', 'claim' ),
					'datePublished' => array( 'date', 'datePublished' ),
					'author'        => array( 'entity', 'Organization', array( 'name' => array( 'text', 'reviewer' ) ), array( 'name' ) ),
					'itemReviewed'  => array(
						'entity',
						'Claim',
						array(
							'author'        => array( 'entity', 'Person', array( 'name' => array( 'text', 'claimant' ) ), array( 'name' ) ),
							'datePublished' => array( 'date', 'claimDate' ),
						),
						array( 'author' ),
					),
					'reviewRating'  => array(
						'entity',
						'Rating',
						array(
							'ratingValue'   => array( 'number', 'ratingValue' ),
							'bestRating'    => array( 'const', 5 ),
							'worstRating'   => array( 'const', 1 ),
							'alternateName' => array( 'text', 'verdict' ),
						),
						array( 'ratingValue', 'alternateName' ),
					),
				),
			),
			'real-estate-listing' => array(
				'type'        => 'RealEstateListing',
				'page'        => true,
				'title'       => __( 'Property listing (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A flat or house for sale or rent, marked up as RealEstateListing.', 'seooptimierung-toolkit' ),
				'icon'        => 'admin-home',
				'fields'      => array_merge(
					array(
						self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
						self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
						self::f(
							'propertyType',
							__( 'Kind of property', 'seooptimierung-toolkit' ),
							'select',
							array(
								'options' => array(
									'Apartment' => __( 'Apartment', 'seooptimierung-toolkit' ),
									'House'     => __( 'House', 'seooptimierung-toolkit' ),
									'SingleFamilyResidence' => __( 'Single-family home', 'seooptimierung-toolkit' ),
									'Room'      => __( 'Room', 'seooptimierung-toolkit' ),
								),
							)
						),
						self::f( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text' ),
						self::f( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
						self::f( 'floorSize', __( 'Living area (m²)', 'seooptimierung-toolkit' ), 'number' ),
						self::f( 'rooms', __( 'Rooms', 'seooptimierung-toolkit' ), 'number' ),
						self::f( 'datePosted', __( 'Listed on', 'seooptimierung-toolkit' ), 'date' ),
					),
					self::address_fields()
				),
				'build'       => array(
					'@type'       => array( 'const', 'RealEstateListing' ),
					'name'        => array( 'text', 'name', 'title' ),
					'description' => array( 'text', 'description' ),
					'image'       => array( 'image', 'image' ),
					'datePosted'  => array( 'date', 'datePosted' ),
					'offers'      => array(
						'entity',
						'Offer',
						array(
							'price'         => array( 'price', 'price' ),
							'priceCurrency' => array( 'currency', 'currency', 'EUR' ),
						),
						array( 'price' ),
					),
					'about'       => array(
						'entity',
						array( 'type', 'propertyType', 'Apartment' ),
						array(
							'floorSize'     => array(
								'entity',
								'QuantitativeValue',
								array(
									'value'    => array( 'number', 'floorSize' ),
									'unitCode' => array( 'const', 'MTK' ),
								),
								array( 'value' ),
							),
							'numberOfRooms' => array( 'number', 'rooms' ),
							'address'       => self::address_rule(),
						),
					),
				),
			),
			'vehicle'             => array(
				'type'        => 'Car',
				'title'       => __( 'Vehicle (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A car or motorbike for sale, marked up as Vehicle.', 'seooptimierung-toolkit' ),
				'icon'        => 'dashboard',
				'fields'      => array(
					self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::f(
						'vehicleType',
						__( 'Kind of vehicle', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'Car'              => __( 'Car', 'seooptimierung-toolkit' ),
								'Motorcycle'       => __( 'Motorcycle', 'seooptimierung-toolkit' ),
								'BusOrCoach'       => __( 'Bus or coach', 'seooptimierung-toolkit' ),
								'MotorizedBicycle' => __( 'Moped', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'brand', __( 'Make', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'model', __( 'Model', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'year', __( 'Model year', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'mileage', __( 'Mileage (km)', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'fuelType', __( 'Fuel', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'transmission', __( 'Transmission', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'color', __( 'Colour', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'vin', __( 'Vehicle identification number', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'condition', __( 'Condition', 'seooptimierung-toolkit' ), 'select', array( 'options' => self::condition_options() ) ),
					self::f( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::f( 'url', __( 'Link to the offer', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'                       => array( 'type', 'vehicleType', 'Car' ),
					'name'                        => array( 'text', 'name', 'title' ),
					'description'                 => array( 'text', 'description' ),
					'image'                       => array( 'image', 'image' ),
					'brand'                       => array( 'entity', 'Brand', array( 'name' => array( 'text', 'brand' ) ) ),
					'model'                       => array( 'text', 'model' ),
					'vehicleModelDate'            => array( 'text', 'year' ),
					'mileageFromOdometer'         => array(
						'entity',
						'QuantitativeValue',
						array(
							'value'    => array( 'number', 'mileage' ),
							'unitCode' => array( 'const', 'KMT' ),
						),
						array( 'value' ),
					),
					'fuelType'                    => array( 'text', 'fuelType' ),
					'vehicleTransmission'         => array( 'text', 'transmission' ),
					'color'                       => array( 'text', 'color' ),
					'vehicleIdentificationNumber' => array( 'text', 'vin' ),
					'itemCondition'               => array( 'enum', 'condition' ),
					'offers'                      => array(
						'entity',
						'Offer',
						array(
							'price'         => array( 'price', 'price' ),
							'priceCurrency' => array( 'currency', 'currency', 'EUR' ),
							'url'           => array( 'url', 'url' ),
						),
						array( 'price' ),
					),
				),
			),
			'learning-resource'   => array(
				'type'        => 'LearningResource',
				'title'       => __( 'Learning resource (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A tutorial, worksheet or lesson, marked up as LearningResource.', 'seooptimierung-toolkit' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array(
					self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'resourceType', __( 'Kind of resource', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Tutorial, Worksheet', 'seooptimierung-toolkit' ) ) ),
					self::f( 'educationalLevel', __( 'Level', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Beginner', 'seooptimierung-toolkit' ) ) ),
					self::f( 'teaches', __( 'What it teaches', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'duration', __( 'Time needed (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::f( 'language', __( 'Language (code)', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'de' ) ),
					self::f( 'author', __( 'Author', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'datePublished', __( 'Published', 'seooptimierung-toolkit' ), 'date' ),
				),
				'build'       => array(
					'@type'                => array( 'const', 'LearningResource' ),
					'name'                 => array( 'text', 'name', 'title' ),
					'description'          => array( 'text', 'description' ),
					'image'                => array( 'image', 'image' ),
					'learningResourceType' => array( 'text', 'resourceType' ),
					'educationalLevel'     => array( 'text', 'educationalLevel' ),
					'teaches'              => array( 'text', 'teaches' ),
					'timeRequired'         => array( 'minutes', 'duration' ),
					'inLanguage'           => array( 'text', 'language' ),
					'author'               => array( 'entity', 'Person', array( 'name' => array( 'text', 'author', 'author' ) ) ),
					'datePublished'        => array( 'date', 'datePublished' ),
				),
			),
			'music-album'         => array(
				'type'        => 'MusicAlbum',
				'title'       => __( 'Music album (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'An album with artist and release date, marked up as MusicAlbum.', 'seooptimierung-toolkit' ),
				'icon'        => 'format-audio',
				'fields'      => array(
					self::f( 'name', __( 'Album title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'artist', __( 'Artist', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'image', __( 'Cover', 'seooptimierung-toolkit' ), 'image' ),
					self::f( 'datePublished', __( 'Release date', 'seooptimierung-toolkit' ), 'date' ),
					self::f( 'genre', __( 'Genre', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'tracks', __( 'Tracks', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One track per entry, in order.', 'seooptimierung-toolkit' ) ) ),
					self::f( 'url', __( 'Listen or buy link', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'         => array( 'const', 'MusicAlbum' ),
					'name'          => array( 'text', 'name', 'title' ),
					'byArtist'      => array( 'entity', 'MusicGroup', array( 'name' => array( 'text', 'artist' ) ), array( 'name' ) ),
					'image'         => array( 'image', 'image' ),
					'datePublished' => array( 'date', 'datePublished' ),
					'genre'         => array( 'text', 'genre' ),
					'track'         => array( 'entities', 'tracks', 'MusicRecording' ),
					'url'           => array( 'url', 'url' ),
				),
			),
			'item-list'           => array(
				'type'        => 'ItemList',
				'title'       => __( 'List of items (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A ranking or collection such as "Top 10", marked up as ItemList.', 'seooptimierung-toolkit' ),
				'icon'        => 'list-view',
				'fields'      => array(
					self::f( 'name', __( 'List title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f(
						'items',
						__( 'Entries', 'seooptimierung-toolkit' ),
						'list',
						array(
							'pair' => true,
							'help' => __( 'One entry per line in the form: Name | address of its page. The address is optional.', 'seooptimierung-toolkit' ),
						)
					),
					self::f( 'ordered', __( 'Numbered ranking', 'seooptimierung-toolkit' ), 'toggle' ),
				),
				'build'       => array(
					'@type'           => array( 'const', 'ItemList' ),
					'name'            => array( 'text', 'name', 'title' ),
					'description'     => array( 'text', 'description' ),
					'itemListOrder'   => array( 'boolenum', 'ordered', 'ItemListOrderAscending' ),
					'numberOfItems'   => array( 'itemcount', 'items' ),
					'itemListElement' => array( 'listitems', 'items' ),
				),
			),
			'image-license'       => array(
				'type'        => 'ImageObject',
				'title'       => __( 'Licensable image (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'An image with creator, credit and licence, so search engines can show a "Licensable" badge.', 'seooptimierung-toolkit' ),
				'icon'        => 'format-image',
				'fields'      => array(
					self::f( 'contentUrl', __( 'Image', 'seooptimierung-toolkit' ), 'image', array( 'show' => true ) ),
					self::f( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'creator', __( 'Creator', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'creditText', __( 'Credit line', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'copyrightNotice', __( 'Copyright notice', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => '© 2026 Example' ) ),
					self::f( 'license', __( 'Licence link', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'acquireLicensePage', __( 'Page where the image can be licensed', 'seooptimierung-toolkit' ), 'url' ),
				),
				'build'       => array(
					'@type'              => array( 'const', 'ImageObject' ),
					'name'               => array( 'text', 'name' ),
					'contentUrl'         => array( 'url', 'contentUrl' ),
					'creator'            => array( 'entity', 'Person', array( 'name' => array( 'text', 'creator' ) ), array( 'name' ) ),
					'creditText'         => array( 'text', 'creditText' ),
					'copyrightNotice'    => array( 'text', 'copyrightNotice' ),
					'license'            => array( 'url', 'license' ),
					'acquireLicensePage' => array( 'url', 'acquireLicensePage' ),
				),
			),
			'discussion-post'     => array(
				'type'        => 'DiscussionForumPosting',
				'title'       => __( 'Forum post (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A post from a forum or community thread, marked up as DiscussionForumPosting.', 'seooptimierung-toolkit' ),
				'icon'        => 'testimonial',
				'fields'      => array(
					self::f( 'headline', __( 'Headline', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'text', __( 'Post text', 'seooptimierung-toolkit' ), 'textarea' ),
					self::f( 'author', __( 'Author', 'seooptimierung-toolkit' ), 'text' ),
					self::f( 'authorUrl', __( 'Author profile', 'seooptimierung-toolkit' ), 'url' ),
					self::f( 'datePublished', __( 'Posted', 'seooptimierung-toolkit' ), 'datetime' ),
					self::f( 'comments', __( 'Number of replies', 'seooptimierung-toolkit' ), 'number' ),
				),
				'build'       => array(
					'@type'                => array( 'const', 'DiscussionForumPosting' ),
					'headline'             => array( 'text', 'headline', 'title' ),
					'text'                 => array( 'text', 'text' ),
					'datePublished'        => array( 'datetime', 'datePublished' ),
					'author'               => array(
						'entity',
						'Person',
						array(
							'name' => array( 'text', 'author', 'author' ),
							'url'  => array( 'url', 'authorUrl' ),
						),
						array( 'name' ),
					),
					'interactionStatistic' => array(
						'entity',
						'InteractionCounter',
						array(
							'interactionType'      => array( 'const', 'https://schema.org/CommentAction' ),
							'userInteractionCount' => array( 'posint', 'comments' ),
						),
						array( 'userInteractionCount' ),
					),
				),
			),
		);
	}

	// -- Definition helpers ---------------------------------------------

	/**
	 * One field definition.
	 *
	 * @param string              $key   Attribute name.
	 * @param string              $label Field label.
	 * @param string              $type  Field type.
	 * @param array<string,mixed> $extra Optional: options, help, placeholder, pair, render, show.
	 * @return array<string,mixed>
	 */
	private static function f( string $key, string $label, string $type, array $extra = array() ): array {
		return array_merge(
			array(
				'key'   => $key,
				'label' => $label,
				'type'  => $type,
			),
			$extra
		);
	}

	/**
	 * The five postal-address fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function address_fields(): array {
		return array(
			self::f( 'streetAddress', __( 'Street address', 'seooptimierung-toolkit' ), 'text' ),
			self::f( 'postalCode', __( 'Postal code', 'seooptimierung-toolkit' ), 'text' ),
			self::f( 'locality', __( 'City', 'seooptimierung-toolkit' ), 'text' ),
			self::f( 'region', __( 'State / region', 'seooptimierung-toolkit' ), 'text' ),
			self::f( 'country', __( 'Country (2-letter code)', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'DE' ) ),
		);
	}

	/**
	 * Rule for the PostalAddress built from address_fields().
	 *
	 * @return array<int,mixed>
	 */
	private static function address_rule(): array {
		return array(
			'entity',
			'PostalAddress',
			array(
				'streetAddress'   => array( 'text', 'streetAddress' ),
				'postalCode'      => array( 'text', 'postalCode' ),
				'addressLocality' => array( 'text', 'locality' ),
				'addressRegion'   => array( 'text', 'region' ),
				'addressCountry'  => array( 'upper', 'country' ),
			),
		);
	}

	/**
	 * Rule for GeoCoordinates from the latitude/longitude fields.
	 *
	 * @return array<int,mixed>
	 */
	private static function geo_rule(): array {
		return array(
			'entity',
			'GeoCoordinates',
			array(
				'latitude'  => array( 'number', 'latitude' ),
				'longitude' => array( 'number', 'longitude' ),
			),
			array( 'latitude', 'longitude' ),
		);
	}

	/**
	 * Rule for an Offer from price / currency / url, and — for products —
	 * availability (InStock unless chosen otherwise) and condition.
	 *
	 * @param bool $with_stock Whether the type has availability and condition fields.
	 * @return array<int,mixed>
	 */
	private static function offer_rule( bool $with_stock ): array {
		$props = array(
			'price'         => array( 'price', 'price' ),
			'priceCurrency' => array( 'currency', 'currency', 'EUR' ),
			'url'           => array( 'url', 'url' ),
		);

		if ( $with_stock ) {
			$props['availability']  = array( 'enum', 'availability', 'InStock' );
			$props['itemCondition'] = array( 'enum', 'condition' );
		}

		return array( 'entity', 'Offer', $props, array( 'price' ) );
	}

	/**
	 * Availability choices.
	 *
	 * @return array<string,string>
	 */
	private static function availability_options(): array {
		return array(
			'InStock'             => __( 'In stock', 'seooptimierung-toolkit' ),
			'OutOfStock'          => __( 'Out of stock', 'seooptimierung-toolkit' ),
			'PreOrder'            => __( 'Pre-order', 'seooptimierung-toolkit' ),
			'BackOrder'           => __( 'Back order', 'seooptimierung-toolkit' ),
			'LimitedAvailability' => __( 'Limited availability', 'seooptimierung-toolkit' ),
			'SoldOut'             => __( 'Sold out', 'seooptimierung-toolkit' ),
			'Discontinued'        => __( 'Discontinued', 'seooptimierung-toolkit' ),
		);
	}

	/**
	 * Condition choices.
	 *
	 * @return array<string,string>
	 */
	private static function condition_options(): array {
		return array(
			'NewCondition'         => __( 'New', 'seooptimierung-toolkit' ),
			'UsedCondition'        => __( 'Used', 'seooptimierung-toolkit' ),
			'RefurbishedCondition' => __( 'Refurbished', 'seooptimierung-toolkit' ),
			'DamagedCondition'     => __( 'Damaged', 'seooptimierung-toolkit' ),
		);
	}

	/**
	 * Help text for rating fields: never invent ratings.
	 */
	private static function rating_help(): string {
		return __( 'Only if you really collect ratings — never invent them.', 'seooptimierung-toolkit' );
	}

	/**
	 * The business-type choices, flattened from LocalBusinessTypes.
	 *
	 * @return array<string,string>
	 */
	private static function business_type_options(): array {
		$options = array();

		foreach ( LocalBusinessTypes::groups() as $types ) {
			$options = array_merge( $options, $types );
		}

		return $options;
	}

	// -- JSON-LD --------------------------------------------------------

	/**
	 * The JSON-LD node of a declared type.
	 *
	 * @param array<string,mixed>  $definition The type's definition.
	 * @param array<string,mixed>  $attrs      Block attributes.
	 * @param array<string,string> $ctx        Page facts.
	 * @param \DateTimeZone        $zone       Site time zone.
	 * @return array<string,mixed>
	 */
	public static function node( array $definition, array $attrs, array $ctx, \DateTimeZone $zone ): array {
		$node = array();

		foreach ( $definition['build'] as $property => $rule ) {
			$node[ $property ] = self::resolve( (array) $rule, $attrs, $ctx, $zone );
		}

		return $node;
	}

	/**
	 * Whether a resolved value carries anything.
	 *
	 * @param mixed $value Resolved value.
	 */
	private static function present( $value ): bool {
		return null !== $value && '' !== $value && array() !== $value;
	}

	/**
	 * Resolves one rule to a value.
	 *
	 * @param array<int,mixed>     $rule  The rule.
	 * @param array<string,mixed>  $attrs Block attributes.
	 * @param array<string,string> $ctx   Page facts.
	 * @param \DateTimeZone        $zone  Site time zone.
	 * @return mixed
	 */
	private static function resolve( array $rule, array $attrs, array $ctx, \DateTimeZone $zone ) {
		$kind = (string) ( $rule[0] ?? '' );
		$key  = isset( $rule[1] ) && is_scalar( $rule[1] ) ? (string) $rule[1] : '';
		$text = ContentTypes::text( $attrs, $key );

		switch ( $kind ) {
			case 'text':
				if ( '' === $text && isset( $rule[2] ) ) {
					return (string) ( $ctx[ (string) $rule[2] ] ?? '' );
				}
				return $text;
			case 'url':
				return ContentTypes::url( $attrs, $key );
			case 'image':
				$url = ContentTypes::url( $attrs, $key );
				if ( '' !== $url ) {
					return array( $url );
				}
				return isset( $rule[2] ) && '' !== ( $ctx['image'] ?? '' ) ? array( $ctx['image'] ) : array();
			case 'date':
			case 'datetime':
				return ContentTypes::iso_datetime( $text, $zone );
			case 'minutes':
				return ContentTypes::duration_from_minutes( $attrs[ $key ] ?? 0 );
			case 'seconds':
				return ContentTypes::duration_from_seconds( $attrs[ $key ] ?? 0 );
			case 'number':
				$number = str_replace( ',', '.', $text );
				if ( is_numeric( $number ) ) {
					return $number + 0;
				}
				return '' !== $text ? null : ( $rule[2] ?? null );
			case 'posint':
				$count = (int) $text;
				return $count > 0 ? $count : null;
			case 'itemcount':
				$count = count( ContentTypes::clean_list( $attrs[ $key ] ?? array() ) );
				return $count > 0 ? $count : null;
			case 'list':
				return ContentTypes::clean_list( $attrs[ $key ] ?? array() );
			case 'enum':
				$name = '' !== $text ? $text : (string) ( $rule[2] ?? '' );
				return '' !== $name ? ContentTypes::enum_url( $name ) : '';
			case 'boolenum':
				return ! empty( $attrs[ $key ] ) ? ContentTypes::enum_url( (string) ( $rule[2] ?? '' ) ) : '';
			case 'bool':
				return ! empty( $attrs[ $key ] ) ? true : null;
			case 'price':
				return self::normalise_price( $text );
			case 'upper':
				return strtoupper( $text );
			case 'currency':
				return '' !== $text ? strtoupper( $text ) : (string) ( $rule[2] ?? 'EUR' );
			case 'type':
				return 1 === preg_match( '/^[A-Z][A-Za-z]*$/', $text ) ? $text : (string) ( $rule[2] ?? 'Thing' );
			case 'countif':
				return '' !== $text ? 1 : 0;
			case 'const':
				return $rule[1] ?? null;
			case 'ctx':
				return (string) ( $ctx[ $key ] ?? '' );
			case 'listitems':
				return self::list_items( ContentTypes::clean_list( $attrs[ $key ] ?? array() ) );
			case 'entities':
				$entities = array();
				foreach ( ContentTypes::clean_list( $attrs[ $key ] ?? array() ) as $name ) {
					$entities[] = array(
						'@type' => (string) ( $rule[2] ?? 'Thing' ),
						'name'  => $name,
					);
				}
				return $entities;
			case 'entity':
				return self::entity( $rule, $attrs, $ctx, $zone );
		}

		return null;
	}

	/**
	 * A nested object, or null when it is empty or lacks a required property.
	 *
	 * @param array<int,mixed>     $rule  [ 'entity', type|rule, props, required ].
	 * @param array<string,mixed>  $attrs Block attributes.
	 * @param array<string,string> $ctx   Page facts.
	 * @param \DateTimeZone        $zone  Site time zone.
	 * @return array<string,mixed>|null
	 */
	private static function entity( array $rule, array $attrs, array $ctx, \DateTimeZone $zone ): ?array {
		$type     = is_array( $rule[1] ) ? (string) self::resolve( $rule[1], $attrs, $ctx, $zone ) : (string) $rule[1];
		$props    = (array) ( $rule[2] ?? array() );
		$required = (array) ( $rule[3] ?? array() );
		$values   = array();

		foreach ( $props as $property => $prop_rule ) {
			$value = self::resolve( (array) $prop_rule, $attrs, $ctx, $zone );
			if ( self::present( $value ) ) {
				$values[ $property ] = $value;
			}
		}

		foreach ( $required as $property ) {
			if ( ! isset( $values[ $property ] ) ) {
				return null;
			}
		}

		if ( array() === $values ) {
			return null;
		}

		return array_merge( array( '@type' => $type ), $values );
	}

	/**
	 * A typed price as a plain decimal string: "49,90", "1.299,00", "€ 49" and
	 * "1,299.00" become "49.90", "1299.00", "49" and "1299.00"; '' when it is
	 * not a number.
	 *
	 * @param string $value Entered price.
	 */
	public static function normalise_price( string $value ): string {
		$value = preg_replace( '/[^0-9.,]/', '', $value );
		$value = is_string( $value ) ? $value : '';

		if ( '' === $value ) {
			return '';
		}

		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );

		if ( false !== $comma && false !== $dot ) {
			// The separator that comes last is the decimal one.
			$value = $comma > $dot ? str_replace( array( '.', ',' ), array( '', '.' ), $value ) : str_replace( ',', '', $value );
		} elseif ( false !== $comma ) {
			$value = str_replace( ',', '.', $value );
		}

		return is_numeric( $value ) ? $value : '';
	}

	/**
	 * ListItem entries from "Name | address" lines.
	 *
	 * @param string[] $lines Entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_items( array $lines ): array {
		$items = array();

		foreach ( array_values( $lines ) as $position => $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			$url   = isset( $parts[1] ) ? esc_url_raw( $parts[1] ) : '';

			$item = array(
				'@type'    => 'ListItem',
				'position' => $position + 1,
				'name'     => $parts[0],
			);
			if ( '' !== $url ) {
				$item['url'] = $url;
			}
			$items[] = $item;
		}

		return $items;
	}

	// -- Visible markup -------------------------------------------------

	/**
	 * The visible markup of a declared type: its title, text paragraphs and
	 * one label/value row per remaining field.
	 *
	 * @param array<string,mixed> $definition The type's definition.
	 * @param array<string,mixed> $attrs      Block attributes.
	 */
	public static function html( array $definition, array $attrs ): string {
		$title      = '';
		$paragraphs = '';
		$rows       = array();

		foreach ( $definition['fields'] as $field ) {
			$key   = (string) $field['key'];
			$label = (string) $field['label'];
			$type  = (string) $field['type'];

			if ( '' === $title && 'text' === $type && in_array( $key, array( 'name', 'title', 'headline', 'question' ), true ) ) {
				$title = ContentTypes::text( $attrs, $key );
				continue;
			}
			if ( 'textarea' === $type ) {
				$paragraphs .= '<p>' . esc_html( ContentTypes::text( $attrs, $key ) ) . '</p>';
				continue;
			}
			if ( 'image' === $type ) {
				$shown = ! empty( $field['show'] ) ? ContentTypes::url( $attrs, $key ) : '';
				if ( '' !== $shown ) {
					$paragraphs .= '<figure class="seooptimierung-schema__figure"><img src="' . esc_url( $shown ) . '" alt="' . esc_attr( ContentTypes::text( $attrs, 'name' ) ) . '" loading="lazy" /></figure>';
				}
				continue;
			}

			$value = self::row_value( $field, $attrs );
			if ( '' !== $value ) {
				$rows[ $label ] = $value;
			}
		}

		$out  = '' !== $title ? '<p class="seooptimierung-schema__title">' . esc_html( $title ) . '</p>' : '';
		$out .= str_replace( '<p></p>', '', $paragraphs );

		$list = '';
		foreach ( $rows as $label => $value ) {
			$list .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . $value . '</dd>';
		}

		return $out . ( '' !== $list ? '<dl>' . $list . '</dl>' : '' );
	}

	/**
	 * The HTML-safe value of one field for the markup, or ''.
	 *
	 * @param array<string,mixed> $field Field definition.
	 * @param array<string,mixed> $attrs Block attributes.
	 */
	private static function row_value( array $field, array $attrs ): string {
		$key  = (string) $field['key'];
		$text = ContentTypes::text( $attrs, $key );

		switch ( (string) $field['type'] ) {
			case 'toggle':
				return ! empty( $attrs[ $key ] ) ? esc_html__( 'Yes', 'seooptimierung-toolkit' ) : '';
			case 'select':
				return '' !== $text ? esc_html( (string) ( $field['options'][ $text ] ?? $text ) ) : '';
			case 'datetime':
				return esc_html( str_replace( 'T', ' ', $text ) );
			case 'url':
				$url = ContentTypes::url( $attrs, $key );
				if ( '' === $url ) {
					return '';
				}
				if ( 'audio' === ( $field['render'] ?? '' ) ) {
					return '<audio controls preload="none" src="' . esc_url( $url ) . '"></audio>';
				}
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>';
			case 'list':
				$items = ContentTypes::clean_list( $attrs[ $key ] ?? array() );
				if ( array() === $items ) {
					return '';
				}
				if ( ! empty( $field['pair'] ) ) {
					return self::pair_list( $items, ! empty( $attrs['ordered'] ) );
				}
				return '<ul class="seooptimierung-schema__list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></ul>';
		}

		return esc_html( $text );
	}

	/**
	 * A list of "Name | address" entries with the names linked.
	 *
	 * @param string[] $items   Entries.
	 * @param bool     $ordered Whether to number it.
	 */
	private static function pair_list( array $items, bool $ordered ): string {
		$html = '';

		foreach ( self::list_items( $items ) as $item ) {
			$name  = esc_html( (string) $item['name'] );
			$html .= '<li>' . ( isset( $item['url'] ) ? '<a href="' . esc_url( (string) $item['url'] ) . '">' . $name . '</a>' : $name ) . '</li>';
		}

		$tag = $ordered ? 'ol' : 'ul';

		return '<' . $tag . ' class="seooptimierung-schema__list">' . $html . '</' . $tag . '>';
	}
}

<?php
/**
 * The structured-data content types that can be placed inside a page.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

/**
 * One registry drives everything about an embeddable content type — FAQ,
 * HowTo, Recipe, Event, JobPosting, Course, SoftwareApplication, VideoObject
 * (coded here) and the further types declared in ExtraContentTypes:
 *
 * - the fields the editor shows (definitions_for_editor()),
 * - the block attributes WordPress needs (block_attributes()),
 * - the visible markup printed on the page (html()), and
 * - the JSON-LD node added to the page's graph (node()).
 *
 * Because the visible markup and the node are built from the same
 * attributes, what a visitor reads and what a search engine is told cannot
 * drift apart — which structured-data guidelines require.
 *
 * Several of these can sit on one page (an article with an FAQ and a recipe);
 * each becomes its own node in the single @graph.
 */
final class ContentTypes {

	/**
	 * All content types: slug => definition. The slug is also the block name
	 * suffix (seooptimierung/{slug}).
	 *
	 * Field types: text, textarea, number, date, datetime, url, select,
	 * toggle, image (a URL chosen from the media library), list (a list of
	 * strings) and qa (a list of question/answer pairs).
	 *
	 * @return array<string,array{type:string,title:string,description:string,icon:string,fields:array<int,array<string,mixed>>}>
	 */
	public static function all(): array {
		static $all = null;

		if ( null !== $all ) {
			return $all;
		}

		$all = array(
			'faq'                  => array(
				'type'        => 'FAQPage',
				'title'       => __( 'FAQ (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'Questions and answers, shown on the page and marked up as FAQ.', 'seooptimierung-toolkit' ),
				'icon'        => 'editor-help',
				'fields'      => array(
					self::field( 'items', __( 'Questions and answers', 'seooptimierung-toolkit' ), 'qa' ),
				),
			),
			'howto'                => array(
				'type'        => 'HowTo',
				'title'       => __( 'How-to (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'Step-by-step instructions, shown on the page and marked up as HowTo.', 'seooptimierung-toolkit' ),
				'icon'        => 'list-view',
				'fields'      => array(
					self::field( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Short description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::field( 'totalTime', __( 'Total time (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'supplies', __( 'Materials', 'seooptimierung-toolkit' ), 'list' ),
					self::field( 'tools', __( 'Tools', 'seooptimierung-toolkit' ), 'list' ),
					self::field( 'steps', __( 'Steps', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One step per entry, in order.', 'seooptimierung-toolkit' ) ) ),
				),
			),
			'recipe'               => array(
				'type'        => 'Recipe',
				'title'       => __( 'Recipe (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A recipe card, shown on the page and marked up as Recipe.', 'seooptimierung-toolkit' ),
				'icon'        => 'carrot',
				'fields'      => array(
					self::field( 'name', __( 'Recipe name', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Short description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::field( 'prepTime', __( 'Preparation time (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'cookTime', __( 'Cooking time (minutes)', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'recipeYield', __( 'Yield', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. 4 servings', 'seooptimierung-toolkit' ) ) ),
					self::field( 'recipeCategory', __( 'Category', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Main course', 'seooptimierung-toolkit' ) ) ),
					self::field( 'recipeCuisine', __( 'Cuisine', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. Italian', 'seooptimierung-toolkit' ) ) ),
					self::field( 'calories', __( 'Calories per serving', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. 320 calories', 'seooptimierung-toolkit' ) ) ),
					self::field( 'ingredients', __( 'Ingredients', 'seooptimierung-toolkit' ), 'list' ),
					self::field( 'steps', __( 'Instructions', 'seooptimierung-toolkit' ), 'list', array( 'help' => __( 'One step per entry, in order.', 'seooptimierung-toolkit' ) ) ),
				),
			),
			'event'                => array(
				'type'        => 'Event',
				'title'       => __( 'Event (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'Date, place and tickets of an event, shown on the page and marked up as Event.', 'seooptimierung-toolkit' ),
				'icon'        => 'calendar-alt',
				'fields'      => array(
					self::field( 'name', __( 'Event name', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Short description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'startDate', __( 'Starts', 'seooptimierung-toolkit' ), 'datetime' ),
					self::field( 'endDate', __( 'Ends', 'seooptimierung-toolkit' ), 'datetime' ),
					self::field(
						'status',
						__( 'Status', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'EventScheduled'   => __( 'Scheduled', 'seooptimierung-toolkit' ),
								'EventCancelled'   => __( 'Cancelled', 'seooptimierung-toolkit' ),
								'EventPostponed'   => __( 'Postponed', 'seooptimierung-toolkit' ),
								'EventRescheduled' => __( 'Rescheduled', 'seooptimierung-toolkit' ),
								'EventMovedOnline' => __( 'Moved online', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::field(
						'attendanceMode',
						__( 'Attendance', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'OfflineEventAttendanceMode' => __( 'In person', 'seooptimierung-toolkit' ),
								'OnlineEventAttendanceMode' => __( 'Online', 'seooptimierung-toolkit' ),
								'MixedEventAttendanceMode' => __( 'In person and online', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::field( 'locationName', __( 'Venue name', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'address', __( 'Venue address', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'onlineUrl', __( 'Online link', 'seooptimierung-toolkit' ), 'url' ),
					self::field( 'organizer', __( 'Organizer', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'organizerUrl', __( 'Organizer website', 'seooptimierung-toolkit' ), 'url' ),
					self::field( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::field( 'price', __( 'Ticket price', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( 'e.g. 25 (0 for free)', 'seooptimierung-toolkit' ) ) ),
					self::field( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::field( 'ticketUrl', __( 'Ticket link', 'seooptimierung-toolkit' ), 'url' ),
				),
			),
			'job-posting'          => array(
				'type'        => 'JobPosting',
				'title'       => __( 'Job posting (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A job ad, shown on the page and marked up as JobPosting.', 'seooptimierung-toolkit' ),
				'icon'        => 'businessperson',
				'fields'      => array(
					self::field( 'title', __( 'Job title', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Job description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'datePosted', __( 'Posted on', 'seooptimierung-toolkit' ), 'date' ),
					self::field( 'validThrough', __( 'Apply until', 'seooptimierung-toolkit' ), 'date' ),
					self::field(
						'employmentType',
						__( 'Employment type', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'FULL_TIME'  => __( 'Full time', 'seooptimierung-toolkit' ),
								'PART_TIME'  => __( 'Part time', 'seooptimierung-toolkit' ),
								'CONTRACTOR' => __( 'Contractor', 'seooptimierung-toolkit' ),
								'TEMPORARY'  => __( 'Temporary', 'seooptimierung-toolkit' ),
								'INTERN'     => __( 'Internship', 'seooptimierung-toolkit' ),
								'VOLUNTEER'  => __( 'Volunteer', 'seooptimierung-toolkit' ),
								'PER_DIEM'   => __( 'Per diem', 'seooptimierung-toolkit' ),
								'OTHER'      => __( 'Other', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::field( 'company', __( 'Hiring company', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'companyUrl', __( 'Company website', 'seooptimierung-toolkit' ), 'url' ),
					self::field( 'companyLogo', __( 'Company logo', 'seooptimierung-toolkit' ), 'image' ),
					self::field( 'remote', __( 'Fully remote', 'seooptimierung-toolkit' ), 'toggle' ),
					self::field( 'streetAddress', __( 'Street address', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'postalCode', __( 'Postal code', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'locality', __( 'City', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'region', __( 'State / region', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'country', __( 'Country (2-letter code)', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'DE' ) ),
					self::field( 'salaryMin', __( 'Salary from', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'salaryMax', __( 'Salary up to', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'salaryCurrency', __( 'Salary currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::field(
						'salaryUnit',
						__( 'Salary per', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'YEAR'  => __( 'Year', 'seooptimierung-toolkit' ),
								'MONTH' => __( 'Month', 'seooptimierung-toolkit' ),
								'WEEK'  => __( 'Week', 'seooptimierung-toolkit' ),
								'DAY'   => __( 'Day', 'seooptimierung-toolkit' ),
								'HOUR'  => __( 'Hour', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::field( 'applyUrl', __( 'Application link', 'seooptimierung-toolkit' ), 'url' ),
				),
			),
			'course'               => array(
				'type'        => 'Course',
				'title'       => __( 'Course (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A course or training, shown on the page and marked up as Course.', 'seooptimierung-toolkit' ),
				'icon'        => 'welcome-learn-more',
				'fields'      => array(
					self::field( 'name', __( 'Course name', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'provider', __( 'Provider', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'providerUrl', __( 'Provider website', 'seooptimierung-toolkit' ), 'url' ),
				),
			),
			'software-application' => array(
				'type'        => 'SoftwareApplication',
				'title'       => __( 'Software (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'An app or program, shown on the page and marked up as SoftwareApplication.', 'seooptimierung-toolkit' ),
				'icon'        => 'laptop',
				'fields'      => array(
					self::field( 'name', __( 'Name', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'image', __( 'Image', 'seooptimierung-toolkit' ), 'image' ),
					self::field(
						'category',
						__( 'Category', 'seooptimierung-toolkit' ),
						'select',
						array(
							'options' => array(
								'BusinessApplication'      => __( 'Business', 'seooptimierung-toolkit' ),
								'DeveloperApplication'     => __( 'Developer tools', 'seooptimierung-toolkit' ),
								'DesignApplication'        => __( 'Design', 'seooptimierung-toolkit' ),
								'EducationalApplication'   => __( 'Education', 'seooptimierung-toolkit' ),
								'EntertainmentApplication' => __( 'Entertainment', 'seooptimierung-toolkit' ),
								'FinanceApplication'       => __( 'Finance', 'seooptimierung-toolkit' ),
								'GameApplication'          => __( 'Game', 'seooptimierung-toolkit' ),
								'HealthApplication'        => __( 'Health', 'seooptimierung-toolkit' ),
								'LifestyleApplication'     => __( 'Lifestyle', 'seooptimierung-toolkit' ),
								'MultimediaApplication'    => __( 'Multimedia', 'seooptimierung-toolkit' ),
								'SecurityApplication'      => __( 'Security', 'seooptimierung-toolkit' ),
								'ShoppingApplication'      => __( 'Shopping', 'seooptimierung-toolkit' ),
								'UtilitiesApplication'     => __( 'Utilities', 'seooptimierung-toolkit' ),
							),
						)
					),
					self::field( 'operatingSystem', __( 'Operating system', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'Windows, macOS, Android' ) ),
					self::field( 'price', __( 'Price', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => __( '0 for free', 'seooptimierung-toolkit' ) ) ),
					self::field( 'currency', __( 'Currency', 'seooptimierung-toolkit' ), 'text', array( 'placeholder' => 'EUR' ) ),
					self::field( 'downloadUrl', __( 'Download link', 'seooptimierung-toolkit' ), 'url' ),
					self::field( 'ratingValue', __( 'Average rating', 'seooptimierung-toolkit' ), 'number', array( 'help' => __( 'Only if you really collect ratings — never invent them. Google shows a rich result for software only with a rating or review.', 'seooptimierung-toolkit' ) ) ),
					self::field( 'ratingCount', __( 'Number of ratings', 'seooptimierung-toolkit' ), 'number' ),
				),
			),
			'video'                => array(
				'type'        => 'VideoObject',
				'title'       => __( 'Video (structured data)', 'seooptimierung-toolkit' ),
				'description' => __( 'A video with its details, shown on the page and marked up as VideoObject.', 'seooptimierung-toolkit' ),
				'icon'        => 'format-video',
				'fields'      => array(
					self::field( 'name', __( 'Title', 'seooptimierung-toolkit' ), 'text' ),
					self::field( 'description', __( 'Description', 'seooptimierung-toolkit' ), 'textarea' ),
					self::field( 'thumbnail', __( 'Thumbnail', 'seooptimierung-toolkit' ), 'image' ),
					self::field( 'uploadDate', __( 'Published', 'seooptimierung-toolkit' ), 'datetime' ),
					self::field( 'contentUrl', __( 'Video file URL', 'seooptimierung-toolkit' ), 'url' ),
					self::field( 'embedUrl', __( 'Embed URL', 'seooptimierung-toolkit' ), 'url', array( 'help' => __( 'e.g. https://www.youtube.com/embed/… — shown as the player.', 'seooptimierung-toolkit' ) ) ),
					self::field( 'duration', __( 'Length (seconds)', 'seooptimierung-toolkit' ), 'number' ),
					self::field( 'isLive', __( 'Live stream', 'seooptimierung-toolkit' ), 'toggle', array( 'help' => __( 'Marks the page as a live broadcast (BroadcastEvent).', 'seooptimierung-toolkit' ) ) ),
					self::field( 'liveStart', __( 'Live stream starts', 'seooptimierung-toolkit' ), 'datetime' ),
					self::field( 'liveEnd', __( 'Live stream ends', 'seooptimierung-toolkit' ), 'datetime' ),
				),
			),
		);

		$all = array_merge( $all, ExtraContentTypes::all() );

		return $all;
	}

	/**
	 * Builds one field definition.
	 *
	 * @param string              $key   Attribute name.
	 * @param string              $label Field label.
	 * @param string              $type  Field type.
	 * @param array<string,mixed> $extra Optional: options (select), help, placeholder.
	 * @return array<string,mixed>
	 */
	private static function field( string $key, string $label, string $type, array $extra = array() ): array {
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
	 * Definitions in the shape the editor script needs.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions_for_editor(): array {
		$out = array();

		foreach ( self::all() as $slug => $definition ) {
			$out[ $slug ] = array(
				'name'   => 'seooptimierung/' . $slug,
				'type'   => $definition['type'],
				'title'  => $definition['title'],
				'fields' => $definition['fields'],
			);
		}

		return $out;
	}

	/**
	 * Block attributes for a content type.
	 *
	 * @param string $slug Content type slug.
	 * @return array<string,array<string,mixed>>
	 */
	public static function block_attributes( string $slug ): array {
		$attributes = array();

		foreach ( self::all()[ $slug ]['fields'] ?? array() as $field ) {
			switch ( $field['type'] ) {
				case 'toggle':
					$attributes[ $field['key'] ] = array(
						'type'    => 'boolean',
						'default' => false,
					);
					break;
				case 'list':
					$attributes[ $field['key'] ] = array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					);
					break;
				case 'qa':
					$attributes[ $field['key'] ] = array(
						'type'    => 'array',
						'items'   => array( 'type' => 'object' ),
						'default' => array(),
					);
					break;
				default:
					$attributes[ $field['key'] ] = array(
						'type'    => 'string',
						'default' => '',
					);
			}
		}

		return $attributes;
	}

	/**
	 * Turns shortcode attributes into block attributes. Text fields map
	 * one to one; a list is given as a "|"-separated string; FAQ pairs as
	 * q1/a1, q2/a2, ...
	 *
	 * @param string               $slug Content type slug.
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return array<string,mixed>
	 */
	public static function attributes_from_shortcode( string $slug, array $atts ): array {
		$out = array();

		foreach ( self::all()[ $slug ]['fields'] ?? array() as $field ) {
			$key = $field['key'];

			if ( 'qa' === $field['type'] ) {
				$items = array();
				for ( $i = 1; $i <= 30; $i++ ) {
					if ( isset( $atts[ 'q' . $i ] ) && isset( $atts[ 'a' . $i ] ) ) {
						$items[] = array(
							'question' => (string) $atts[ 'q' . $i ],
							'answer'   => (string) $atts[ 'a' . $i ],
						);
					}
				}
				$out[ $key ] = $items;
			} elseif ( 'list' === $field['type'] ) {
				$out[ $key ] = isset( $atts[ $key ] ) ? array_map( 'trim', explode( '|', (string) $atts[ $key ] ) ) : array();
			} elseif ( 'toggle' === $field['type'] ) {
				$out[ $key ] = isset( $atts[ $key ] ) && filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
			} elseif ( isset( $atts[ $key ] ) ) {
				$out[ $key ] = (string) $atts[ $key ];
			}
		}

		return $out;
	}

	// -- Value helpers (pure) ----------------------------------------

	/**
	 * Non-empty, trimmed strings of a list value.
	 *
	 * @param mixed $value Raw attribute value.
	 * @return string[]
	 */
	public static function clean_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			$text = trim( wp_strip_all_tags( is_scalar( $item ) ? (string) $item : '' ) );
			if ( '' !== $text ) {
				$out[] = $text;
			}
		}

		return $out;
	}

	/**
	 * Complete question/answer pairs of a qa value.
	 *
	 * @param mixed $value Raw attribute value.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function clean_qa( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			$question = trim( wp_strip_all_tags( (string) ( is_array( $item ) ? ( $item['question'] ?? '' ) : '' ) ) );
			$answer   = trim( wp_kses_post( (string) ( is_array( $item ) ? ( $item['answer'] ?? '' ) : '' ) ) );

			if ( '' !== $question && '' !== trim( wp_strip_all_tags( $answer ) ) ) {
				$out[] = array(
					'question' => $question,
					'answer'   => $answer,
				);
			}
		}

		return $out;
	}

	/**
	 * ISO 8601 duration for a number of minutes ("PT1H30M"), or '' when
	 * not positive.
	 *
	 * @param mixed $minutes Minutes.
	 */
	public static function duration_from_minutes( $minutes ): string {
		$minutes = (int) $minutes;
		if ( $minutes <= 0 ) {
			return '';
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;

		return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $rest > 0 || 0 === $hours ? $rest . 'M' : '' );
	}

	/**
	 * ISO 8601 duration for a number of seconds ("PT1M30S"), or '' when
	 * not positive.
	 *
	 * @param mixed $seconds Seconds.
	 */
	public static function duration_from_seconds( $seconds ): string {
		$seconds = (int) $seconds;
		if ( $seconds <= 0 ) {
			return '';
		}

		$hours = intdiv( $seconds, 3600 );
		$mins  = intdiv( $seconds % 3600, 60 );
		$secs  = $seconds % 60;

		return 'PT' . ( $hours > 0 ? $hours . 'H' : '' ) . ( $mins > 0 ? $mins . 'M' : '' ) . ( $secs > 0 ? $secs . 'S' : '' );
	}

	/**
	 * ISO 8601 date-time for a "YYYY-MM-DDTHH:MM" (or "YYYY-MM-DD") value
	 * entered in the site's time zone; '' when it can't be read. A value
	 * that already carries an offset keeps it.
	 *
	 * @param string        $value Entered value.
	 * @param \DateTimeZone $zone  The site's time zone.
	 */
	public static function iso_datetime( string $value, \DateTimeZone $zone ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		try {
			$date = new \DateTimeImmutable( $value, $zone );
		} catch ( \Exception $e ) {
			return '';
		}

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $date->format( 'Y-m-d' ) : $date->format( 'c' );
	}

	/**
	 * Schema.org enum URL for a short name ("InStock").
	 *
	 * @param string $name Enumeration member.
	 */
	public static function enum_url( string $name ): string {
		return 'https://schema.org/' . preg_replace( '/[^A-Za-z]/', '', $name );
	}

	/**
	 * A string attribute, trimmed and stripped of tags.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $key   Attribute name.
	 */
	public static function text( array $attrs, string $key ): string {
		return trim( wp_strip_all_tags( isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ? (string) $attrs[ $key ] : '' ) );
	}

	/**
	 * A URL attribute, or ''.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @param string              $key   Attribute name.
	 */
	public static function url( array $attrs, string $key ): string {
		return esc_url_raw( trim( isset( $attrs[ $key ] ) && is_scalar( $attrs[ $key ] ) ? (string) $attrs[ $key ] : '' ) );
	}

	/**
	 * Whether a block has any content worth printing or marking up.
	 *
	 * @param string              $slug  Content type slug.
	 * @param array<string,mixed> $attrs Attributes.
	 */
	public static function has_content( string $slug, array $attrs ): bool {
		if ( ! isset( self::all()[ $slug ] ) ) {
			return false;
		}

		foreach ( self::all()[ $slug ]['fields'] as $field ) {
			$value = $attrs[ $field['key'] ] ?? null;

			if ( 'toggle' === $field['type'] || 'select' === $field['type'] ) {
				continue;
			}
			if ( 'qa' === $field['type'] && array() !== self::clean_qa( $value ) ) {
				return true;
			}
			if ( 'list' === $field['type'] && array() !== self::clean_list( $value ) ) {
				return true;
			}
			if ( ! in_array( $field['type'], array( 'qa', 'list' ), true ) && '' !== self::text( $attrs, $field['key'] ) ) {
				return true;
			}
		}

		return false;
	}

	// -- JSON-LD ----------------------------------------------------

	/**
	 * The JSON-LD node for one block, or null when it has nothing to say.
	 *
	 * @param string               $slug  Content type slug.
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param array<string,string> $ctx   Page context: url, title, author, image, published, modified.
	 * @param int                  $index Position among the page's blocks of the same kind (for the @id).
	 * @return array<string,mixed>|null
	 */
	public static function node( string $slug, array $attrs, array $ctx, int $index = 1 ): ?array {
		if ( ! self::has_content( $slug, $attrs ) ) {
			return null;
		}

		$zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$node = null;

		$definition = self::all()[ $slug ];

		if ( isset( $definition['build'] ) ) {
			$node = ExtraContentTypes::node( $definition, $attrs, $ctx, $zone );
		}

		switch ( $slug ) {
			case 'howto':
				$node = self::node_howto( $attrs, $ctx );
				break;
			case 'recipe':
				$node = self::node_recipe( $attrs, $ctx );
				break;
			case 'event':
				$node = self::node_event( $attrs, $ctx, $zone );
				break;
			case 'job-posting':
				$node = self::node_job( $attrs, $zone );
				break;
			case 'course':
				$node = self::node_course( $attrs );
				break;
			case 'software-application':
				$node = self::node_software( $attrs );
				break;
			case 'video':
				$node = self::node_video( $attrs, $ctx, $zone );
				break;
		}

		if ( null === $node ) {
			return null;
		}

		// A "patch" adds properties to the page's own node (paywall markup) instead of being a node of its own.
		if ( ! empty( $definition['patch'] ) ) {
			$node['@patch'] = true;

			return self::without_empty( $node );
		}

		$node['@id'] = ( $ctx['url'] ?? '' ) . '#' . $slug . ( $index > 1 ? '-' . $index : '' );

		// A QAPage or property listing is itself a kind of web page, so it is not "the main entity of" one.
		if ( empty( $definition['page'] ) ) {
			$node['mainEntityOfPage'] = array( '@id' => ( $ctx['url'] ?? '' ) . '#webpage' );
		}

		return self::without_empty( $node );
	}

	/**
	 * A node without its empty properties (false and 0 are kept).
	 *
	 * @param array<string,mixed> $node Node.
	 * @return array<string,mixed>
	 */
	private static function without_empty( array $node ): array {
		return array_filter(
			$node,
			static function ( $value ): bool {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * FAQPage main entities of an FAQ block.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public static function faq_entities( array $attrs ): array {
		$entities = array();

		foreach ( self::clean_qa( $attrs['items'] ?? array() ) as $pair ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $pair['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['answer'],
				),
			);
		}

		return $entities;
	}

	/**
	 * HowTo node.
	 *
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param array<string,string> $ctx   Page context.
	 * @return array<string,mixed>
	 */
	private static function node_howto( array $attrs, array $ctx ): array {
		$steps = array();
		foreach ( self::clean_list( $attrs['steps'] ?? array() ) as $position => $text ) {
			$steps[] = array(
				'@type'    => 'HowToStep',
				'position' => $position + 1,
				'text'     => $text,
			);
		}

		$supplies = array();
		foreach ( self::clean_list( $attrs['supplies'] ?? array() ) as $name ) {
			$supplies[] = array(
				'@type' => 'HowToSupply',
				'name'  => $name,
			);
		}

		$tools = array();
		foreach ( self::clean_list( $attrs['tools'] ?? array() ) as $name ) {
			$tools[] = array(
				'@type' => 'HowToTool',
				'name'  => $name,
			);
		}

		return array(
			'@type'       => 'HowTo',
			'name'        => '' !== self::text( $attrs, 'name' ) ? self::text( $attrs, 'name' ) : ( $ctx['title'] ?? '' ),
			'description' => self::text( $attrs, 'description' ),
			'image'       => self::url( $attrs, 'image' ),
			'totalTime'   => self::duration_from_minutes( $attrs['totalTime'] ?? 0 ),
			'supply'      => $supplies,
			'tool'        => $tools,
			'step'        => $steps,
		);
	}

	/**
	 * Recipe node.
	 *
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param array<string,string> $ctx   Page context.
	 * @return array<string,mixed>
	 */
	private static function node_recipe( array $attrs, array $ctx ): array {
		$steps = array();
		foreach ( self::clean_list( $attrs['steps'] ?? array() ) as $text ) {
			$steps[] = array(
				'@type' => 'HowToStep',
				'text'  => $text,
			);
		}

		$prep  = (int) ( $attrs['prepTime'] ?? 0 );
		$cook  = (int) ( $attrs['cookTime'] ?? 0 );
		$image = self::url( $attrs, 'image' );

		return array(
			'@type'              => 'Recipe',
			'name'               => '' !== self::text( $attrs, 'name' ) ? self::text( $attrs, 'name' ) : ( $ctx['title'] ?? '' ),
			'description'        => self::text( $attrs, 'description' ),
			'image'              => '' !== $image ? array( $image ) : ( '' !== ( $ctx['image'] ?? '' ) ? array( $ctx['image'] ) : array() ),
			'author'             => '' !== ( $ctx['author'] ?? '' ) ? array(
				'@type' => 'Person',
				'name'  => $ctx['author'],
			) : null,
			'datePublished'      => $ctx['published'] ?? '',
			'prepTime'           => self::duration_from_minutes( $prep ),
			'cookTime'           => self::duration_from_minutes( $cook ),
			'totalTime'          => self::duration_from_minutes( $prep + $cook ),
			'recipeYield'        => self::text( $attrs, 'recipeYield' ),
			'recipeCategory'     => self::text( $attrs, 'recipeCategory' ),
			'recipeCuisine'      => self::text( $attrs, 'recipeCuisine' ),
			'nutrition'          => '' !== self::text( $attrs, 'calories' ) ? array(
				'@type'    => 'NutritionInformation',
				'calories' => self::text( $attrs, 'calories' ),
			) : null,
			'recipeIngredient'   => self::clean_list( $attrs['ingredients'] ?? array() ),
			'recipeInstructions' => $steps,
		);
	}

	/**
	 * Event node.
	 *
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param array<string,string> $ctx   Page context.
	 * @param \DateTimeZone        $zone  Site time zone.
	 * @return array<string,mixed>
	 */
	private static function node_event( array $attrs, array $ctx, \DateTimeZone $zone ): array {
		$mode      = '' !== self::text( $attrs, 'attendanceMode' ) ? self::text( $attrs, 'attendanceMode' ) : 'OfflineEventAttendanceMode';
		$locations = array();

		if ( '' !== self::text( $attrs, 'locationName' ) || '' !== self::text( $attrs, 'address' ) ) {
			$locations[] = array_filter(
				array(
					'@type'   => 'Place',
					'name'    => self::text( $attrs, 'locationName' ),
					'address' => self::text( $attrs, 'address' ),
				)
			);
		}
		if ( '' !== self::url( $attrs, 'onlineUrl' ) ) {
			$locations[] = array(
				'@type' => 'VirtualLocation',
				'url'   => self::url( $attrs, 'onlineUrl' ),
			);
		}

		$offers = null;
		if ( '' !== self::text( $attrs, 'price' ) ) {
			$offers = array_filter(
				array(
					'@type'         => 'Offer',
					'price'         => self::text( $attrs, 'price' ),
					'priceCurrency' => '' !== self::text( $attrs, 'currency' ) ? strtoupper( self::text( $attrs, 'currency' ) ) : 'EUR',
					'url'           => self::url( $attrs, 'ticketUrl' ),
					'availability'  => self::enum_url( 'InStock' ),
				)
			);
		}

		$image = self::url( $attrs, 'image' );

		return array(
			'@type'               => 'Event',
			'name'                => self::text( $attrs, 'name' ) !== '' ? self::text( $attrs, 'name' ) : ( $ctx['title'] ?? '' ),
			'description'         => self::text( $attrs, 'description' ),
			'startDate'           => self::iso_datetime( self::text( $attrs, 'startDate' ), $zone ),
			'endDate'             => self::iso_datetime( self::text( $attrs, 'endDate' ), $zone ),
			'eventStatus'         => self::enum_url( '' !== self::text( $attrs, 'status' ) ? self::text( $attrs, 'status' ) : 'EventScheduled' ),
			'eventAttendanceMode' => self::enum_url( $mode ),
			'location'            => 1 === count( $locations ) ? $locations[0] : $locations,
			'organizer'           => '' !== self::text( $attrs, 'organizer' ) ? array_filter(
				array(
					'@type' => 'Organization',
					'name'  => self::text( $attrs, 'organizer' ),
					'url'   => self::url( $attrs, 'organizerUrl' ),
				)
			) : null,
			'image'               => '' !== $image ? array( $image ) : array(),
			'offers'              => $offers,
		);
	}

	/**
	 * JobPosting node.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @param \DateTimeZone       $zone  Site time zone.
	 * @return array<string,mixed>
	 */
	private static function node_job( array $attrs, \DateTimeZone $zone ): array {
		$address     = array_filter(
			array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => self::text( $attrs, 'streetAddress' ),
				'postalCode'      => self::text( $attrs, 'postalCode' ),
				'addressLocality' => self::text( $attrs, 'locality' ),
				'addressRegion'   => self::text( $attrs, 'region' ),
				'addressCountry'  => strtoupper( self::text( $attrs, 'country' ) ),
			)
		);
		$has_address = '' !== self::text( $attrs, 'streetAddress' ) || '' !== self::text( $attrs, 'locality' ) || '' !== self::text( $attrs, 'postalCode' );
		$remote      = ! empty( $attrs['remote'] );

		$min = self::text( $attrs, 'salaryMin' );
		$max = self::text( $attrs, 'salaryMax' );
		$pay = null;
		if ( '' !== $min || '' !== $max ) {
			$value = array(
				'@type'    => 'QuantitativeValue',
				'unitText' => '' !== self::text( $attrs, 'salaryUnit' ) ? self::text( $attrs, 'salaryUnit' ) : 'YEAR',
			);
			if ( '' !== $min && '' !== $max ) {
				$value['minValue'] = (float) $min;
				$value['maxValue'] = (float) $max;
			} else {
				$value['value'] = (float) ( '' !== $min ? $min : $max );
			}
			$pay = array(
				'@type'    => 'MonetaryAmount',
				'currency' => '' !== self::text( $attrs, 'salaryCurrency' ) ? strtoupper( self::text( $attrs, 'salaryCurrency' ) ) : 'EUR',
				'value'    => $value,
			);
		}

		$logo = self::url( $attrs, 'companyLogo' );

		return array(
			'@type'                         => 'JobPosting',
			'title'                         => self::text( $attrs, 'title' ),
			'description'                   => trim( wp_kses_post( wpautop( (string) ( $attrs['description'] ?? '' ) ) ) ),
			'datePosted'                    => self::iso_datetime( self::text( $attrs, 'datePosted' ), $zone ),
			'validThrough'                  => self::iso_datetime( self::text( $attrs, 'validThrough' ), $zone ),
			'employmentType'                => self::text( $attrs, 'employmentType' ),
			'hiringOrganization'            => '' !== self::text( $attrs, 'company' ) ? array_filter(
				array(
					'@type'  => 'Organization',
					'name'   => self::text( $attrs, 'company' ),
					'sameAs' => self::url( $attrs, 'companyUrl' ),
					'logo'   => $logo,
				)
			) : null,
			'jobLocationType'               => $remote ? 'TELECOMMUTE' : null,
			'applicantLocationRequirements' => $remote && '' !== self::text( $attrs, 'country' ) ? array(
				'@type' => 'Country',
				'name'  => strtoupper( self::text( $attrs, 'country' ) ),
			) : null,
			'jobLocation'                   => $has_address ? array(
				'@type'   => 'Place',
				'address' => $address,
			) : null,
			'baseSalary'                    => $pay,
			'directApply'                   => '' !== self::url( $attrs, 'applyUrl' ) ? true : null,
		);
	}

	/**
	 * Course node.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array<string,mixed>
	 */
	private static function node_course( array $attrs ): array {
		return array(
			'@type'       => 'Course',
			'name'        => self::text( $attrs, 'name' ),
			'description' => self::text( $attrs, 'description' ),
			'provider'    => '' !== self::text( $attrs, 'provider' ) ? array_filter(
				array(
					'@type'  => 'Organization',
					'name'   => self::text( $attrs, 'provider' ),
					'sameAs' => self::url( $attrs, 'providerUrl' ),
				)
			) : null,
		);
	}

	/**
	 * SoftwareApplication node.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 * @return array<string,mixed>
	 */
	private static function node_software( array $attrs ): array {
		$offers = null;
		if ( '' !== self::text( $attrs, 'price' ) ) {
			$offers = array_filter(
				array(
					'@type'         => 'Offer',
					'price'         => self::text( $attrs, 'price' ),
					'priceCurrency' => '' !== self::text( $attrs, 'currency' ) ? strtoupper( self::text( $attrs, 'currency' ) ) : 'EUR',
					'url'           => self::url( $attrs, 'downloadUrl' ),
				)
			);
		}

		$rating = null;
		if ( '' !== self::text( $attrs, 'ratingValue' ) && (int) self::text( $attrs, 'ratingCount' ) > 0 ) {
			$rating = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) self::text( $attrs, 'ratingValue' ),
				'ratingCount' => (int) self::text( $attrs, 'ratingCount' ),
			);
		}

		$image = self::url( $attrs, 'image' );

		return array(
			'@type'               => 'SoftwareApplication',
			'name'                => self::text( $attrs, 'name' ),
			'description'         => self::text( $attrs, 'description' ),
			'image'               => $image,
			'applicationCategory' => self::text( $attrs, 'category' ),
			'operatingSystem'     => self::text( $attrs, 'operatingSystem' ),
			'offers'              => $offers,
			'aggregateRating'     => $rating,
		);
	}

	/**
	 * VideoObject node (with a BroadcastEvent for live streams).
	 *
	 * @param array<string,mixed>  $attrs Attributes.
	 * @param array<string,string> $ctx   Page context.
	 * @param \DateTimeZone        $zone  Site time zone.
	 * @return array<string,mixed>
	 */
	private static function node_video( array $attrs, array $ctx, \DateTimeZone $zone ): array {
		$live      = ! empty( $attrs['isLive'] );
		$thumbnail = self::url( $attrs, 'thumbnail' );

		$publication = null;
		if ( $live ) {
			$publication = array_filter(
				array(
					'@type'           => 'BroadcastEvent',
					'isLiveBroadcast' => true,
					'startDate'       => self::iso_datetime( self::text( $attrs, 'liveStart' ), $zone ),
					'endDate'         => self::iso_datetime( self::text( $attrs, 'liveEnd' ), $zone ),
				)
			);
		}

		return array(
			'@type'        => 'VideoObject',
			'name'         => '' !== self::text( $attrs, 'name' ) ? self::text( $attrs, 'name' ) : ( $ctx['title'] ?? '' ),
			'description'  => self::text( $attrs, 'description' ),
			'thumbnailUrl' => '' !== $thumbnail ? array( $thumbnail ) : array(),
			'uploadDate'   => self::iso_datetime( self::text( $attrs, 'uploadDate' ), $zone ),
			'contentUrl'   => self::url( $attrs, 'contentUrl' ),
			'embedUrl'     => self::url( $attrs, 'embedUrl' ),
			'duration'     => self::duration_from_seconds( $attrs['duration'] ?? 0 ),
			'publication'  => $publication,
		);
	}

	// -- Visible markup -------------------------------------------------

	/**
	 * The visible markup of one block, or '' when it is empty.
	 *
	 * @param string              $slug  Content type slug.
	 * @param array<string,mixed> $attrs Attributes.
	 */
	public static function html( string $slug, array $attrs ): string {
		if ( ! self::has_content( $slug, $attrs ) ) {
			return '';
		}

		$inner      = '';
		$definition = self::all()[ $slug ];

		if ( isset( $definition['build'] ) ) {
			$inner = ExtraContentTypes::html( $definition, $attrs );
		}

		switch ( $slug ) {
			case 'faq':
				$inner = self::html_faq( $attrs );
				break;
			case 'howto':
				$inner = self::html_howto( $attrs );
				break;
			case 'recipe':
				$inner = self::html_recipe( $attrs );
				break;
			case 'event':
				$inner = self::html_event( $attrs );
				break;
			case 'job-posting':
				$inner = self::html_job( $attrs );
				break;
			case 'course':
				$inner = self::html_course( $attrs );
				break;
			case 'software-application':
				$inner = self::html_software( $attrs );
				break;
			case 'video':
				$inner = self::html_video( $attrs );
				break;
		}

		if ( '' === $inner ) {
			return '';
		}

		/**
		 * Filters the visible markup of a structured-data content block.
		 *
		 * @param string              $html  Markup.
		 * @param string              $slug  Content type slug (faq, recipe, ...).
		 * @param array<string,mixed> $attrs Block attributes.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		return (string) apply_filters( 'seooptimierung/schema/block_html', '<div class="seooptimierung-schema seooptimierung-schema--' . esc_attr( $slug ) . '">' . $inner . '</div>', $slug, $attrs );
	}

	/**
	 * A title line.
	 *
	 * @param string $title Title text.
	 */
	private static function title( string $title ): string {
		return '' !== $title ? '<p class="seooptimierung-schema__title">' . esc_html( $title ) . '</p>' : '';
	}

	/**
	 * A paragraph from a plain-text value.
	 *
	 * @param string $text Text.
	 */
	private static function paragraph( string $text ): string {
		return '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '';
	}

	/**
	 * An unordered or ordered list.
	 *
	 * @param string[] $items Items.
	 * @param bool     $ordered Whether to number it.
	 */
	private static function list_html( array $items, bool $ordered = false ): string {
		if ( array() === $items ) {
			return '';
		}

		$tag = $ordered ? 'ol' : 'ul';

		return '<' . $tag . ' class="seooptimierung-schema__list"><li>' . implode( '</li><li>', array_map( 'esc_html', $items ) ) . '</li></' . $tag . '>';
	}

	/**
	 * A definition list from label => value pairs (empty values skipped).
	 *
	 * @param array<string,string> $rows Label => HTML-safe value.
	 */
	private static function rows( array $rows ): string {
		$out = '';

		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				$out .= '<dt>' . esc_html( (string) $label ) . '</dt><dd>' . $value . '</dd>';
			}
		}

		return '' !== $out ? '<dl>' . $out . '</dl>' : '';
	}

	/**
	 * A human minutes string ("1 h 30 min").
	 *
	 * @param int $minutes Minutes.
	 */
	public static function format_minutes( int $minutes ): string {
		if ( $minutes <= 0 ) {
			return '';
		}

		$hours = intdiv( $minutes, 60 );
		$rest  = $minutes % 60;

		return trim( ( $hours > 0 ? $hours . ' h ' : '' ) . ( $rest > 0 ? $rest . ' min' : '' ) );
	}

	/**
	 * FAQ markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_faq( array $attrs ): string {
		$out = '<div class="seooptimierung-schema__faq">';

		foreach ( self::clean_qa( $attrs['items'] ?? array() ) as $pair ) {
			$out .= '<h3>' . esc_html( $pair['question'] ) . '</h3><div>' . wp_kses_post( wpautop( $pair['answer'] ) ) . '</div>';
		}

		return $out . '</div>';
	}

	/**
	 * HowTo markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_howto( array $attrs ): string {
		$out  = self::title( self::text( $attrs, 'name' ) ) . self::paragraph( self::text( $attrs, 'description' ) );
		$time = self::format_minutes( (int) ( $attrs['totalTime'] ?? 0 ) );

		if ( '' !== $time ) {
			$out .= '<ul class="seooptimierung-schema__meta"><li>' . esc_html__( 'Time:', 'seooptimierung-toolkit' ) . ' ' . esc_html( $time ) . '</li></ul>';
		}
		if ( array() !== self::clean_list( $attrs['supplies'] ?? array() ) ) {
			$out .= '<h3>' . esc_html__( 'Materials', 'seooptimierung-toolkit' ) . '</h3>' . self::list_html( self::clean_list( $attrs['supplies'] ) );
		}
		if ( array() !== self::clean_list( $attrs['tools'] ?? array() ) ) {
			$out .= '<h3>' . esc_html__( 'Tools', 'seooptimierung-toolkit' ) . '</h3>' . self::list_html( self::clean_list( $attrs['tools'] ) );
		}
		if ( array() !== self::clean_list( $attrs['steps'] ?? array() ) ) {
			$out .= '<h3>' . esc_html__( 'Steps', 'seooptimierung-toolkit' ) . '</h3>' . self::list_html( self::clean_list( $attrs['steps'] ), true );
		}

		return $out;
	}

	/**
	 * Recipe markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_recipe( array $attrs ): string {
		$meta = array();
		foreach ( array(
			__( 'Prep:', 'seooptimierung-toolkit' )     => self::format_minutes( (int) ( $attrs['prepTime'] ?? 0 ) ),
			__( 'Cook:', 'seooptimierung-toolkit' )     => self::format_minutes( (int) ( $attrs['cookTime'] ?? 0 ) ),
			__( 'Yield:', 'seooptimierung-toolkit' )    => self::text( $attrs, 'recipeYield' ),
			__( 'Calories:', 'seooptimierung-toolkit' ) => self::text( $attrs, 'calories' ),
		) as $label => $value ) {
			if ( '' !== $value ) {
				$meta[] = '<li>' . esc_html( $label ) . ' ' . esc_html( $value ) . '</li>';
			}
		}

		$out = self::title( self::text( $attrs, 'name' ) ) . self::paragraph( self::text( $attrs, 'description' ) );
		if ( array() !== $meta ) {
			$out .= '<ul class="seooptimierung-schema__meta">' . implode( '', $meta ) . '</ul>';
		}
		if ( array() !== self::clean_list( $attrs['ingredients'] ?? array() ) ) {
			$out .= '<h3>' . esc_html__( 'Ingredients', 'seooptimierung-toolkit' ) . '</h3>' . self::list_html( self::clean_list( $attrs['ingredients'] ) );
		}
		if ( array() !== self::clean_list( $attrs['steps'] ?? array() ) ) {
			$out .= '<h3>' . esc_html__( 'Instructions', 'seooptimierung-toolkit' ) . '</h3>' . self::list_html( self::clean_list( $attrs['steps'] ), true );
		}

		return $out;
	}

	/**
	 * Event markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_event( array $attrs ): string {
		$link  = '' !== self::url( $attrs, 'ticketUrl' ) ? '<a href="' . esc_url( self::url( $attrs, 'ticketUrl' ) ) . '">' . esc_html__( 'Tickets', 'seooptimierung-toolkit' ) . '</a>' : '';
		$web   = '' !== self::url( $attrs, 'onlineUrl' ) ? '<a href="' . esc_url( self::url( $attrs, 'onlineUrl' ) ) . '">' . esc_html( self::url( $attrs, 'onlineUrl' ) ) . '</a>' : '';
		$venue = trim( self::text( $attrs, 'locationName' ) . ( '' !== self::text( $attrs, 'address' ) ? ', ' . self::text( $attrs, 'address' ) : '' ), ', ' );

		return self::title( self::text( $attrs, 'name' ) ) . self::paragraph( self::text( $attrs, 'description' ) ) . self::rows(
			array(
				__( 'Starts', 'seooptimierung-toolkit' )  => esc_html( str_replace( 'T', ' ', self::text( $attrs, 'startDate' ) ) ),
				__( 'Ends', 'seooptimierung-toolkit' )    => esc_html( str_replace( 'T', ' ', self::text( $attrs, 'endDate' ) ) ),
				__( 'Venue', 'seooptimierung-toolkit' )   => esc_html( $venue ),
				__( 'Online', 'seooptimierung-toolkit' )  => $web,
				__( 'Organizer', 'seooptimierung-toolkit' ) => esc_html( self::text( $attrs, 'organizer' ) ),
				__( 'Price', 'seooptimierung-toolkit' )   => esc_html( trim( self::text( $attrs, 'price' ) . ' ' . ( '' !== self::text( $attrs, 'price' ) ? strtoupper( self::text( $attrs, 'currency' ) ) : '' ) ) ),
				__( 'Tickets', 'seooptimierung-toolkit' ) => $link,
			)
		);
	}

	/**
	 * JobPosting markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_job( array $attrs ): string {
		$place = trim( implode( ', ', array_filter( array( self::text( $attrs, 'streetAddress' ), trim( self::text( $attrs, 'postalCode' ) . ' ' . self::text( $attrs, 'locality' ) ), self::text( $attrs, 'region' ), strtoupper( self::text( $attrs, 'country' ) ) ) ) ) );
		if ( ! empty( $attrs['remote'] ) ) {
			$place = trim( __( 'Remote', 'seooptimierung-toolkit' ) . ( '' !== $place ? ' · ' . $place : '' ) );
		}

		$salary = '';
		$min    = self::text( $attrs, 'salaryMin' );
		$max    = self::text( $attrs, 'salaryMax' );
		if ( '' !== $min || '' !== $max ) {
			$salary = ( '' !== $min && '' !== $max ? $min . ' – ' . $max : ( '' !== $min ? $min : $max ) ) . ' ' . ( '' !== self::text( $attrs, 'salaryCurrency' ) ? strtoupper( self::text( $attrs, 'salaryCurrency' ) ) : 'EUR' ) . ' / ' . strtolower( '' !== self::text( $attrs, 'salaryUnit' ) ? self::text( $attrs, 'salaryUnit' ) : 'YEAR' );
		}

		$apply = '' !== self::url( $attrs, 'applyUrl' ) ? '<a href="' . esc_url( self::url( $attrs, 'applyUrl' ) ) . '">' . esc_html__( 'Apply now', 'seooptimierung-toolkit' ) . '</a>' : '';

		return self::title( self::text( $attrs, 'title' ) ) . self::rows(
			array(
				__( 'Company', 'seooptimierung-toolkit' )  => esc_html( self::text( $attrs, 'company' ) ),
				__( 'Location', 'seooptimierung-toolkit' ) => esc_html( $place ),
				__( 'Type', 'seooptimierung-toolkit' )     => esc_html( str_replace( '_', ' ', strtolower( self::text( $attrs, 'employmentType' ) ) ) ),
				__( 'Salary', 'seooptimierung-toolkit' )   => esc_html( $salary ),
				__( 'Posted', 'seooptimierung-toolkit' )   => esc_html( self::text( $attrs, 'datePosted' ) ),
				__( 'Apply until', 'seooptimierung-toolkit' ) => esc_html( self::text( $attrs, 'validThrough' ) ),
			)
		) . wp_kses_post( wpautop( (string) ( $attrs['description'] ?? '' ) ) ) . ( '' !== $apply ? '<p>' . $apply . '</p>' : '' );
	}

	/**
	 * Course markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_course( array $attrs ): string {
		$provider = self::text( $attrs, 'provider' );
		$url      = self::url( $attrs, 'providerUrl' );

		return self::title( self::text( $attrs, 'name' ) ) . self::paragraph( self::text( $attrs, 'description' ) ) . self::rows(
			array(
				__( 'Provider', 'seooptimierung-toolkit' ) => '' !== $url && '' !== $provider ? '<a href="' . esc_url( $url ) . '">' . esc_html( $provider ) . '</a>' : esc_html( $provider ),
			)
		);
	}

	/**
	 * SoftwareApplication markup.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_software( array $attrs ): string {
		$download = '' !== self::url( $attrs, 'downloadUrl' ) ? '<a href="' . esc_url( self::url( $attrs, 'downloadUrl' ) ) . '">' . esc_html__( 'Download', 'seooptimierung-toolkit' ) . '</a>' : '';
		$price    = '' !== self::text( $attrs, 'price' ) ? trim( self::text( $attrs, 'price' ) . ' ' . strtoupper( self::text( $attrs, 'currency' ) ) ) : '';
		$rating   = '' !== self::text( $attrs, 'ratingValue' ) && (int) self::text( $attrs, 'ratingCount' ) > 0 ? self::text( $attrs, 'ratingValue' ) . ' / 5 (' . (int) self::text( $attrs, 'ratingCount' ) . ')' : '';

		return self::title( self::text( $attrs, 'name' ) ) . self::paragraph( self::text( $attrs, 'description' ) ) . self::rows(
			array(
				__( 'Operating system', 'seooptimierung-toolkit' ) => esc_html( self::text( $attrs, 'operatingSystem' ) ),
				__( 'Price', 'seooptimierung-toolkit' )    => esc_html( $price ),
				__( 'Rating', 'seooptimierung-toolkit' )   => esc_html( $rating ),
				__( 'Download', 'seooptimierung-toolkit' ) => $download,
			)
		);
	}

	/**
	 * Video markup: the player when there is an embed URL or file.
	 *
	 * @param array<string,mixed> $attrs Attributes.
	 */
	private static function html_video( array $attrs ): string {
		$embed = self::url( $attrs, 'embedUrl' );
		$file  = self::url( $attrs, 'contentUrl' );
		$thumb = self::url( $attrs, 'thumbnail' );

		$player = '';
		if ( '' !== $embed ) {
			$player = '<div class="seooptimierung-schema__player"><iframe src="' . esc_url( $embed ) . '" title="' . esc_attr( self::text( $attrs, 'name' ) ) . '" loading="lazy" allowfullscreen style="width:100%;aspect-ratio:16/9;border:0"></iframe></div>';
		} elseif ( '' !== $file ) {
			$player = '<video controls preload="none"' . ( '' !== $thumb ? ' poster="' . esc_url( $thumb ) . '"' : '' ) . ' style="width:100%" src="' . esc_url( $file ) . '"></video>';
		}

		return self::title( self::text( $attrs, 'name' ) ) . $player . self::paragraph( self::text( $attrs, 'description' ) );
	}
}

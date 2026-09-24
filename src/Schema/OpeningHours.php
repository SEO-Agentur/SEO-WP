<?php
/**
 * Parses the Settings > General "Opening hours" field into
 * OpeningHoursSpecification nodes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Schema;

/**
 * Deliberately supports one narrow, unambiguous format rather than
 * free-form text a schema consumer would have to guess at: one rule per
 * line, comma-separated two-letter day codes, a space, then a 24-hour
 * HH:MM-HH:MM range — e.g. "Mo,Tu,We,Th,Fr 09:00-18:00". No day ranges
 * (no "Mo-Fr"): a range's boundaries (does it include both ends? what
 * order do days come in for a site in another locale?) is exactly the
 * kind of ambiguity worth avoiding by just listing the days out.
 */
final class OpeningHours {

	/**
	 * Two-letter day code => schema.org day name.
	 *
	 * @var array<string,string>
	 */
	private const DAYS = array(
		'mo' => 'Monday',
		'tu' => 'Tuesday',
		'we' => 'Wednesday',
		'th' => 'Thursday',
		'fr' => 'Friday',
		'sa' => 'Saturday',
		'su' => 'Sunday',
	);

	/**
	 * Parses every valid line into an OpeningHoursSpecification node;
	 * invalid lines are silently skipped rather than breaking the whole
	 * field over one typo.
	 *
	 * @param string $text Raw field content, one rule per line.
	 * @return array<int,array{'@type':string,dayOfWeek:string[],opens:string,closes:string}>
	 */
	public static function parse( string $text ): array {
		$specs = array();

		foreach ( preg_split( '/\r\n|\r|\n/', trim( $text ) ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}

			$spec = self::parse_line( $line );
			if ( null !== $spec ) {
				$specs[] = $spec;
			}
		}

		return $specs;
	}

	/**
	 * Parses a single line, or returns null when it doesn't match the
	 * expected format or names no recognised day.
	 *
	 * @param string $line One line of the field.
	 * @return array{'@type':string,dayOfWeek:string[],opens:string,closes:string}|null
	 */
	private static function parse_line( string $line ): ?array {
		if ( ! preg_match( '/^([A-Za-z,]+)\s+(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $line, $matches ) ) {
			return null;
		}

		$days = array();
		foreach ( explode( ',', $matches[1] ) as $day ) {
			$key = strtolower( trim( $day ) );
			if ( isset( self::DAYS[ $key ] ) ) {
				$days[] = 'https://schema.org/' . self::DAYS[ $key ];
			}
		}

		if ( array() === $days ) {
			return null;
		}

		return array(
			'@type'     => 'OpeningHoursSpecification',
			'dayOfWeek' => $days,
			'opens'     => $matches[2],
			'closes'    => $matches[3],
		);
	}
}

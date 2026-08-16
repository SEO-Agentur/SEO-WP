<?php
/**
 * Title/meta description length classification.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

/**
 * The single source of truth for the title/description "traffic light"
 * bands shown in the Bulk Editor, the classic metabox and the block
 * editor sidebar — a character count outside the band is likely to be
 * truncated (too long) or under-informative (too short) in a Google
 * search result. Purely informational everywhere it's used: nothing
 * reads this to withhold a save or otherwise change behaviour.
 */
final class LengthAdvisor {

	/**
	 * Bands, in characters, per field.
	 *
	 * @var array<string,array{min:int,max:int}>
	 */
	private const BANDS = array(
		'title'       => array(
			'min' => 30,
			'max' => 60,
		),
		'description' => array(
			'min' => 70,
			'max' => 155,
		),
	);

	/**
	 * Classifies a length for a field: 'empty' (zero characters), 'short'
	 * (below the band), 'long' (above it), or 'good' (inside it, or an
	 * unknown field with no band defined).
	 *
	 * @param string $field  'title' or 'description'.
	 * @param int    $length Character count.
	 */
	public static function status( string $field, int $length ): string {
		$band = self::BANDS[ $field ] ?? null;

		if ( null === $band ) {
			return 'good';
		}

		if ( 0 === $length ) {
			return 'empty';
		}

		if ( $length < $band['min'] ) {
			return 'short';
		}

		if ( $length > $band['max'] ) {
			return 'long';
		}

		return 'good';
	}

	/**
	 * Every field's band, for handing to JS via wp_localize_script so the
	 * client-side live badge uses the exact same thresholds instead of a
	 * second, hand-copied set of numbers.
	 *
	 * @return array<string,array{min:int,max:int}>
	 */
	public static function bands(): array {
		return self::BANDS;
	}
}

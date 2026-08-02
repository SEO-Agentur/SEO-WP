<?php
/**
 * Central error recording for risky operations (import mapping, direct
 * database queries) so failures are surfaced in the admin instead of
 * disappearing silently.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Support;

use Throwable;

/**
 * A small, persistent log of things that went wrong: a caught exception
 * during import mapping, a database error from one of the direct queries
 * Import needs, a JSON decode failure in a source's stored data. Not a
 * general application log — only failures worth a site owner's attention.
 */
final class ErrorLog {

	/**
	 * Option key the log is stored under.
	 */
	private const OPTION_KEY = 'seo_wp_error_log';

	/**
	 * Oldest entries are dropped once the log exceeds this size.
	 */
	private const MAX_ENTRIES = 100;

	/**
	 * How long an entry counts as "recent" for the admin notice.
	 */
	private const RECENT_WINDOW = DAY_IN_SECONDS;

	/**
	 * Runs $callback and records any thrown Throwable instead of letting
	 * it abort the caller (e.g. one bad row shouldn't fail an entire
	 * import batch). Returns $callback's result, or null if it threw.
	 *
	 * @param string   $context  Short identifier for where this happened, e.g. "import:yoast:post:123".
	 * @param callable $callback Operation to run.
	 * @return mixed
	 */
	public static function capture( string $context, callable $callback ) {
		try {
			return $callback();
		} catch ( Throwable $exception ) {
			self::record(
				$context,
				$exception->getMessage(),
				array(
					'exception' => get_class( $exception ),
					'file'      => $exception->getFile() . ':' . $exception->getLine(),
				)
			);

			return null;
		}
	}

	/**
	 * Records an entry.
	 *
	 * @param string              $context Short identifier for where this happened.
	 * @param string              $message What went wrong.
	 * @param array<string,mixed> $data    Optional extra detail.
	 */
	public static function record( string $context, string $message, array $data = array() ): void {
		$log   = self::all();
		$log[] = array(
			'time'    => time(),
			'context' => $context,
			'message' => $message,
			'data'    => $data,
		);

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}

		update_option( self::OPTION_KEY, $log, false );
	}

	/**
	 * Every recorded entry, oldest first.
	 *
	 * @return array<int,array{time:int,context:string,message:string,data:array<string,mixed>}>
	 */
	public static function all(): array {
		$log = get_option( self::OPTION_KEY, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Whether any entry was recorded within the last day, for the admin
	 * notice.
	 */
	public static function has_recent(): bool {
		$log = self::all();
		if ( array() === $log ) {
			return false;
		}

		$last = end( $log );
		$time = isset( $last['time'] ) ? (int) $last['time'] : 0;

		return $time > 0 && ( time() - $time ) < self::RECENT_WINDOW;
	}

	/**
	 * Clears the log.
	 */
	public static function clear(): void {
		delete_option( self::OPTION_KEY );
	}
}

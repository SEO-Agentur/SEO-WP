<?php
/**
 * Guards AJAX JSON responses against stray output corrupting them.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

/**
 * A PHP notice/warning/deprecation triggered anywhere during a batch step
 * (a third-party plugin's hook callback, an unusual value in a source
 * SEO plugin's postmeta, anything) gets echoed straight into the
 * response body if display_errors is on — turning valid JSON into
 * "<br />&lt;b&gt;Warning&lt;/b&gt;... {"success":true,...}", which
 * every one of this plugin's batch-loop scripts (import, duplicate
 * scan, link audit, bulk-edit AI) then fails to parse and reports as
 * "something went wrong", stopping a multi-page loop partway through
 * with no useful error. begin() captures everything printed from that
 * point on into a buffer this plugin controls; success()/error() pull it
 * back out right before finally handing a clean response to
 * wp_send_json_*, so the batch itself never sees corrupted JSON — but
 * unlike simply discarding it, anything actually captured is recorded via
 * ErrorLog first, so a stray warning still surfaces on the Diagnostics
 * page instead of vanishing with no trace of what went wrong or where.
 */
final class Ajax {

	/**
	 * The output buffering level begin() started from, so
	 * flush_stray_output() only ever pops buffers this class itself
	 * added — never a buffer some other plugin or WordPress core already
	 * had open before this request reached us.
	 *
	 * @var int
	 */
	private static int $baseline = 0;

	/**
	 * Short identifier for whichever handler called begin(), used as the
	 * ErrorLog context if anything stray gets captured.
	 *
	 * @var string
	 */
	private static string $context = 'ajax';

	/**
	 * Starts capturing all output from this point on. Call as the very
	 * first line of an AJAX handler.
	 *
	 * @param string $context Short identifier for this handler, e.g. 'import:batch' — recorded in ErrorLog if anything stray is captured.
	 */
	public static function begin( string $context = 'ajax' ): void {
		self::$context  = $context;
		self::$baseline = ob_get_level();
		ob_start();
	}

	/**
	 * Recovers captured output (logging it if non-empty), then sends a
	 * JSON success response (terminates the request, matching
	 * wp_send_json_success()).
	 *
	 * @param mixed $data Data to encode as the response.
	 */
	public static function success( $data = null ): void {
		self::flush_stray_output();
		wp_send_json_success( $data );
	}

	/**
	 * Recovers captured output (logging it if non-empty), then sends a
	 * JSON error response (terminates the request, matching
	 * wp_send_json_error()).
	 *
	 * @param mixed    $data        Data to encode as the response.
	 * @param int|null $status_code HTTP status code to send, if any.
	 */
	public static function error( $data = null, ?int $status_code = null ): void {
		self::flush_stray_output();
		wp_send_json_error( $data, $status_code );
	}

	/**
	 * Pops every output buffer opened since begin(). Whatever they
	 * captured is removed from the response either way (that's the whole
	 * point — it can't be allowed to reach the JSON body), but if it's
	 * non-empty it's recorded via ErrorLog first, so it's visible on the
	 * Diagnostics page rather than simply disappearing.
	 */
	private static function flush_stray_output(): void {
		$captured = '';

		while ( ob_get_level() > self::$baseline ) {
			$captured .= (string) ob_get_clean();
		}

		$captured = trim( $captured );
		if ( '' === $captured ) {
			return;
		}

		ErrorLog::record(
			'ajax:' . self::$context,
			__( 'PHP printed unexpected output during an AJAX request (typically a warning, notice or deprecation) — it was stripped from the JSON response so the request could still complete, but is recorded here for diagnosis.', 'seooptimierung-toolkit' ),
			array( 'output' => mb_substr( $captured, 0, 2000 ) )
		);
	}
}

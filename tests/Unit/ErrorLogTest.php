<?php
/**
 * Tests for the persistent error log used across import and detection.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Support\ErrorLog;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Support\ErrorLog
 */
final class ErrorLogTest extends TestCase {

	/**
	 * Resets the in-memory option store between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['seo_wp_test_options'] = array();
	}

	/**
	 * A fresh log is empty and reports no recent entries.
	 */
	public function test_starts_empty(): void {
		$this->assertSame( array(), ErrorLog::all() );
		$this->assertFalse( ErrorLog::has_recent() );
	}

	/**
	 * record() appends an entry with context, message and data.
	 */
	public function test_record_appends_an_entry(): void {
		ErrorLog::record( 'import:yoast:post:5', 'Something went wrong.', array( 'foo' => 'bar' ) );

		$log = ErrorLog::all();
		$this->assertCount( 1, $log );
		$this->assertSame( 'import:yoast:post:5', $log[0]['context'] );
		$this->assertSame( 'Something went wrong.', $log[0]['message'] );
		$this->assertSame( array( 'foo' => 'bar' ), $log[0]['data'] );
		$this->assertTrue( ErrorLog::has_recent() );
	}

	/**
	 * capture() returns the callback's result when it doesn't throw.
	 */
	public function test_capture_returns_result_on_success(): void {
		$result = ErrorLog::capture(
			'test',
			static function (): string {
				return 'ok';
			}
		);

		$this->assertSame( 'ok', $result );
		$this->assertSame( array(), ErrorLog::all() );
	}

	/**
	 * capture() records a thrown exception and returns null instead of
	 * letting it propagate — one bad item must not abort a whole batch.
	 */
	public function test_capture_records_thrown_exception_and_returns_null(): void {
		$result = ErrorLog::capture(
			'import:rank_math:post:9',
			static function (): void {
				throw new \RuntimeException( 'Unexpected data shape.' );
			}
		);

		$this->assertNull( $result );

		$log = ErrorLog::all();
		$this->assertCount( 1, $log );
		$this->assertSame( 'import:rank_math:post:9', $log[0]['context'] );
		$this->assertSame( 'Unexpected data shape.', $log[0]['message'] );
		$this->assertSame( \RuntimeException::class, $log[0]['data']['exception'] );
	}

	/**
	 * clear() empties the log.
	 */
	public function test_clear_empties_the_log(): void {
		ErrorLog::record( 'a', 'b' );
		ErrorLog::clear();

		$this->assertSame( array(), ErrorLog::all() );
		$this->assertFalse( ErrorLog::has_recent() );
	}

	/**
	 * The log is capped so it can't grow without bound.
	 */
	public function test_log_is_capped_at_max_entries(): void {
		for ( $i = 0; $i < 150; $i++ ) {
			ErrorLog::record( "context-{$i}", "message-{$i}" );
		}

		$log = ErrorLog::all();
		$this->assertCount( 100, $log );
		$this->assertSame( 'context-149', $log[ count( $log ) - 1 ]['context'] );
		$this->assertSame( 'context-50', $log[0]['context'] );
	}
}

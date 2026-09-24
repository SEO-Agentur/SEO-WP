<?php
/**
 * Tests for recognising staging/development host patterns.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Support\StagingDetector;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Support\StagingDetector
 */
final class StagingDetectorTest extends TestCase {

	/**
	 * A normal production domain is not flagged.
	 */
	public function test_production_domain_is_not_staging(): void {
		$this->assertFalse( StagingDetector::looks_like_staging_host( 'example.com' ) );
		$this->assertFalse( StagingDetector::looks_like_staging_host( 'www.example.com' ) );
		$this->assertFalse( StagingDetector::looks_like_staging_host( 'seooptimierung.de' ) );
	}

	/**
	 * Common staging subdomain conventions are flagged.
	 *
	 * @dataProvider staging_hosts_provider
	 */
	public function test_flags_common_staging_hosts( string $host ): void {
		$this->assertTrue( StagingDetector::looks_like_staging_host( $host ) );
	}

	/**
	 * Data provider of hosts that should be recognised as staging.
	 *
	 * @return array<int,array{0:string}>
	 */
	public static function staging_hosts_provider(): array {
		return array(
			array( 'staging.example.com' ),
			array( 'example.staging.com' ),
			array( 'staging-example.com' ),
			array( 'example-staging.com' ),
			array( 'stage.example.com' ),
			array( 'dev.example.com' ),
			array( 'example.dev.com' ),
			array( 'test.example.com' ),
			array( 'example.test' ),
			array( 'example.local' ),
			array( 'localhost' ),
			array( 'my-site.localhost' ),
			array( 'example.wpengine.com' ),
			array( 'example.kinsta.cloud' ),
			array( 'example.flywheelsites.com' ),
		);
	}

	/**
	 * A "dev" substring that isn't a subdomain boundary is not falsely
	 * flagged (e.g. a legitimate "developer.example.com").
	 */
	public function test_does_not_false_positive_on_dev_substring(): void {
		$this->assertFalse( StagingDetector::looks_like_staging_host( 'developer.example.com' ) );
	}

	/**
	 * A bare IPv4 or IPv6 address is flagged.
	 */
	public function test_flags_ip_addresses(): void {
		$this->assertTrue( StagingDetector::looks_like_staging_host( '203.0.113.42' ) );
		$this->assertTrue( StagingDetector::looks_like_staging_host( '::1' ) );
	}

	/**
	 * An empty host is not flagged.
	 */
	public function test_empty_host_is_not_flagged(): void {
		$this->assertFalse( StagingDetector::looks_like_staging_host( '' ) );
	}

	/**
	 * is_ip_address() correctly distinguishes IPs from domain names.
	 */
	public function test_is_ip_address(): void {
		$this->assertTrue( StagingDetector::is_ip_address( '192.168.1.1' ) );
		$this->assertFalse( StagingDetector::is_ip_address( 'example.com' ) );
	}
}

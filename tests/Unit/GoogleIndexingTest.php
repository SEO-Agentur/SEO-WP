<?php
/**
 * Tests for the Google Indexing API's JWT assertion builder.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Tests\Unit;

use SeoWp\Crawl\GoogleIndexing;
use PHPUnit\Framework\TestCase;

/**
 * @covers \SeoWp\Crawl\GoogleIndexing
 */
final class GoogleIndexingTest extends TestCase {

	/**
	 * A real RSA key pair generated for these tests only.
	 *
	 * @var array{private: string, public: string}
	 */
	private static array $keyPair;

	/**
	 * Generates a throwaway RSA key pair once for the whole test class.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		openssl_pkey_export( $resource, $private_key );
		$details = openssl_pkey_get_details( $resource );

		self::$keyPair = array(
			'private' => $private_key,
			'public'  => $details['key'],
		);
	}

	/**
	 * Decodes a base64url-encoded JSON segment.
	 *
	 * @param string $segment Base64url-encoded segment.
	 * @return array<string,mixed>
	 */
	private static function decodeSegment( string $segment ): array {
		$padded = strtr( $segment, '-_', '+/' );
		$padded .= str_repeat( '=', ( 4 - strlen( $padded ) % 4 ) % 4 );

		return json_decode( (string) base64_decode( $padded ), true );
	}

	/**
	 * The JWT has the standard three dot-separated segments, and the
	 * claims carry the expected issuer, scope and audience.
	 */
	public function test_builds_a_well_formed_jwt_with_expected_claims(): void {
		$jwt = GoogleIndexing::build_jwt( 'svc@example.iam.gserviceaccount.com', self::$keyPair['private'] );

		$segments = explode( '.', $jwt );
		$this->assertCount( 3, $segments );

		$header = self::decodeSegment( $segments[0] );
		$this->assertSame( 'RS256', $header['alg'] );
		$this->assertSame( 'JWT', $header['typ'] );

		$claims = self::decodeSegment( $segments[1] );
		$this->assertSame( 'svc@example.iam.gserviceaccount.com', $claims['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/indexing', $claims['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $claims['aud'] );
		$this->assertGreaterThan( $claims['iat'], $claims['exp'] );
	}

	/**
	 * The signature segment verifies against the matching public key.
	 */
	public function test_signature_verifies_against_the_public_key(): void {
		$jwt = GoogleIndexing::build_jwt( 'svc@example.iam.gserviceaccount.com', self::$keyPair['private'] );

		$segments      = explode( '.', $jwt );
		$signing_input = $segments[0] . '.' . $segments[1];
		$signature     = base64_decode( strtr( $segments[2], '-_', '+/' ) );

		$result = openssl_verify( $signing_input, $signature, self::$keyPair['public'], OPENSSL_ALGO_SHA256 );

		$this->assertSame( 1, $result );
	}

	/**
	 * An invalid private key produces no JWT rather than a broken one.
	 */
	public function test_returns_empty_string_for_an_invalid_private_key(): void {
		$jwt = GoogleIndexing::build_jwt( 'svc@example.iam.gserviceaccount.com', 'not a real key' );

		$this->assertSame( '', $jwt );
	}
}

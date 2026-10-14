<?php
/**
 * Tests for the security header configuration logic.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Performance\SecurityHeaderConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Performance\SecurityHeaderConfig
 */
final class SecurityHeaderConfigTest extends TestCase {

	/**
	 * Nothing configured, nothing sent.
	 */
	public function test_sends_nothing_by_default(): void {
		$this->assertSame( array(), SecurityHeaderConfig::headers( array(), true, true ) );
	}

	/**
	 * Sites that only ever saved the old combined checkbox keep getting
	 * the same four headers until the Security tab is saved.
	 */
	public function test_legacy_combined_flag_still_enables_the_four_headers(): void {
		$headers = SecurityHeaderConfig::headers( array( 'enable_security_headers' => true ), false, true );

		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
		$this->assertSame( SecurityHeaderConfig::DEFAULT_PERMISSIONS_POLICY, $headers['Permissions-Policy'] );
		$this->assertArrayNotHasKey( 'Strict-Transport-Security', $headers );
	}

	/**
	 * Once the new per-header keys exist they win over the legacy flag.
	 */
	public function test_individual_keys_override_the_legacy_flag(): void {
		$headers = SecurityHeaderConfig::headers(
			array(
				'enable_security_headers'    => true,
				'sec_x_content_type_options' => false,
				'sec_x_frame_options'        => true,
				'sec_x_frame_options_value'  => 'DENY',
				'sec_referrer_policy'        => false,
				'sec_permissions_policy'     => false,
			),
			false,
			true
		);

		$this->assertSame( array( 'X-Frame-Options' => 'DENY' ), $headers );
	}

	/**
	 * An invalid stored value falls back to the safe default instead of
	 * being sent.
	 */
	public function test_invalid_choice_values_fall_back(): void {
		$headers = SecurityHeaderConfig::headers(
			array(
				'sec_x_frame_options'       => true,
				'sec_x_frame_options_value' => 'ALLOW-FROM evil',
				'sec_referrer_policy'       => true,
				'sec_referrer_policy_value' => 'bogus',
			),
			false,
			true
		);

		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertSame( 'strict-origin-when-cross-origin', $headers['Referrer-Policy'] );
	}

	/**
	 * An emptied Permissions-Policy field sends no header rather than an
	 * empty one.
	 */
	public function test_emptied_permissions_policy_sends_nothing(): void {
		$headers = SecurityHeaderConfig::headers(
			array(
				'sec_permissions_policy'       => true,
				'sec_permissions_policy_value' => '',
			),
			false,
			true
		);

		$this->assertArrayNotHasKey( 'Permissions-Policy', $headers );
	}

	/**
	 * HSTS is only sent over HTTPS.
	 */
	public function test_hsts_requires_https(): void {
		$settings = array( 'sec_hsts' => true );

		$this->assertArrayNotHasKey( 'Strict-Transport-Security', SecurityHeaderConfig::headers( $settings, false, true ) );
		$this->assertSame( 'max-age=31536000', SecurityHeaderConfig::headers( $settings, true, true )['Strict-Transport-Security'] );
	}

	/**
	 * Subdomains and preload are appended in the right order, and preload
	 * is dropped unless the browsers' preload requirements are met.
	 */
	public function test_hsts_options(): void {
		$this->assertSame(
			'max-age=63072000; includeSubDomains; preload',
			SecurityHeaderConfig::hsts_value(
				array(
					'sec_hsts_max_age'    => '63072000',
					'sec_hsts_subdomains' => true,
					'sec_hsts_preload'    => true,
				)
			)
		);

		$this->assertSame(
			'max-age=3600; includeSubDomains',
			SecurityHeaderConfig::hsts_value(
				array(
					'sec_hsts_max_age'    => 3600,
					'sec_hsts_subdomains' => true,
					'sec_hsts_preload'    => true,
				)
			)
		);

		$this->assertSame(
			'max-age=31536000',
			SecurityHeaderConfig::hsts_value(
				array(
					'sec_hsts_subdomains' => false,
					'sec_hsts_preload'    => true,
				)
			)
		);
	}

	/**
	 * Legacy HSTS keys still apply before the Security tab is saved.
	 */
	public function test_legacy_hsts_keys_apply(): void {
		$headers = SecurityHeaderConfig::headers(
			array(
				'enable_hsts'             => true,
				'hsts_include_subdomains' => true,
			),
			true,
			true
		);

		$this->assertSame( 'max-age=31536000; includeSubDomains', $headers['Strict-Transport-Security'] );
	}

	/**
	 * The CSP defaults to report-only, uses the default policy when none
	 * is stored, and is never sent outside the public front end.
	 */
	public function test_csp_defaults_and_scope(): void {
		$settings = array( 'sec_csp' => true );

		$headers = SecurityHeaderConfig::headers( $settings, true, true );
		$this->assertSame( SecurityHeaderConfig::DEFAULT_CSP, $headers['Content-Security-Policy-Report-Only'] );
		$this->assertArrayNotHasKey( 'Content-Security-Policy', $headers );

		$this->assertSame( array(), SecurityHeaderConfig::headers( $settings, true, false ) );

		$enforced = SecurityHeaderConfig::headers(
			array(
				'sec_csp'             => true,
				'sec_csp_report_only' => false,
				'sec_csp_policy'      => "default-src 'self'",
			),
			true,
			true
		);
		$this->assertSame( array( 'Content-Security-Policy' => "default-src 'self'" ), $enforced );
	}

	/**
	 * Control characters are stripped so a value can never smuggle in a
	 * second header line.
	 */
	public function test_clean_value_removes_header_injection(): void {
		$this->assertSame(
			"default-src 'self' X-Evil: 1",
			SecurityHeaderConfig::clean_value( "default-src 'self'\r\nX-Evil: 1" )
		);
		$this->assertSame( 'a b', SecurityHeaderConfig::clean_value( "  a \t\t b  " ) );
	}

	/**
	 * The live-check report distinguishes sent, missing and duplicated.
	 */
	public function test_check_report_states(): void {
		$report = SecurityHeaderConfig::check_report(
			array(
				'x-content-type-options' => 'nosniff',
				'x-frame-options'        => 'SAMEORIGIN, SAMEORIGIN',
				'referrer-policy'        => array( 'same-origin', 'same-origin' ),
				'permissions-policy'     => 'geolocation=(), camera=()',
			)
		);

		$this->assertSame( 'ok', $report['X-Content-Type-Options']['state'] );
		$this->assertSame( 'duplicate', $report['X-Frame-Options']['state'] );
		$this->assertSame( 'duplicate', $report['Referrer-Policy']['state'] );
		$this->assertSame( 'ok', $report['Permissions-Policy']['state'] );
		$this->assertSame( 'missing', $report['Strict-Transport-Security']['state'] );
		$this->assertSame( 'missing', $report['Content-Security-Policy']['state'] );
	}

	/**
	 * Compared against the configured headers, the live check tells
	 * apart: as configured, replaced by a proxy, stripped, and sent by
	 * someone else.
	 */
	public function test_check_report_compares_with_expected_headers(): void {
		$report = SecurityHeaderConfig::check_report(
			array(
				'x-content-type-options' => 'nosniff',
				'referrer-policy'        => 'strict-origin-when-cross-origin',
				'strict-transport-security' => 'max-age=31536000; includeSubDomains',
				'x-frame-options'        => 'SAMEORIGIN',
			),
			array(
				'X-Content-Type-Options'    => 'nosniff',
				'Referrer-Policy'           => 'same-origin',
				'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains; preload',
				'Permissions-Policy'        => 'camera=()',
			)
		);

		$this->assertSame( 'ok', $report['X-Content-Type-Options']['state'] );
		$this->assertSame( 'overridden', $report['Referrer-Policy']['state'] );
		$this->assertSame( 'overridden', $report['Strict-Transport-Security']['state'] );
		$this->assertSame( 'missing', $report['Permissions-Policy']['state'] );
		$this->assertSame( 'foreign', $report['X-Frame-Options']['state'] );
		$this->assertSame( 'absent', $report['Content-Security-Policy']['state'] );
	}
}

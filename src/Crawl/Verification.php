<?php
/**
 * Site verification meta tags.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

/**
 * Renders google/bing/baidu/pinterest/yandex site verification meta tags
 * from settings.
 */
final class Verification {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Settings key => verification meta tag name.
	 *
	 * @var array<string,string>
	 */
	private const PROVIDERS = array(
		'verification_google'    => 'google-site-verification',
		'verification_bing'      => 'msvalidate.01',
		'verification_baidu'     => 'baidu-site-verification',
		'verification_pinterest' => 'p:domain_verify',
		'verification_yandex'    => 'yandex-verification',
	);

	/**
	 * The verification code from whatever was pasted: the console hands
	 * out a whole `<meta name="..." content="CODE" />` tag, which would
	 * otherwise be stripped to nothing when the field is sanitised.
	 *
	 * @param string $raw Pasted value: a bare code or a whole meta tag.
	 */
	public static function extract_code( string $raw ): string {
		if ( 1 === preg_match( '/content\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/i', $raw, $m ) ) {
			$raw = '' !== $m[1] ? $m[1] : ( $m[2] ?? '' );
		}

		return trim( $raw );
	}

	/**
	 * Settings keys of the verification fields.
	 *
	 * @return string[]
	 */
	public static function settings_keys(): array {
		return array_keys( self::PROVIDERS );
	}

	/**
	 * Registers the wp_head output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'output' ), 1 );
	}

	/**
	 * Echoes a verification meta tag for each configured provider.
	 */
	public function output(): void {
		if ( is_admin() ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );

		foreach ( self::PROVIDERS as $key => $meta_name ) {
			$value = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
			if ( '' === $value ) {
				continue;
			}

			printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $meta_name ), esc_attr( $value ) );
		}
	}
}

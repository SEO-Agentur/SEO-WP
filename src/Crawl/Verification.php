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

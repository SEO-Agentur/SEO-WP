<?php
/**
 * One entry point for every AI request the plugin makes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

/**
 * Hands a prompt (optionally with one image) to the chosen provider and
 * returns its text reply:
 *
 * - "core": WordPress' own AI client (wp_ai_client_prompt(), WordPress 7.0+),
 *   which uses whichever provider/connector the site owner has set up for
 *   WordPress itself — no key is stored by this plugin at all.
 * - "anthropic" / "openai": the site owner's own API key, entered on the AI
 *   Settings page and called directly (see AiClient).
 *
 * Nothing here runs by itself: every caller is an explicit, capability-gated
 * admin action (a "Suggest" click).
 */
final class Ai {

	public const PROVIDER_CORE      = 'core';
	public const PROVIDER_ANTHROPIC = 'anthropic';
	public const PROVIDER_OPENAI    = 'openai';

	/**
	 * Providers that can be used right now: provider slug => label.
	 * "core" comes first when WordPress has a usable AI provider.
	 *
	 * @return array<string,string>
	 */
	public static function providers(): array {
		$providers = array();

		if ( self::core_available() ) {
			$providers[ self::PROVIDER_CORE ] = __( 'WordPress AI', 'seooptimierung-toolkit' );
		}
		if ( Secrets::has( 'ai_anthropic_key' ) ) {
			$providers[ self::PROVIDER_ANTHROPIC ] = __( 'Claude', 'seooptimierung-toolkit' );
		}
		if ( Secrets::has( 'ai_openai_key' ) ) {
			$providers[ self::PROVIDER_OPENAI ] = __( 'OpenAI', 'seooptimierung-toolkit' );
		}

		return $providers;
	}

	/**
	 * Whether WordPress' built-in AI client exists and has a provider that
	 * can generate text.
	 */
	public static function core_available(): bool {
		static $available = null;

		if ( null !== $available ) {
			return $available;
		}

		$available = false;

		if ( function_exists( 'wp_ai_client_prompt' ) && ( ! function_exists( 'wp_supports_ai' ) || wp_supports_ai() ) ) {
			try {
				$available = (bool) wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
			} catch ( \Throwable $e ) {
				$available = false;
			}
		}

		return $available;
	}

	/**
	 * Picks the provider to use when the caller doesn't care: the first
	 * available one (WordPress AI, then Claude, then OpenAI).
	 */
	public static function default_provider(): string {
		$providers = self::providers();

		return array() === $providers ? '' : (string) array_key_first( $providers );
	}

	/**
	 * Sends a prompt and returns the reply text.
	 *
	 * @param string                              $provider Provider slug (see providers()), or '' for the default.
	 * @param string                              $prompt   User prompt.
	 * @param array{mime:string,data:string}|null $image    Optional image: MIME type and base64-encoded bytes.
	 * @param int                                 $max_tokens Upper bound for the reply length.
	 * @return string|\WP_Error Reply text, or a WP_Error.
	 */
	public static function generate( string $provider, string $prompt, ?array $image = null, int $max_tokens = 300 ) {
		$provider = '' === $provider ? self::default_provider() : $provider;
		$settings = get_option( 'seo_wp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		switch ( $provider ) {
			case self::PROVIDER_CORE:
				return self::core( $prompt, $image, $max_tokens );

			case self::PROVIDER_ANTHROPIC:
				$key = Secrets::get( 'ai_anthropic_key' );
				if ( '' === $key ) {
					return new \WP_Error( 'seo_wp_ai_no_key', __( 'No Anthropic API key configured.', 'seooptimierung-toolkit' ) );
				}
				return AiClient::anthropic( $key, $prompt, (string) ( $settings['ai_anthropic_model'] ?? '' ), $image, $max_tokens );

			case self::PROVIDER_OPENAI:
				$key = Secrets::get( 'ai_openai_key' );
				if ( '' === $key ) {
					return new \WP_Error( 'seo_wp_ai_no_key', __( 'No OpenAI API key configured.', 'seooptimierung-toolkit' ) );
				}
				return AiClient::openai( $key, $prompt, (string) ( $settings['ai_openai_model'] ?? '' ), $image, $max_tokens );

			default:
				return new \WP_Error( 'seo_wp_ai_no_provider', __( 'No AI provider available — connect one in WordPress (Settings → Connectors) or add a key on the AI Settings page.', 'seooptimierung-toolkit' ) );
		}
	}

	/**
	 * Runs the prompt through WordPress' own AI client.
	 *
	 * @param string                              $prompt     User prompt.
	 * @param array{mime:string,data:string}|null $image      Optional image.
	 * @param int                                 $max_tokens Upper bound for the reply length.
	 * @return string|\WP_Error
	 */
	private static function core( string $prompt, ?array $image, int $max_tokens ) {
		if ( ! self::core_available() ) {
			return new \WP_Error( 'seo_wp_ai_core_unavailable', __( 'WordPress has no AI provider connected.', 'seooptimierung-toolkit' ) );
		}

		try {
			$builder = wp_ai_client_prompt( $prompt )->using_max_tokens( $max_tokens );

			if ( null !== $image ) {
				$builder = $builder->with_file( 'data:' . $image['mime'] . ';base64,' . $image['data'], $image['mime'] );
			}

			$text = $builder->generate_text();
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'seo_wp_ai_core_error', $e->getMessage() );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return is_string( $text ) && '' !== trim( $text )
			? trim( $text )
			: new \WP_Error( 'seo_wp_ai_core_error', __( 'WordPress AI returned no text.', 'seooptimierung-toolkit' ) );
	}
}

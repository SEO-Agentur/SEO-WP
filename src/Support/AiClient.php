<?php
/**
 * Minimal HTTP clients for the Anthropic and OpenAI text-generation APIs.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

/**
 * Sends one prompt to whichever provider is configured and returns its
 * raw text reply. No SDK/dependency — both APIs are a single JSON POST,
 * matching this project's no-bundled-vendor-library stance (see
 * GoogleIndexing's hand-rolled JWT for the same reasoning). Bring-your-
 * own-key: this plugin never proxies or marks up the request, and never
 * calls out unless a site owner has both configured a key and clicked an
 * explicit "suggest" action — nothing here runs automatically.
 */
final class AiClient {

	/**
	 * Anthropic's Messages API endpoint.
	 */
	private const ANTHROPIC_ENDPOINT = 'https://api.anthropic.com/v1/messages';

	/**
	 * Anthropic API version header required by the Messages API.
	 */
	private const ANTHROPIC_VERSION = '2023-06-01';

	/**
	 * OpenAI's Chat Completions endpoint.
	 */
	private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Default model per provider, used when no override is configured.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_MODELS = array(
		'anthropic' => 'claude-haiku-4-5-20251001',
		'openai'    => 'gpt-4o-mini',
	);

	/**
	 * Sends a prompt to Anthropic's Claude and returns its text reply.
	 *
	 * @param string $api_key Anthropic API key.
	 * @param string $prompt  User prompt.
	 * @param string $model   Model override, or '' to use the default.
	 * @return string|\WP_Error Reply text, or a WP_Error describing what went wrong.
	 */
	public static function anthropic( string $api_key, string $prompt, string $model = '' ) {
		$response = wp_remote_post(
			self::ANTHROPIC_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => self::ANTHROPIC_VERSION,
				),
				'body'    => wp_json_encode(
					array(
						'model'      => '' !== $model ? $model : self::DEFAULT_MODELS['anthropic'],
						'max_tokens' => 300,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return new \WP_Error( 'seo_wp_anthropic_error', (string) $body['error']['message'] );
		}

		$text = $body['content'][0]['text'] ?? null;
		if ( ! is_string( $text ) || '' === $text ) {
			return new \WP_Error( 'seo_wp_anthropic_error', __( 'Claude returned no text.', 'seooptimierung-toolkit' ) );
		}

		return $text;
	}

	/**
	 * Sends a prompt to OpenAI's Chat Completions API and returns its
	 * text reply.
	 *
	 * @param string $api_key OpenAI API key.
	 * @param string $prompt  User prompt.
	 * @param string $model   Model override, or '' to use the default.
	 * @return string|\WP_Error Reply text, or a WP_Error describing what went wrong.
	 */
	public static function openai( string $api_key, string $prompt, string $model = '' ) {
		$response = wp_remote_post(
			self::OPENAI_ENDPOINT,
			array(
				'timeout' => 30,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode(
					array(
						'model'      => '' !== $model ? $model : self::DEFAULT_MODELS['openai'],
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
						'max_tokens' => 300,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return new \WP_Error( 'seo_wp_openai_error', (string) $body['error']['message'] );
		}

		$text = $body['choices'][0]['message']['content'] ?? null;
		if ( ! is_string( $text ) || '' === $text ) {
			return new \WP_Error( 'seo_wp_openai_error', __( 'OpenAI returned no text.', 'seooptimierung-toolkit' ) );
		}

		return $text;
	}

	/**
	 * Extracts a {"title": ..., "description": ...} object from a model
	 * reply, tolerating a reply wrapped in a markdown code fence (models
	 * do this even when explicitly told not to). Pure string logic,
	 * independent of WordPress, for easy unit testing.
	 *
	 * @param string $reply Raw model reply text.
	 * @return array{title: string, description: string}|null
	 */
	public static function parse_title_description_reply( string $reply ): ?array {
		$reply = trim( $reply );
		$reply = (string) preg_replace( '/^```(?:json)?/i', '', $reply );
		$reply = (string) preg_replace( '/```$/', '', $reply );
		$reply = trim( $reply );

		$decoded = json_decode( $reply, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['title'], $decoded['description'] ) ) {
			return null;
		}

		$title       = trim( (string) $decoded['title'] );
		$description = trim( (string) $decoded['description'] );

		if ( '' === $title || '' === $description ) {
			return null;
		}

		return array(
			'title'       => $title,
			'description' => $description,
		);
	}
}

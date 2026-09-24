<?php
/**
 * Virtual, editable robots.txt output.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

/**
 * Extends WordPress core's own virtual robots.txt (via the robots_txt
 * filter — no rewrite rule or physical file of our own) with a custom
 * rule block, an AI-crawler opt-out toggle and a Sitemap line.
 */
final class RobotsTxt {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * User-agents of crawlers used to source AI training data, blocked
	 * when the "block_ai_crawlers" setting is enabled.
	 *
	 * @var string[]
	 */
	private const AI_CRAWLERS = array(
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'Google-Extended',
		'CCBot',
		'ClaudeBot',
		'Claude-Web',
		'anthropic-ai',
		'PerplexityBot',
		'Applebot-Extended',
		'Bytespider',
		'Meta-ExternalAgent',
		'Amazonbot',
		'Diffbot',
	);

	/**
	 * Registers the robots_txt filter.
	 */
	public function register(): void {
		add_filter( 'robots_txt', array( $this, 'filter' ), 10, 2 );
	}

	/**
	 * Appends the plugin's rules to core's robots.txt output.
	 *
	 * @param string $output    Core's own robots.txt content.
	 * @param bool   $is_public Whether Settings > Reading allows search engines to index the site.
	 */
	public function filter( string $output, bool $is_public ): string {
		if ( ! $is_public ) {
			return $output;
		}

		$settings = get_option( self::OPTION_KEY, array() );

		$custom = isset( $settings['robots_txt_custom'] ) ? trim( (string) $settings['robots_txt_custom'] ) : '';
		if ( '' !== $custom ) {
			$output .= "\n" . $custom . "\n";
		}

		if ( ! empty( $settings['block_ai_crawlers'] ) ) {
			$output .= "\n" . self::ai_crawler_block() . "\n";
		}

		$output .= "\nSitemap: " . home_url( '/sitemap.xml' ) . "\n";

		return $output;
	}

	/**
	 * Builds a Disallow-everything block for each known AI crawler. Pure
	 * string building, independent of WordPress, for easy unit testing.
	 */
	public static function ai_crawler_block(): string {
		$lines = array();

		foreach ( self::AI_CRAWLERS as $user_agent ) {
			$lines[] = "User-agent: {$user_agent}";
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) );
	}
}

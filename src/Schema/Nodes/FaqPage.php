<?php
/**
 * FAQPage schema node.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Schema\Nodes;

use WP_Post;

/**
 * Builds the FAQPage JSON-LD node from a structured post meta field group
 * (an array of question/answer pairs), written by the Admin metabox (M7).
 */
final class FaqPage {

	/**
	 * Post meta key holding the array of { question, answer } pairs.
	 */
	private const META_FAQ = '_seo_wp_faq';

	/**
	 * Builds the node, or null when the current object has no FAQ entries.
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$entities = $this->entities( $post );
		if ( array() === $entities ) {
			return null;
		}

		$url = (string) get_permalink( $post );

		return array(
			'@type'      => 'FAQPage',
			'@id'        => $url . '#faq',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Reads and validates the FAQ entries stored for the given post.
	 *
	 * @param WP_Post $post Current post.
	 * @return array<int,array<string,mixed>>
	 */
	private function entities( WP_Post $post ): array {
		$raw = get_post_meta( $post->ID, self::META_FAQ, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$entities = array();
		foreach ( $raw as $item ) {
			$question = isset( $item['question'] ) ? trim( (string) $item['question'] ) : '';
			$answer   = isset( $item['answer'] ) ? trim( (string) $item['answer'] ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => $question,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $answer,
				),
			);
		}

		return $entities;
	}
}

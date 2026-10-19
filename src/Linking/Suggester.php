<?php
/**
 * Internal link suggestions.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Linking;

use Seooptimierung\Toolkit\Meta\Robots;
use WP_Post;
use WP_Query;

/**
 * For the page being edited, finds other pages worth linking to and, where
 * possible, a phrase already in the text that can carry the link.
 *
 * No AI and no external service: a candidate scores for words its title and
 * focus keyword share with the text, plus words from its categories/tags;
 * its focus keyword appearing verbatim in the text counts most. Everything
 * the scoring and anchor search need is pure string logic (unit tested);
 * only suggest() touches WordPress.
 */
final class Suggester {

	/**
	 * Posts considered as link targets.
	 */
	private const CANDIDATE_LIMIT = 400;

	/**
	 * Lowest score that is still shown.
	 */
	private const MIN_SCORE = 4;

	/**
	 * Words too common to say anything (German and English).
	 *
	 * @var string[]
	 */
	private const STOPWORDS = array(
		'aber',
		'alle',
		'allem',
		'allen',
		'aller',
		'alles',
		'also',
		'auch',
		'auf',
		'aus',
		'bei',
		'beim',
		'bin',
		'bis',
		'bist',
		'dass',
		'dein',
		'deine',
		'dem',
		'den',
		'der',
		'des',
		'die',
		'dies',
		'diese',
		'diesem',
		'diesen',
		'dieser',
		'dieses',
		'doch',
		'dort',
		'durch',
		'eine',
		'einem',
		'einen',
		'einer',
		'eines',
		'einfach',
		'einige',
		'er',
		'erst',
		'euch',
		'euer',
		'fuer',
		'für',
		'gegen',
		'geht',
		'haben',
		'hat',
		'hatte',
		'hier',
		'ihr',
		'ihre',
		'ihren',
		'immer',
		'indem',
		'ins',
		'ist',
		'jede',
		'jedem',
		'jeden',
		'jeder',
		'jedes',
		'kann',
		'kein',
		'keine',
		'koennen',
		'können',
		'machen',
		'mehr',
		'mein',
		'meine',
		'mit',
		'muss',
		'nach',
		'nicht',
		'noch',
		'nur',
		'oder',
		'ohne',
		'sehr',
		'sein',
		'seine',
		'sich',
		'sie',
		'sind',
		'sollte',
		'sondern',
		'ueber',
		'über',
		'und',
		'uns',
		'unter',
		'vom',
		'von',
		'vor',
		'war',
		'waren',
		'warum',
		'was',
		'weil',
		'welche',
		'wenn',
		'werden',
		'wie',
		'wieder',
		'wir',
		'wird',
		'wurde',
		'zum',
		'zur',
		'zwischen',
		'about',
		'after',
		'again',
		'all',
		'also',
		'and',
		'are',
		'because',
		'been',
		'before',
		'being',
		'between',
		'both',
		'but',
		'can',
		'could',
		'did',
		'does',
		'doing',
		'down',
		'each',
		'few',
		'for',
		'from',
		'further',
		'had',
		'has',
		'have',
		'having',
		'her',
		'here',
		'hers',
		'him',
		'his',
		'how',
		'into',
		'its',
		'just',
		'more',
		'most',
		'must',
		'not',
		'now',
		'off',
		'once',
		'only',
		'other',
		'our',
		'out',
		'over',
		'own',
		'same',
		'she',
		'should',
		'some',
		'such',
		'than',
		'that',
		'the',
		'their',
		'them',
		'then',
		'there',
		'these',
		'they',
		'this',
		'those',
		'through',
		'too',
		'under',
		'until',
		'very',
		'was',
		'were',
		'what',
		'when',
		'where',
		'which',
		'while',
		'who',
		'whom',
		'why',
		'will',
		'with',
		'would',
		'you',
		'your',
	);

	/**
	 * Significant words of a text: lowercase, at least four letters, no
	 * stop words, each once.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function tokenize( string $text ): array {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

		if ( ! preg_match_all( '/[\p{L}\p{N}][\p{L}\p{N}\-]*/u', $text, $matches ) ) {
			return array();
		}

		$words = array();
		foreach ( $matches[0] as $word ) {
			$word = trim( $word, '-' );
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $word ) : strlen( $word ) ) >= 4 && ! in_array( $word, self::STOPWORDS, true ) ) {
				$words[ $word ] = true;
			}
		}

		return array_keys( $words );
	}

	/**
	 * Scores one candidate against the source text's words.
	 *
	 * @param string[]                                          $source_tokens Words of the source text.
	 * @param string                                            $source_text   Plain text of the source.
	 * @param array{title:string,keyword:string,terms:string[]} $candidate     Candidate facts.
	 * @return array{score:int,matched:string[]}
	 */
	public static function score( array $source_tokens, string $source_text, array $candidate ): array {
		$score   = 0;
		$matched = array();

		foreach ( self::tokenize( $candidate['title'] ) as $word ) {
			if ( in_array( $word, $source_tokens, true ) ) {
				$score    += 3;
				$matched[] = $word;
			}
		}

		$keyword_tokens = self::tokenize( $candidate['keyword'] );
		foreach ( $keyword_tokens as $word ) {
			if ( in_array( $word, $source_tokens, true ) ) {
				$score    += 4;
				$matched[] = $word;
			}
		}

		if ( '' !== trim( $candidate['keyword'] ) && false !== stripos( $source_text, trim( $candidate['keyword'] ) ) ) {
			$score += 6;
		}

		foreach ( $candidate['terms'] as $term ) {
			foreach ( self::tokenize( $term ) as $word ) {
				if ( in_array( $word, $source_tokens, true ) ) {
					++$score;
					$matched[] = $word;
				}
			}
		}

		return array(
			'score'   => $score,
			'matched' => array_values( array_unique( $matched ) ),
		);
	}

	/**
	 * Phrases from a candidate that could serve as anchor text, best first:
	 * its focus keyword, its whole title, then runs of two or three words
	 * from the title.
	 *
	 * @param string $title   Candidate title.
	 * @param string $keyword Candidate focus keyword.
	 * @return string[]
	 */
	public static function anchor_phrases( string $title, string $keyword ): array {
		$phrases = array();

		if ( '' !== trim( $keyword ) ) {
			$phrases[] = trim( $keyword );
		}
		if ( '' !== trim( $title ) ) {
			$phrases[] = trim( $title );
		}

		$words = preg_split( '/\s+/u', trim( (string) preg_replace( '/[^\p{L}\p{N}\s\-]+/u', ' ', $title ) ) );
		$words = is_array( $words ) ? array_values( array_filter( $words, 'strlen' ) ) : array();

		$count = count( $words );

		foreach ( array( 3, 2 ) as $size ) {
			for ( $i = 0; $i + $size <= $count; $i++ ) {
				$run = array_slice( $words, $i, $size );
				if ( array() !== self::tokenize( implode( ' ', $run ) ) ) {
					$phrases[] = implode( ' ', $run );
				}
			}
		}

		return array_values( array_unique( $phrases ) );
	}

	/**
	 * The first phrase that appears in the text outside existing links,
	 * as it is written there (so the link keeps the author's capitalisation).
	 * Pure string logic.
	 *
	 * @param string   $html    Source content as HTML.
	 * @param string[] $phrases Candidate anchor phrases, best first.
	 */
	public static function find_anchor( string $html, array $phrases ): string {
		$text = (string) preg_replace( '#<a\b[^>]*>.*?</a>#is', ' ', $html );
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );

		foreach ( $phrases as $phrase ) {
			if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $phrase ) : strlen( $phrase ) ) < 4 ) {
				continue;
			}

			if ( 1 === preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu', $text, $match ) ) {
				return $match[0];
			}
		}

		return '';
	}

	/**
	 * Paths of the links already in a piece of content (home-relative,
	 * lower case, no trailing slash), so they are not suggested again.
	 *
	 * @param string $html Content.
	 * @return string[]
	 */
	public static function linked_paths( string $html ): array {
		if ( ! preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return array();
		}

		$paths = array();
		foreach ( $matches[1] as $href ) {
			$path = strtolower( rtrim( (string) wp_parse_url( $href, PHP_URL_PATH ), '/' ) );
			if ( '' !== $path ) {
				$paths[ $path ] = true;
			}
		}

		return array_keys( $paths );
	}

	/**
	 * Suggestions for a post.
	 *
	 * @param WP_Post $post  The post being edited.
	 * @param string  $html  Its current content (the editor's, possibly unsaved), or '' to use the saved one.
	 * @param string  $title Its current title, or ''.
	 * @param int     $limit Most suggestions returned.
	 * @return array<int,array{id:int,title:string,url:string,score:int,matched:string[],anchor:string}>
	 */
	public static function suggest( WP_Post $post, string $html = '', string $title = '', int $limit = 8 ): array {
		$html  = '' !== $html ? $html : $post->post_content;
		$title = '' !== $title ? $title : $post->post_title;
		$text  = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );

		$source_tokens = self::tokenize( $title . ' ' . $text . ' ' . (string) get_post_meta( $post->ID, '_seo_wp_focus_keyword', true ) );
		if ( array() === $source_tokens ) {
			return array();
		}

		$linked = self::linked_paths( $html );
		$query  = new WP_Query(
			array(
				'post_type'              => array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) ),
				'post_status'            => 'publish',
				'has_password'           => false,
				'post__not_in'           => array( $post->ID ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- one ID.
				'posts_per_page'         => self::CANDIDATE_LIMIT,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
			)
		);

		$language = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID ) : '';
		$results  = array();

		foreach ( $query->posts as $candidate ) {
			if ( ! $candidate instanceof WP_Post ) {
				continue;
			}
			if ( '' !== $language && function_exists( 'pll_get_post_language' ) && (string) pll_get_post_language( $candidate->ID ) !== $language ) {
				continue;
			}
			if ( '1' === (string) get_post_meta( $candidate->ID, Robots::META_NOINDEX, true ) ) {
				continue;
			}

			$url  = (string) get_permalink( $candidate );
			$path = strtolower( rtrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
			if ( '' === $path || in_array( $path, $linked, true ) ) {
				continue;
			}

			$terms = array();
			foreach ( (array) get_object_taxonomies( $candidate->post_type ) as $taxonomy ) {
				foreach ( (array) get_the_terms( $candidate, $taxonomy ) as $term ) {
					if ( $term instanceof \WP_Term ) {
						$terms[] = $term->name;
					}
				}
			}

			$keyword = (string) get_post_meta( $candidate->ID, '_seo_wp_focus_keyword', true );
			$scored  = self::score(
				$source_tokens,
				$text,
				array(
					'title'   => $candidate->post_title,
					'keyword' => $keyword,
					'terms'   => $terms,
				)
			);

			if ( $scored['score'] < self::MIN_SCORE ) {
				continue;
			}

			$results[] = array(
				'id'      => $candidate->ID,
				'title'   => wp_strip_all_tags( $candidate->post_title ),
				'url'     => $url,
				'score'   => $scored['score'],
				'matched' => array_slice( $scored['matched'], 0, 6 ),
				'anchor'  => self::find_anchor( $html, self::anchor_phrases( $candidate->post_title, $keyword ) ),
			);
		}

		usort(
			$results,
			static function ( array $a, array $b ): int {
				return array( $b['score'], '' !== $b['anchor'] ? 1 : 0 ) <=> array( $a['score'], '' !== $a['anchor'] ? 1 : 0 );
			}
		);

		return array_slice( $results, 0, $limit );
	}
}

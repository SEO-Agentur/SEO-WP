<?php
/**
 * Tests for the internal link suggestion logic.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Linking\Suggester;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Linking\Suggester
 */
final class SuggesterTest extends TestCase {

	/**
	 * Words are lower-cased, short and common words dropped, each kept once.
	 */
	public function test_tokenize(): void {
		$this->assertSame(
			array( 'schaltbares', 'glas', 'kosten', 'büro' ),
			Suggester::tokenize( 'Schaltbares Glas: die Kosten für das Büro, Glas und Kosten' )
		);
		$this->assertSame( array(), Suggester::tokenize( 'the and of to' ) );
	}

	/**
	 * A candidate sharing title and keyword words scores; one that shares
	 * nothing does not; the keyword appearing verbatim counts most.
	 */
	public function test_score(): void {
		$text   = 'Schaltbares Glas im Büro: was kostet schaltbares Glas wirklich?';
		$tokens = Suggester::tokenize( $text );

		$fit = Suggester::score( $tokens, $text, array( 'title' => 'Kosten von schaltbarem Glas', 'keyword' => 'schaltbares glas kosten', 'terms' => array() ) );
		$off = Suggester::score( $tokens, $text, array( 'title' => 'Rezept für Kuchen', 'keyword' => 'kuchen', 'terms' => array( 'Backen' ) ) );

		$this->assertGreaterThanOrEqual( 4, $fit['score'] );
		$this->assertContains( 'glas', $fit['matched'] );
		$this->assertSame( 0, $off['score'] );

		$verbatim = Suggester::score( $tokens, $text, array( 'title' => 'Unrelated', 'keyword' => 'schaltbares Glas', 'terms' => array() ) );
		$this->assertGreaterThanOrEqual( 6, $verbatim['score'] );
	}

	/**
	 * Anchor phrases come best first: keyword, title, then word runs.
	 */
	public function test_anchor_phrases(): void {
		$phrases = Suggester::anchor_phrases( 'Was kostet schaltbares Glas?', 'schaltbares glas kosten' );

		$this->assertSame( 'schaltbares glas kosten', $phrases[0] );
		$this->assertSame( 'Was kostet schaltbares Glas?', $phrases[1] );
		$this->assertContains( 'kostet schaltbares Glas', $phrases );
		$this->assertContains( 'schaltbares Glas', $phrases );
	}

	/**
	 * The anchor is found as written in the text, whole words only, and
	 * never inside an existing link.
	 */
	public function test_find_anchor(): void {
		$html = '<p>Alles über <a href="/x/">Schaltbares Glas</a> und über schaltbares Glas im Büro. Glasfaser ist etwas anderes.</p>';

		$this->assertSame( 'schaltbares Glas', Suggester::find_anchor( $html, array( 'Schaltbares Glas' ) ) );
		$this->assertSame( '', Suggester::find_anchor( $html, array( 'Glasfaser Kabel', 'zzz' ) ) );
		$this->assertSame( '', Suggester::find_anchor( '<p>nur <a href="/y/">verlinktes Glas</a> hier</p>', array( 'verlinktes Glas' ) ) );
		$this->assertSame( '', Suggester::find_anchor( '<p>Glasfaser</p>', array( 'Glas' ) ) );
	}

	/**
	 * Paths of links already present are collected normalised.
	 */
	public function test_linked_paths(): void {
		$this->assertSame(
			array( '/kosten', '/a/b' ),
			Suggester::linked_paths( '<a href="https://example.com/Kosten/">k</a><a href=\'/a/b/\'>x</a><a href="#top">t</a>' )
		);
	}
}

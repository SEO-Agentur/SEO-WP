<?php
/**
 * Tests for the asset optimizer's pure helpers: the CSS and JavaScript
 * minifiers, URL handling and the planning of combined files.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Tests\Unit;

use Seooptimierung\Toolkit\Performance\AssetTools;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Seooptimierung\Toolkit\Performance\AssetTools
 */
final class AssetToolsTest extends TestCase {

	/**
	 * Comments, indentation and redundant spaces go; the content of
	 * strings and url() stays exactly as written.
	 */
	public function test_css_minify_keeps_strings_and_urls(): void {
		$css = "/* header */\na , b > c {\n  color : red ;\n  background: url( 'a b.png' ) ;\n  content: \" x  y \";\n}\n";

		$min = AssetTools::minify_css( $css );

		$this->assertSame( "a,b>c{color : red;background: url( 'a b.png' );content: \" x  y \"}", $min );
	}

	/**
	 * Spaces that matter stay: in media queries, calc() and between values.
	 */
	public function test_css_minify_keeps_meaningful_spaces(): void {
		$min = AssetTools::minify_css( "@media (min-width: 100px) and (max-width:200px){ .a{ width: calc( 100% - 20px ); margin:0 auto; } }" );

		$this->assertStringContainsString( ') and (', $min );
		$this->assertStringContainsString( 'calc( 100% - 20px )', $min );
		$this->assertStringContainsString( 'margin:0 auto', $min );
		$this->assertStringNotContainsString( ';}', $min );
	}

	/**
	 * Licence comments survive; an unquoted url() with special characters
	 * and escapes are copied untouched.
	 */
	public function test_css_minify_edge_cases(): void {
		$this->assertStringContainsString( '/*! MIT */', AssetTools::minify_css( "/*! MIT */\na{b:c}" ) );
		$this->assertStringContainsString( 'url(data:image/svg+xml;charset=utf8,%3Csvg xmlns=%27x%27%3E)', AssetTools::minify_css( 'a{b:url(data:image/svg+xml;charset=utf8,%3Csvg xmlns=%27x%27%3E)}' ) );
		$this->assertStringContainsString( '.a\\:b', AssetTools::minify_css( '.a\\:b { c : d }' ) );
		$this->assertSame( '', AssetTools::minify_css( "  /* only a comment */  \n" ) );
	}

	/**
	 * A relative url() is resolved against the original file's folder, so
	 * it still works from the combined file's new location.
	 */
	public function test_css_urls_are_made_absolute(): void {
		$css = "a{background:url(../img/a.png)} b{background:url(\"img/b.png?v=2#x\")} c{background:url(data:image/png;base64,AAA)} d{background:url(/abs/d.png)} e{background:url(https://cdn.example.com/e.png)}";

		$out = AssetTools::absolutize_css_urls( $css, 'https://example.com/wp-content/themes/t/css/style.css?ver=1' );

		$this->assertStringContainsString( 'url(https://example.com/wp-content/themes/t/img/a.png)', $out );
		$this->assertStringContainsString( 'url("https://example.com/wp-content/themes/t/css/img/b.png?v=2#x")', $out );
		$this->assertStringContainsString( 'url(data:image/png;base64,AAA)', $out );
		$this->assertStringContainsString( 'url(/abs/d.png)', $out );
		$this->assertStringContainsString( 'url(https://cdn.example.com/e.png)', $out );
	}

	/**
	 * @import files must not be combined; a comment that merely mentions it does not count.
	 */
	public function test_css_import_detection(): void {
		$this->assertTrue( AssetTools::css_has_import( "@import url('a.css');\nb{c:d}" ) );
		$this->assertFalse( AssetTools::css_has_import( "/* @import url(x) */\nb{c:d}" ) );
	}

	/**
	 * JavaScript: comments and indentation go, strings, regular
	 * expressions and template literals are untouched.
	 */
	public function test_js_minify_keeps_literals(): void {
		$js = "// note\nvar a = 1 ;\nif (a == 1) {\n  b( a , 'x  y' ) ;   // tail\n}\nvar r = /https?:\\/\\//.test(s) ;\nvar t = `a \${ b + ' }' } c` ;\n";

		$min = AssetTools::minify_js( $js );

		// The line break after "}" stays: after a function expression it is what ends the statement.
		$this->assertSame( "var a=1;if(a==1){b(a,'x  y');}\nvar r=/https?:\\/\\//.test(s);var t=`a \${ b + ' }' } c`;", $min );
	}

	/**
	 * Line breaks that could be automatic semicolons are kept, and so are
	 * the spaces in "a + +b" and between words.
	 */
	public function test_js_minify_keeps_what_changes_meaning(): void {
		$this->assertSame( "return a\n+ +b;", AssetTools::minify_js( "return a\n+ +b;" ) );
		$this->assertSame( 'var x=a + +b;', AssetTools::minify_js( 'var x = a + +b;' ) );
		$this->assertSame( 'x=a - -b;', AssetTools::minify_js( 'x = a - -b;' ) );
		$this->assertSame( "a=b\nc=d", AssetTools::minify_js( "a = b\nc = d" ) );
		$this->assertSame( "}\n(function(){})()", AssetTools::minify_js( "}\n(function () {\n})()" ) );
		$this->assertSame( 'return typeof x;', AssetTools::minify_js( 'return   typeof   x ;' ) );
		$this->assertSame( 'x=a / b / c;', AssetTools::minify_js( 'x = a / b / c;' ), 'division is not a regular expression' );
	}

	/**
	 * A "/" after a keyword or an operator starts a regular expression whose
	 * content (even a "//" inside it) is never treated as a comment.
	 */
	public function test_js_minify_regex_detection(): void {
		$this->assertSame( 'return /a\\/\\/b/.test(s);', AssetTools::minify_js( 'return /a\\/\\/b/.test(s);' ) );
		$this->assertSame( 'x=[/[/]x/];', AssetTools::minify_js( 'x = [ /[/]x/ ];' ) );
		$this->assertSame( 'if(/a/.test(b)){c()}', AssetTools::minify_js( "if ( /a/.test(b) ) {\n c()\n}" ) );
	}

	/**
	 * Strings with comment markers or line breaks inside are safe, and
	 * licence comments are kept.
	 */
	public function test_js_minify_strings_and_licences(): void {
		$this->assertSame( "var u='http://x.test/a';var v=\"/* no */\";", AssetTools::minify_js( "var u = 'http://x.test/a';\nvar v = \"/* no */\";" ) );
		$this->assertStringContainsString( '/*! (c) Me */', AssetTools::minify_js( "/*! (c) Me */\nvar a = 1;" ) );
		$this->assertStringNotContainsString( 'secret', AssetTools::minify_js( "/* secret */\nvar a = 1; /* secret */" ) );
		$this->assertSame( 'a=b;c=d', AssetTools::minify_js( "a = b; /* x\n y */ c = d" ) );
	}

	/**
	 * A leading "use strict" is found even after comments and a BOM.
	 */
	public function test_js_starts_strict(): void {
		$this->assertTrue( AssetTools::js_starts_strict( "'use strict';\nvar a;" ) );
		$this->assertTrue( AssetTools::js_starts_strict( "// x\n/* y */\n\"use strict\"; a()" ) );
		$this->assertFalse( AssetTools::js_starts_strict( "var a; 'use strict';" ) );
		$this->assertFalse( AssetTools::js_starts_strict( '(function(){ "use strict"; })();' ) );
	}

	/**
	 * Source-map comments are removed.
	 */
	public function test_source_map_comments(): void {
		$this->assertSame( "a();\n\nb();", AssetTools::strip_source_map_comment( "a();\n//# sourceMappingURL=a.js.map\nb();" ) );
		$this->assertSame( 'a{}', trim( AssetTools::strip_source_map_comment( "a{}\n/*# sourceMappingURL=a.css.map */" ) ) );
	}

	/**
	 * File addresses map to disk paths by the longest matching prefix, and
	 * anything that could leave the folder is refused.
	 */
	public function test_url_to_path(): void {
		$bases = array(
			'https://example.com/'                 => '/var/www/html',
			'https://example.com/wp-content'       => '/var/www/html/wp-content',
			'https://example.com/wp-content/uploads' => '/data/uploads',
		);

		$this->assertSame( '/var/www/html/wp-content/themes/t/a.css', AssetTools::url_to_path( 'https://example.com/wp-content/themes/t/a.css?ver=3', $bases ) );
		$this->assertSame( '/data/uploads/x.css', AssetTools::url_to_path( 'http://example.com/wp-content/uploads/x.css', $bases ), 'scheme is ignored' );
		$this->assertSame( '/var/www/html/wp-includes/a.js', AssetTools::url_to_path( '//example.com/wp-includes/a.js', $bases ) );
		$this->assertSame( '', AssetTools::url_to_path( 'https://other.test/a.css', $bases ) );
		$this->assertSame( '', AssetTools::url_to_path( 'https://example.com/wp-content/../wp-config.php', $bases ) );
		$this->assertSame( '', AssetTools::url_to_path( 'https://example.com/wp-content/%2e%2e/wp-config.php', $bases ) );
		$this->assertSame( '', AssetTools::url_to_path( '/wp-content/a.css', $bases ), 'only absolute addresses' );
	}

	/**
	 * Neighbouring eligible files share a run; an ineligible file or a
	 * change of key (media) ends it; without combining each file is alone.
	 */
	public function test_plan_runs(): void {
		$items = array(
			array( 'a', 'all' ),
			array( 'b', 'all' ),
			array( 'x', null ),
			array( 'c', 'all' ),
			array( 'd', 'print' ),
			array( 'e', 'print' ),
		);

		$this->assertSame( array( array( 'a', 'b' ), array( 'c' ), array( 'd', 'e' ) ), AssetTools::plan_runs( $items, true ) );
		$this->assertSame( array( array( 'a' ), array( 'b' ), array( 'c' ), array( 'd' ), array( 'e' ) ), AssetTools::plan_runs( $items, false ) );
		$this->assertSame( array(), AssetTools::plan_runs( array(), true ) );
		$this->assertSame( array(), AssetTools::plan_runs( array( array( 'x', null ) ), true ) );
	}

	/**
	 * Exclusion lines: handles match exactly, fragments match the address;
	 * comments and case are ignored.
	 */
	public function test_exclusion_list(): void {
		$list = AssetTools::parse_list( "jquery\n# comment\nWoo-Scripts  # trailing\n/plugins/slider/\n\n.min.js" );

		$this->assertSame( array( 'jquery', 'woo-scripts', '/plugins/slider/', '.min.js' ), $list );
		$this->assertTrue( AssetTools::is_excluded( 'jquery', 'https://e.com/jq.js', $list ) );
		$this->assertTrue( AssetTools::is_excluded( 'Woo-Scripts', 'https://e.com/w.js', $list ) );
		$this->assertTrue( AssetTools::is_excluded( 'x', 'https://e.com/wp-content/plugins/slider/s.js', $list ) );
		$this->assertFalse( AssetTools::is_excluded( 'jquery-ui', 'https://e.com/ui.js', array( 'jquery' ) ), 'a handle must match exactly' );
		$this->assertFalse( AssetTools::is_excluded( 'x', 'https://e.com/a.js', array( 'jquery' ) ) );
	}
}

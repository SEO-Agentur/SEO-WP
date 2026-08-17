<?php
/**
 * Document title and meta description output.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Meta;

use SeoWp\Support\CoexistenceMode;
use SeoWp\Templates\Renderer;
use SeoWp\Wpml\StringTranslation;
use WP_Post;
use WP_Term;

/**
 * Filters pre_get_document_title and renders the meta description tag,
 * for any public post type or taxonomy without hardcoding type names.
 */
final class TitleDescription {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Post/term meta key for a per-object title override.
	 */
	public const META_TITLE = '_seo_wp_title';

	/**
	 * Post/term meta key for a per-object description override.
	 */
	public const META_DESCRIPTION = '_seo_wp_description';

	/**
	 * Per-request memoised resolve_title()/resolve_description() results,
	 * shared across instances (Open Graph and Twitter Card each fall back
	 * to these via their own instance) so the underlying template
	 * rendering only ever runs once per request.
	 *
	 * @var array{title: ?string, description: ?string}
	 */
	private static array $resolved = array(
		'title'       => null,
		'description' => null,
	);

	/**
	 * Registers the document title filter and the meta description output.
	 */
	public function register(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 15 );
		add_action( 'wp_head', array( $this, 'output_meta_description' ), 1 );
	}

	/**
	 * Supplies the full document title, short-circuiting core's own
	 * title-part assembly.
	 *
	 * @param string $title Title computed so far by WordPress core.
	 */
	public function filter_title( string $title ): string {
		if ( is_admin() || CoexistenceMode::active() ) {
			return $title;
		}

		$rendered = $this->resolve_title();
		$rendered = '' !== $rendered ? $rendered : $title;

		/**
		 * Filters the final document title, after SEO-WP's own template
		 * rendering and override resolution.
		 *
		 * @param string $rendered The title SEO-WP would otherwise output.
		 * @param mixed  $queried  The current request's queried object (WP_Post, WP_Term, ...), or null.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		return (string) apply_filters( 'seo_wp/title', $rendered, get_queried_object() );
	}

	/**
	 * Echoes the meta description tag when a description is available.
	 */
	public function output_meta_description(): void {
		if ( is_admin() || CoexistenceMode::active() ) {
			return;
		}

		$description = $this->resolve_description();

		/**
		 * Filters the final meta description, after SEO-WP's own template
		 * rendering and override resolution.
		 *
		 * @param string $description The description SEO-WP would otherwise output.
		 * @param mixed  $queried     The current request's queried object (WP_Post, WP_Term, ...), or null.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		$description = (string) apply_filters( 'seo_wp/meta_description', $description, get_queried_object() );

		if ( '' === $description ) {
			return;
		}

		printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $description ) );
	}

	/**
	 * Resolves the title for the current request, for reuse by other
	 * head modules (e.g. Open Graph) that fall back to it. Memoised per
	 * request; see $resolved.
	 */
	public function resolve_title(): string {
		if ( null === self::$resolved['title'] ) {
			self::$resolved['title'] = $this->render_for_context( 'title', self::META_TITLE );
		}

		return self::$resolved['title'];
	}

	/**
	 * Resolves the meta description for the current request, for reuse by
	 * other head modules that fall back to it. Memoised per request; see
	 * $resolved.
	 */
	public function resolve_description(): string {
		if ( null === self::$resolved['description'] ) {
			self::$resolved['description'] = $this->render_for_context( 'description', self::META_DESCRIPTION );
		}

		return self::$resolved['description'];
	}

	/**
	 * Resolves the title for an arbitrary post, independent of the current
	 * request/global query — e.g. for an admin tool that needs every
	 * post's *effective* title (override or rendered template) at once,
	 * not just the one being viewed on the front end right now.
	 *
	 * @param WP_Post $post Post to resolve the title for.
	 */
	public function resolve_title_for_post( WP_Post $post ): string {
		return $this->render_for_post( $post, 'title', self::META_TITLE );
	}

	/**
	 * Resolves the meta description for an arbitrary post; see
	 * resolve_title_for_post().
	 *
	 * @param WP_Post $post Post to resolve the description for.
	 */
	public function resolve_description_for_post( WP_Post $post ): string {
		return $this->render_for_post( $post, 'description', self::META_DESCRIPTION );
	}

	/**
	 * Renders either the per-object override or the configured
	 * per-post-type template for a specific post. Deliberately simpler
	 * than render_for_context()/template_for_current_context(): those
	 * cover every request type (front page, archives, taxonomy terms) by
	 * reading the global query; a single post is always the "singular"
	 * case, so this skips straight to it.
	 *
	 * @param WP_Post $post     Post to render for.
	 * @param string  $kind     'title' or 'description'.
	 * @param string  $meta_key Post meta key holding the override.
	 */
	private function render_for_post( WP_Post $post, string $kind, string $meta_key ): string {
		$values    = $this->values_for_post( $post );
		$separator = (string) $this->option( 'separator', '-' );

		$override = (string) get_post_meta( $post->ID, $meta_key, true );
		if ( '' !== $override ) {
			return Renderer::render( $override, $values, $separator );
		}

		$defaults = self::defaults();
		$template = (string) $this->option( "{$kind}_template_{$post->post_type}", $defaults[ "{$kind}_singular" ] );

		return Renderer::render( $template, $values, $separator );
	}

	/**
	 * Builds the {token} => value map for a specific post.
	 *
	 * @param WP_Post $post Post to build values for.
	 * @return array<string,string>
	 */
	private function values_for_post( WP_Post $post ): array {
		return array(
			'sitename' => (string) get_bloginfo( 'name' ),
			'sep'      => (string) $this->option( 'separator', '-' ),
			'title'    => get_the_title( $post ),
			'excerpt'  => wp_strip_all_tags( (string) get_the_excerpt( $post ) ),
			'category' => '',
			'date'     => (string) get_the_date( '', $post ),
			'page'     => '',
			'year'     => gmdate( 'Y' ),
		);
	}

	/**
	 * Renders either the per-object override or the configured template
	 * for the current request.
	 *
	 * @param string $kind     'title' or 'description'.
	 * @param string $meta_key Post/term meta key holding the override.
	 */
	private function render_for_context( string $kind, string $meta_key ): string {
		$values    = $this->values_for_current_context();
		$separator = (string) $this->option( 'separator', '-' );

		$override = $this->override( $meta_key );
		if ( '' !== $override ) {
			return Renderer::render( $override, $values, $separator );
		}

		$template = $this->template_for_current_context( $kind );
		if ( '' === $template ) {
			return '';
		}

		return Renderer::render( $template, $values, $separator );
	}

	/**
	 * Builds the {token} => value map for the current request.
	 *
	 * @return array<string,string>
	 */
	private function values_for_current_context(): array {
		$values = array(
			'sitename' => (string) get_bloginfo( 'name' ),
			'sep'      => (string) $this->option( 'separator', '-' ),
			'title'    => '',
			'excerpt'  => '',
			'category' => '',
			'date'     => '',
			'page'     => '',
			// Always today's year (an evergreen "{title} {year}" pattern),
			// not the post's publish year — {date} already covers that.
			'year'     => gmdate( 'Y' ),
		);

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$values['title']   = get_the_title( $post );
				$values['excerpt'] = wp_strip_all_tags( (string) get_the_excerpt( $post ) );
				$values['date']    = (string) get_the_date( '', $post );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$values['category'] = $term->name;
			}
		} elseif ( is_post_type_archive() ) {
			$values['title'] = (string) post_type_archive_title( '', false );
		} elseif ( is_author() ) {
			$values['title'] = (string) get_the_author();
		} elseif ( is_search() ) {
			$values['title'] = (string) get_search_query();
		} elseif ( is_404() ) {
			$values['title'] = __( 'Page not found', 'seo-wp' );
		}

		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( $paged > 1 ) {
			$values['page'] = sprintf( /* translators: %d: page number. */ __( 'Page %d', 'seo-wp' ), $paged );
		}

		return $values;
	}

	/**
	 * Resolves the configured template for the current request type,
	 * falling back to a sensible built-in default.
	 *
	 * @param string $kind 'title' or 'description'.
	 */
	private function template_for_current_context( string $kind ): string {
		$defaults = self::defaults();

		if ( is_front_page() ) {
			return (string) $this->option( "{$kind}_template_home", $defaults[ "{$kind}_home" ] );
		}

		if ( is_singular() ) {
			$post_type = get_post_type();
			$post_type = false !== $post_type ? $post_type : 'post';
			return (string) $this->option( "{$kind}_template_{$post_type}", $defaults[ "{$kind}_singular" ] );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term     = get_queried_object();
			$taxonomy = $term instanceof WP_Term ? $term->taxonomy : 'category';
			return (string) $this->option( "{$kind}_template_term_{$taxonomy}", $defaults[ "{$kind}_term" ] );
		}

		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? (string) reset( $post_type ) : (string) $post_type;
			return (string) $this->option( "{$kind}_template_archive_{$post_type}", $defaults[ "{$kind}_singular" ] );
		}

		return (string) $defaults[ "{$kind}_singular" ];
	}

	/**
	 * Reads a per-object title/description override.
	 *
	 * @param string $meta_key Post/term meta key.
	 */
	private function override( string $meta_key ): string {
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? (string) get_post_meta( $post->ID, $meta_key, true ) : '';
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, $meta_key, true ) : '';
		}

		return '';
	}

	/**
	 * Reads a single key from the plugin's settings option.
	 *
	 * @param string $key      Settings key.
	 * @param string $fallback Value to use when the key is not set.
	 */
	private function option( string $key, string $fallback ): string {
		$settings = get_option( self::OPTION_KEY, array() );
		$value    = isset( $settings[ $key ] ) ? (string) $settings[ $key ] : $fallback;

		return StringTranslation::translate( $key, $value );
	}

	/**
	 * Built-in fallback templates, used until Settings (M7) stores its own.
	 *
	 * @return array<string,string>
	 */
	private static function defaults(): array {
		return array(
			'title_home'           => '{sitename}',
			'title_singular'       => '{title} {sep} {sitename}',
			'title_term'           => '{category} {sep} {sitename}',
			'description_home'     => '',
			'description_singular' => '{excerpt}',
			'description_term'     => '',
		);
	}
}

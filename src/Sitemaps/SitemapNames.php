<?php
/**
 * Sitemap file names: which sitemaps exist and what each one is called.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Sitemaps;

/**
 * Every sub-sitemap is served as /sitemap_{name}.xml (page 2 onwards as
 * /sitemap_{name}_2.xml), the index as /index_sitemap.xml. The default
 * name for a post type or taxonomy is readable rather than the raw slug
 * (page, posts, category, tags, products, product-category, ...) and can
 * be changed per sitemap on the Sitemaps settings page.
 *
 * The older /sitemap.xml, /sitemap-posts-{type}-{n}.xml,
 * /sitemap-taxonomies-{tax}-{n}.xml and /sitemap-news.xml URLs keep
 * working (see Router), so nothing already submitted to a search engine
 * stops resolving.
 */
final class SitemapNames {

	public const KIND_POST_TYPE = 'post_type';
	public const KIND_TAXONOMY  = 'taxonomy';
	public const KIND_IMAGES    = 'images';
	public const KIND_VIDEO     = 'video';
	public const KIND_NEWS      = 'news';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Readable default names where the plain slug would be unhelpful.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_POST_TYPE_STEMS = array(
		'post'    => 'posts',
		'product' => 'products',
	);

	/**
	 * Readable default names for taxonomies.
	 *
	 * @var array<string,string>
	 */
	private const DEFAULT_TAXONOMY_STEMS = array(
		'post_tag'    => 'tags',
		'product_cat' => 'product-category',
		'product_tag' => 'product-tag',
		'post_format' => 'post-format',
	);

	/**
	 * The index file's name.
	 */
	public static function index_url(): string {
		return home_url( '/index_sitemap.xml' );
	}

	/**
	 * URL of one page of a sitemap.
	 *
	 * @param string $stem Sitemap name (without sitemap_ / .xml).
	 * @param int    $page Page number, starting at 1.
	 */
	public static function url( string $stem, int $page = 1 ): string {
		return home_url( '/sitemap_' . $stem . ( $page > 1 ? '_' . $page : '' ) . '.xml' );
	}

	/**
	 * The settings key holding a sitemap's custom name.
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @param string $slug Post type or taxonomy slug ('' for images/video/news).
	 */
	public static function option_key( string $kind, string $slug = '' ): string {
		switch ( $kind ) {
			case self::KIND_POST_TYPE:
				return 'sitemap_name_pt_' . $slug;
			case self::KIND_TAXONOMY:
				return 'sitemap_name_tax_' . $slug;
			default:
				return 'sitemap_name_' . $kind;
		}
	}

	/**
	 * The name a sitemap gets when nothing was customised.
	 *
	 * @param string $kind One of the KIND_* constants.
	 * @param string $slug Post type or taxonomy slug ('' for images/video/news).
	 */
	public static function default_stem( string $kind, string $slug = '' ): string {
		switch ( $kind ) {
			case self::KIND_POST_TYPE:
				$stem = self::DEFAULT_POST_TYPE_STEMS[ $slug ] ?? str_replace( '_', '-', $slug );
				break;
			case self::KIND_TAXONOMY:
				$stem = self::DEFAULT_TAXONOMY_STEMS[ $slug ] ?? str_replace( '_', '-', $slug );
				break;
			default:
				$stem = $kind;
		}

		$stem = self::sanitize_stem( $stem );

		return '' !== $stem ? $stem : 'sitemap';
	}

	/**
	 * Restricts a name to lowercase letters, digits, "-" and "_", and
	 * rejects a trailing "_{number}" (that would read as a page number).
	 *
	 * @param string $raw Raw input.
	 */
	public static function sanitize_stem( string $raw ): string {
		$stem = strtolower( trim( $raw ) );
		$stem = (string) preg_replace( '/^sitemap_/', '', $stem );
		$stem = (string) preg_replace( '/\.xml$/', '', $stem );
		$stem = (string) preg_replace( '/[^a-z0-9_-]+/', '-', $stem );
		$stem = trim( $stem, '-_' );
		$stem = (string) preg_replace( '/_[0-9]+$/', '', $stem );

		return $stem;
	}

	/**
	 * Every sitemap this plugin can serve, with its name and whether it
	 * is switched on. Names are made unique: a custom name that clashes
	 * with an earlier sitemap falls back to the default (and, failing
	 * that, gets a numeric suffix), so two sitemaps can never share a URL.
	 *
	 * @return array<int,array{kind:string,slug:string,label:string,stem:string,enabled:bool,option_key:string}>
	 */
	public static function entries(): array {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$raw = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			if ( 'attachment' === $post_type->name ) {
				continue;
			}
			$raw[] = array(
				self::KIND_POST_TYPE,
				$post_type->name,
				(string) $post_type->labels->name,
				Inclusion::post_type( $post_type->name ),
			);
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$raw[] = array(
				self::KIND_TAXONOMY,
				$taxonomy->name,
				(string) $taxonomy->labels->name,
				Inclusion::taxonomy( $taxonomy->name ),
			);
		}

		$raw[] = array( self::KIND_IMAGES, '', __( 'Images', 'seooptimierung-toolkit' ), Inclusion::images_sitemap() );
		$raw[] = array( self::KIND_VIDEO, '', __( 'Videos', 'seooptimierung-toolkit' ), Inclusion::video_sitemap() );
		$raw[] = array( self::KIND_NEWS, '', __( 'Google News', 'seooptimierung-toolkit' ), ! array_key_exists( 'enable_news_sitemap', $settings ) || ! empty( $settings['enable_news_sitemap'] ) );

		$used    = array( 'index' => true );
		$entries = array();

		foreach ( $raw as $item ) {
			list( $kind, $slug, $label, $enabled ) = $item;

			$key       = self::option_key( $kind, $slug );
			$custom    = isset( $settings[ $key ] ) ? self::sanitize_stem( (string) $settings[ $key ] ) : '';
			$candidate = '' !== $custom && ! isset( $used[ $custom ] ) ? $custom : self::default_stem( $kind, $slug );

			$unique = $candidate;
			$n      = 2;
			while ( isset( $used[ $unique ] ) ) {
				$unique = $candidate . '-' . $n;
				++$n;
			}

			$used[ $unique ] = true;

			$entries[] = array(
				'kind'       => $kind,
				'slug'       => $slug,
				'label'      => $label,
				'stem'       => $unique,
				'enabled'    => (bool) $enabled,
				'option_key' => $key,
			);
		}

		return $entries;
	}

	/**
	 * Finds the sitemap behind a requested name.
	 *
	 * @param string $stem Name taken from the URL.
	 * @return array{kind:string,slug:string,label:string,stem:string,enabled:bool,option_key:string}|null
	 */
	public static function resolve( string $stem ): ?array {
		foreach ( self::entries() as $entry ) {
			if ( $entry['stem'] === $stem ) {
				return $entry;
			}
		}

		return null;
	}
}

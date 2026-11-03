<?php
/**
 * Combines, minifies and defers the site's own stylesheets and scripts.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Performance;

use WP_Dependencies;
use WP_Filesystem_Base;
use WP_Scripts;
use WP_Styles;

/**
 * Works on what WordPress is about to print, never on stored files:
 *
 * - Stylesheets and scripts of this site that sit next to each other in
 *   the print order are written into one cached file (optionally
 *   minified), and the first of them prints the tag of that file while
 *   the others print nothing — so the order, and with it the cascade and
 *   the dependencies, is exactly the one WordPress decided on.
 * - Anything that cannot be combined without risk ends the run and stays
 *   as it is: files of other sites, conditional or alternate stylesheets,
 *   scripts carrying inline code or translations, scripts that begin
 *   with "use strict", and everything on the exclusion lists.
 * - Deferring and async loading of scripts goes through WordPress core's
 *   own script loading strategies, which look after dependencies and
 *   inline code; a combined file only gets one when nothing that needs
 *   it right away depends on it.
 *
 * Every switch is off by default. Add ?seo_wp_no_optimize=1 to any
 * address to see that page without the optimizations.
 */
final class AssetOptimizer {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option remembering files that turned out not to be combinable.
	 */
	private const SKIP_OPTION = 'seo_wp_asset_skip';

	/**
	 * Folder inside the uploads folder holding the generated files.
	 */
	public const CACHE_DIR = 'seooptimierung-cache';

	/**
	 * Largest source file that is combined, in bytes.
	 */
	private const MAX_FILE_BYTES = 1500000;

	/**
	 * Entries of the JavaScript exclusion list until the owner saves one:
	 * inline code in themes and plugins calls jQuery right away.
	 */
	public const DEFAULT_JS_EXCLUDE = "jquery\njquery-core\njquery-migrate";

	/**
	 * Query arguments of page builders' editing screens, which must see
	 * the unchanged assets.
	 *
	 * @var string[]
	 */
	private const BUILDER_ARGS = array( 'elementor-preview', 'et_fb', 'fl_builder', 'ct_builder', 'bricks', 'brizy-edit', 'vc_editable', 'tve', 'siteorigin_panels_live_editor', 'customize_changeset_uuid' );

	/**
	 * Print plan of stylesheets: handle => role (first|skip), file URL, media.
	 *
	 * @var array<string,array<string,string>|null>
	 */
	private array $css_plan = array();

	/**
	 * Print plan of scripts: handle => role (first|skip), file URL, strategy.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $js_plan = array();

	/**
	 * Script groups (0 header, 1 footer) that have been planned already.
	 *
	 * @var array<int,bool>
	 */
	private array $planned_groups = array();

	/**
	 * Handles whose loading strategy this class set itself.
	 *
	 * @var array<string,bool>
	 */
	private array $own_strategies = array();

	/**
	 * Whether the optimizations apply to this request (decided once).
	 *
	 * @var bool|null
	 */
	private ?bool $applies = null;

	/**
	 * Address-prefix => folder pairs (built once per request).
	 *
	 * @var array<string,string>|null
	 */
	private ?array $bases_cache = null;

	/**
	 * Registers the filters and the cache clean-up.
	 */
	public function register(): void {
		add_filter( 'style_loader_tag', array( $this, 'filter_style_tag' ), 5, 4 );
		add_filter( 'script_loader_tag', array( $this, 'filter_script_tag' ), 5, 3 );
		add_action( 'wp_print_scripts', array( $this, 'plan_header_scripts' ), 1 );
		add_action( 'wp_print_footer_scripts', array( $this, 'plan_footer_scripts' ), 1 );

		add_action( 'update_option_' . self::OPTION_KEY, array( $this, 'purge' ) );
		add_action( 'switch_theme', array( $this, 'purge' ) );
		add_action( 'activated_plugin', array( $this, 'purge' ) );
		add_action( 'deactivated_plugin', array( $this, 'purge' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ) );
		add_action( 'wp_scheduled_delete', array( $this, 'purge_old' ) );
	}

	// -- Settings and context -------------------------------------------

	/**
	 * Whether a boolean setting is on.
	 *
	 * @param string $key Settings key.
	 */
	private function flag( string $key ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) && ! empty( $settings[ $key ] );
	}

	/**
	 * The entries of an exclusion list setting.
	 *
	 * @param string $key Settings key (css_exclude or js_exclude).
	 * @return string[]
	 */
	private function exclusions( string $key ): array {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( ! array_key_exists( $key, $settings ) && 'js_exclude' === $key ) {
			return AssetTools::parse_list( self::DEFAULT_JS_EXCLUDE );
		}

		return AssetTools::parse_list( (string) ( $settings[ $key ] ?? '' ) );
	}

	/**
	 * Whether the optimizations apply to this request: public pages only,
	 * not in the Customizer, page-builder editors, feeds or REST/AJAX
	 * requests, and not when ?seo_wp_no_optimize is in the address.
	 */
	private function applies(): bool {
		if ( null !== $this->applies ) {
			return $this->applies;
		}

		$skip = is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || 'wp-login.php' === ( $GLOBALS['pagenow'] ?? '' );

		if ( ! $skip ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- these arguments only switch the optimizations off for the current view; nothing is changed or stored.
			$skip = isset( $_GET['seo_wp_no_optimize'] );
			foreach ( self::BUILDER_ARGS as $arg ) {
				$skip = $skip || isset( $_GET[ $arg ] );
			}
			// phpcs:enable
		}

		/**
		 * Filters whether the asset optimizer is skipped for this request.
		 *
		 * @param bool $skip Whether to skip.
		 */
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- namespaced public extension point, documented for third-party developers.
		$this->applies = ! (bool) apply_filters( 'seooptimierung/assets/skip', $skip );

		return $this->applies;
	}

	// -- Files and cache ------------------------------------------------

	/**
	 * The direct filesystem, or null when WordPress would need FTP
	 * credentials (then nothing is written and nothing is changed).
	 */
	private function filesystem(): ?WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		return $wp_filesystem instanceof WP_Filesystem_Base && 'direct' === $wp_filesystem->method ? $wp_filesystem : null;
	}

	/**
	 * Folder and address of the cache for one file type.
	 *
	 * @param string $type css or js.
	 * @return array{dir:string,url:string}|null Null when the uploads folder is not usable.
	 */
	private function cache_location( string $type ): ?array {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return null;
		}

		return array(
			'dir' => trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/' . $type,
			'url' => set_url_scheme( trailingslashit( $uploads['baseurl'] ) . self::CACHE_DIR . '/' . $type ),
		);
	}

	/**
	 * Address-prefix => folder pairs for mapping a file address to disk.
	 *
	 * @return array<string,string>
	 */
	private function bases(): array {
		if ( null !== $this->bases_cache ) {
			return $this->bases_cache;
		}

		$uploads = wp_upload_dir( null, false );
		$bases   = array(
			content_url()   => untrailingslashit( WP_CONTENT_DIR ),
			includes_url()  => untrailingslashit( ABSPATH . WPINC ),
			site_url( '/' ) => untrailingslashit( ABSPATH ),
			home_url( '/' ) => untrailingslashit( ABSPATH ),
		);
		if ( empty( $uploads['error'] ) ) {
			$bases[ $uploads['baseurl'] ] = $uploads['basedir'];
		}

		$this->bases_cache = $bases;

		return $bases;
	}

	/**
	 * The file on disk behind a stylesheet or script address, when it is
	 * a readable file of the expected type inside this WordPress
	 * installation; otherwise ''.
	 *
	 * @param string $url       Address.
	 * @param string $extension css or js.
	 */
	private function local_path( string $url, string $extension ): string {
		$path = AssetTools::url_to_path( $url, $this->bases() );
		if ( '' === $path || strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) !== $extension ) {
			return '';
		}

		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || (int) filesize( $real ) > self::MAX_FILE_BYTES ) {
			return '';
		}

		$roots = array( realpath( ABSPATH ), realpath( WP_CONTENT_DIR ) );
		foreach ( $roots as $root ) {
			if ( false !== $root && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) && false === strpos( $real, DIRECTORY_SEPARATOR . self::CACHE_DIR . DIRECTORY_SEPARATOR ) ) {
				return $real;
			}
		}

		return '';
	}

	/**
	 * The files this class has found to be not combinable.
	 *
	 * @return array<string,bool>
	 */
	private function skipped(): array {
		$skip = get_option( self::SKIP_OPTION, array() );

		return is_array( $skip ) ? $skip : array();
	}

	/**
	 * Remembers that a file must not be combined (it changes with its
	 * modification time, so an updated file is tried again).
	 *
	 * @param string $identity File identity from identity().
	 */
	private function remember_skip( string $identity ): void {
		$skip              = $this->skipped();
		$skip[ $identity ] = true;
		update_option( self::SKIP_OPTION, $skip, false );
	}

	/**
	 * A key for one file that changes whenever the file does.
	 *
	 * @param string $path Path on disk.
	 */
	private function identity( string $path ): string {
		return md5( $path . '|' . (int) filemtime( $path ) . '|' . (int) filesize( $path ) );
	}

	/**
	 * Deletes every generated file and the list of skipped files.
	 */
	public function purge(): void {
		delete_option( self::SKIP_OPTION );

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return;
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR;
		$fs  = $this->filesystem();
		if ( null !== $fs && $fs->is_dir( $dir ) ) {
			$fs->delete( $dir, true );
		}
	}

	/**
	 * Deletes generated files that have not been rewritten for a month
	 * (daily, with WordPress' own clean-up job).
	 */
	public function purge_old(): void {
		$uploads = wp_upload_dir( null, false );
		$fs      = $this->filesystem();
		if ( null === $fs || ! empty( $uploads['error'] ) ) {
			return;
		}

		foreach ( array( 'css', 'js' ) as $type ) {
			$dir = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/' . $type;
			foreach ( (array) $fs->dirlist( $dir ) as $name => $info ) {
				if ( isset( $info['lastmodunix'] ) && (int) $info['lastmodunix'] < time() - 30 * DAY_IN_SECONDS ) {
					$fs->delete( $dir . '/' . $name );
				}
			}
		}
	}

	/**
	 * Number and size of the generated files, for the settings page.
	 *
	 * @return array{files:int,bytes:int}
	 */
	public function cache_stats(): array {
		$stats   = array(
			'files' => 0,
			'bytes' => 0,
		);
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) ) {
			return $stats;
		}

		foreach ( array( 'css', 'js' ) as $type ) {
			$dir   = trailingslashit( $uploads['basedir'] ) . self::CACHE_DIR . '/' . $type;
			$files = glob( $dir . '/*.' . $type );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				++$stats['files'];
				$stats['bytes'] += (int) filesize( $file );
			}
		}

		return $stats;
	}

	/**
	 * Writes a generated file unless it exists already.
	 *
	 * @param string   $type    css or js.
	 * @param string   $name    File name without extension.
	 * @param callable $content Builds the file content, called only when the file is missing.
	 * @return string The file's address, or '' when it could not be written.
	 */
	private function ensure_file( string $type, string $name, callable $content ): string {
		$where = $this->cache_location( $type );
		$fs    = $this->filesystem();
		if ( null === $where || null === $fs ) {
			return '';
		}

		$file = $where['dir'] . '/' . $name . '.' . $type;
		if ( ! $fs->exists( $file ) ) {
			$body = $content();
			if ( ! is_string( $body ) || '' === $body || ! wp_mkdir_p( $where['dir'] ) ) {
				return '';
			}

			$temp = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
			if ( ! $fs->put_contents( $temp, $body, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ) {
				return '';
			}
			if ( ! $fs->move( $temp, $file, true ) ) {
				$fs->delete( $temp );
				return '';
			}
		}

		return $where['url'] . '/' . $name . '.' . $type;
	}

	// -- Stylesheets ----------------------------------------------------

	/**
	 * Replaces the tag of a stylesheet that is part of a combined file.
	 *
	 * @param string $tag    The <link> tag WordPress built.
	 * @param string $handle Handle.
	 * @param string $href   Address.
	 * @param string $media  Media attribute.
	 */
	public function filter_style_tag( string $tag, string $handle, string $href, string $media ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter always passes these arguments; only the tag and handle are needed.
		if ( ! $this->applies() || ! ( $this->flag( 'css_combine' ) || $this->flag( 'css_minify' ) ) ) {
			return $tag;
		}

		global $wp_styles;
		if ( ! $wp_styles instanceof WP_Styles ) {
			return $tag;
		}

		if ( ! array_key_exists( $handle, $this->css_plan ) ) {
			$this->plan_styles( $wp_styles, $handle );
		}

		$entry = $this->css_plan[ $handle ] ?? null;
		if ( null === $entry ) {
			return $tag;
		}
		if ( 'skip' === $entry['role'] ) {
			return '';
		}

		return sprintf(
			"<link rel='stylesheet' id='%s' href='%s' media='%s' />\n", // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- this replaces the tag WordPress itself is printing for stylesheets that were enqueued.
			esc_attr( $entry['id'] ),
			esc_url( $entry['url'] ),
			esc_attr( $entry['media'] )
		);
	}

	/**
	 * Plans the stylesheets that remain to be printed, starting with $current.
	 *
	 * @param WP_Styles $styles  The style queue.
	 * @param string    $current The handle being printed now.
	 */
	private function plan_styles( WP_Styles $styles, string $current ): void {
		$order = array_values( $styles->to_do );
		$index = array_search( $current, $order, true );
		$order = false === $index ? array( $current ) : array_slice( $order, (int) $index );

		$exclude = $this->exclusions( 'css_exclude' );
		$skipped = $this->skipped();

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$items      = array();
			$candidates = array();
			foreach ( $order as $handle ) {
				$object = $styles->registered[ $handle ] ?? null;
				if ( null === $object ) {
					continue;
				}

				$after = array_filter( (array) ( $object->extra['after'] ?? array() ) );
				if ( ! is_string( $object->src ) || '' === $object->src ) {
					if ( array() !== $after ) {
						$items[] = array( $handle, null );
					}
					continue;
				}

				$candidate = $this->style_candidate( $styles, $handle, $object, $exclude, $skipped );
				if ( null === $candidate ) {
					$items[] = array( $handle, null );
					continue;
				}

				$candidates[ $handle ] = $candidate;
				$items[]               = array( $handle, $candidate['media'] );
			}

			$retry = false;
			foreach ( AssetTools::plan_runs( $items, $this->flag( 'css_combine' ) ) as $run ) {
				$built = $this->build_style_run( $styles, $run, $candidates );
				if ( is_string( $built ) ) {
					// A file turned out not to be combinable: leave it out and plan again.
					$skipped[ $built ] = true;
					$this->remember_skip( $built );
					$retry = true;
					break;
				}
			}

			if ( ! $retry ) {
				break;
			}
		}

		// Remember what is not touched, so it is not planned again for every tag.
		foreach ( $order as $handle ) {
			if ( ! array_key_exists( $handle, $this->css_plan ) ) {
				$this->css_plan[ $handle ] = null;
			}
		}
	}

	/**
	 * Whether a stylesheet may be combined, and the facts needed for it.
	 *
	 * @param WP_Styles          $styles  The style queue.
	 * @param string             $handle  Handle.
	 * @param object             $asset   The registered stylesheet.
	 * @param string[]           $exclude Exclusion list.
	 * @param array<string,bool> $skipped Files known not to be combinable.
	 * @return array{url:string,path:string,media:string,identity:string}|null
	 */
	private function style_candidate( WP_Styles $styles, string $handle, object $asset, array $exclude, array $skipped ): ?array {
		$extra = (array) $asset->extra;
		if ( isset( $extra['conditional'] ) || isset( $extra['alt'] ) || isset( $extra['title'] ) || ( is_rtl() && ! empty( $extra['rtl'] ) ) ) {
			return null;
		}

		$url = $this->final_src( $styles, (string) $asset->src );
		if ( AssetTools::is_excluded( $handle, $url, $exclude ) ) {
			return null;
		}

		$path = $this->local_path( $url, 'css' );
		if ( '' === $path ) {
			return null;
		}

		$identity = $this->identity( $path );
		if ( isset( $skipped[ $identity ] ) ) {
			return null;
		}

		$media = is_string( $asset->args ) && '' !== $asset->args ? $asset->args : 'all';

		return array(
			'url'      => $url,
			'path'     => $path,
			'media'    => $media,
			'identity' => $identity,
		);
	}

	/**
	 * The address WordPress will print for a registered source.
	 *
	 * @param WP_Dependencies $deps Queue object (styles or scripts).
	 * @param string          $src  Registered source.
	 */
	private function final_src( WP_Dependencies $deps, string $src ): string {
		$content_url = (string) ( $deps->content_url ?? '' );
		if ( 1 !== preg_match( '#^(https?:)?//#', $src ) && ! ( '' !== $content_url && 0 === strpos( $src, $content_url ) ) ) {
			$src = (string) ( $deps->base_url ?? '' ) . $src;
		}

		return set_url_scheme( $src );
	}

	/**
	 * Builds the combined file of one run of stylesheets and records the plan.
	 *
	 * @param WP_Styles                                                                $styles     The style queue.
	 * @param string[]                                                                 $run        Handles of the run.
	 * @param array<string,array{url:string,path:string,media:string,identity:string}> $candidates Facts per handle.
	 * @return string|null The identity of a file that cannot be combined, or null when all went well.
	 */
	private function build_style_run( WP_Styles $styles, array $run, array $candidates ): ?string {
		$minify = $this->flag( 'css_minify' );
		$parts  = array();
		$ident  = array( home_url(), $minify ? 'min' : 'raw', \Seooptimierung\Toolkit\VERSION );
		$after  = array();

		foreach ( $run as $handle ) {
			$after[ $handle ] = implode( "\n", array_filter( (array) ( $styles->registered[ $handle ]->extra['after'] ?? array() ) ) );
			$ident[]          = $candidates[ $handle ]['identity'] . ':' . md5( $after[ $handle ] );
		}

		$name   = substr( md5( implode( '|', $ident ) ), 0, 20 );
		$failed = null;
		$url    = $this->ensure_file(
			'css',
			$name,
			function () use ( $run, $candidates, $minify, $after, &$failed ): string {
				$fs   = $this->filesystem();
				$body = '';
				foreach ( $run as $handle ) {
					$source = null === $fs ? false : $fs->get_contents( $candidates[ $handle ]['path'] );
					if ( ! is_string( $source ) || AssetTools::css_has_import( $source ) ) {
						$failed = $candidates[ $handle ]['identity'];
						return '';
					}

					$css = AssetTools::absolutize_css_urls( AssetTools::strip_source_map_comment( $source ), $candidates[ $handle ]['url'] );
					if ( $minify && 1 !== preg_match( '/\.min\.css$/i', $candidates[ $handle ]['path'] ) ) {
						$css = AssetTools::minify_css( $css );
					}
					$body .= trim( $css ) . "\n";

					if ( '' !== $after[ $handle ] ) {
						$body .= ( $minify ? AssetTools::minify_css( $after[ $handle ] ) : $after[ $handle ] ) . "\n";
					}
				}

				return $body;
			}
		);

		if ( null !== $failed ) {
			return $failed;
		}
		if ( '' === $url ) {
			return null;
		}

		$media = $candidates[ $run[0] ]['media'];
		foreach ( $run as $position => $handle ) {
			$this->css_plan[ $handle ] = array(
				'role'  => 0 === $position ? 'first' : 'skip',
				'url'   => $url,
				'media' => $media,
				'id'    => 'seooptimierung-css-' . $name,
			);
			// The inline CSS of the handle is part of the file now.
			if ( isset( $styles->registered[ $handle ]->extra['after'] ) ) {
				$styles->registered[ $handle ]->extra['after'] = array();
			}
		}

		return null;
	}

	// -- Scripts ---------------------------------------------------------

	/**
	 * Plans the scripts of the page header.
	 */
	public function plan_header_scripts(): void {
		$this->plan_scripts( 0 );
	}

	/**
	 * Plans the scripts of the page footer.
	 */
	public function plan_footer_scripts(): void {
		$this->plan_scripts( 1 );
	}

	/**
	 * Replaces the tag of a script that is part of a combined file.
	 *
	 * @param string $tag    The <script> tag WordPress built.
	 * @param string $handle Handle.
	 * @param string $src    Address.
	 */
	public function filter_script_tag( string $tag, string $handle, string $src ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- the filter always passes the address; only the tag and handle are needed.
		$entry = $this->js_plan[ $handle ] ?? null;
		if ( null === $entry || ! $this->applies() ) {
			return $tag;
		}
		if ( 'skip' === $entry['role'] ) {
			return '';
		}

		$attributes = array(
			'src' => $entry['url'],
			'id'  => $entry['id'],
		);
		if ( 'defer' === $entry['strategy'] || 'async' === $entry['strategy'] ) {
			$attributes[ $entry['strategy'] ] = true;
		}

		return wp_get_script_tag( $attributes );
	}

	/**
	 * Plans one script group: which neighbours go into a combined file,
	 * and which loading strategy every script gets.
	 *
	 * @param int $group 0 for the header, 1 for the footer.
	 */
	private function plan_scripts( int $group ): void {
		if ( isset( $this->planned_groups[ $group ] ) || ! $this->applies() ) {
			return;
		}

		$combine = $this->flag( 'js_combine' );
		$defer   = $this->flag( 'js_defer' );
		$async   = $this->flag( 'js_async' );
		if ( ! $combine && ! $this->flag( 'js_minify' ) && ! $defer && ! $async ) {
			return;
		}

		global $wp_scripts;
		if ( ! $wp_scripts instanceof WP_Scripts ) {
			return;
		}
		$this->planned_groups[ $group ] = true;

		$closure        = clone $wp_scripts;
		$closure->to_do = array();
		$closure->all_deps( $closure->queue );
		$all        = array_values( array_unique( $closure->to_do ) );
		$dependents = $this->dependents( $wp_scripts, $all );
		$exclude    = $this->exclusions( 'js_exclude' );
		$skipped    = $this->skipped();
		$in_group   = array();

		foreach ( $all as $handle ) {
			$object       = $wp_scripts->registered[ $handle ] ?? null;
			$handle_group = (int) ( $closure->groups[ $handle ] ?? ( $object->extra['group'] ?? 0 ) );
			if ( null !== $object && $handle_group === $group ) {
				$in_group[] = $handle;
			}
		}

		$runs = array();
		if ( $combine || $this->flag( 'js_minify' ) ) {
			for ( $attempt = 0; $attempt < 3; $attempt++ ) {
				$items      = array();
				$candidates = array();
				foreach ( $in_group as $handle ) {
					$object = $wp_scripts->registered[ $handle ];
					if ( ! is_string( $object->src ) || '' === $object->src ) {
						continue;
					}

					$candidate = $this->script_candidate( $wp_scripts, $handle, $object, $exclude, $skipped );
					$items[]   = array( $handle, null === $candidate ? null : 'js' );
					if ( null !== $candidate ) {
						$candidates[ $handle ] = $candidate;
					}
				}

				$runs  = array();
				$retry = false;
				foreach ( AssetTools::plan_runs( $items, $combine ) as $run ) {
					$built = $this->build_script_run( $run, $candidates );
					if ( is_string( $built ) ) {
						$skipped[ $built ] = true;
						$this->remember_skip( $built );
						$retry = true;
						break;
					}
					if ( is_array( $built ) ) {
						$runs[] = $built;
					}
				}
				if ( ! $retry ) {
					break;
				}
			}
		}

		$in_run = array();
		foreach ( $runs as $run ) {
			foreach ( $run['handles'] as $handle ) {
				$in_run[ $handle ] = true;
			}
		}

		// Runs first: decide every run's strategy, then make them consistent — a
		// run must not wait for later while another run that needs it runs right away.
		$strategies = array();
		$run_of     = array();
		foreach ( $runs as $index => $run ) {
			$strategies[ $index ] = $this->run_strategy( $wp_scripts, $run['handles'], $dependents, $defer, $async, $exclude );
			foreach ( $run['handles'] as $handle ) {
				$run_of[ $handle ] = $index;
			}
		}

		do {
			$changed = false;
			foreach ( $runs as $index => $run ) {
				if ( '' !== $strategies[ $index ] ) {
					foreach ( $run['handles'] as $handle ) {
						foreach ( $dependents[ $handle ] ?? array() as $dependent ) {
							if ( isset( $run_of[ $dependent ] ) && $run_of[ $dependent ] !== $index && '' === $strategies[ $run_of[ $dependent ] ] ) {
								$strategies[ $index ] = '';
								$changed              = true;
								break 2;
							}
						}
					}
				}
			}
		} while ( $changed );

		$blocking = array();
		foreach ( $runs as $index => $run ) {
			$strategy = $strategies[ $index ];
			foreach ( $run['handles'] as $position => $handle ) {
				$this->js_plan[ $handle ] = array(
					'role'     => 0 === $position ? 'first' : 'skip',
					'url'      => $run['url'],
					'id'       => 'seooptimierung-js-' . $run['name'],
					'strategy' => $strategy,
				);
				$this->set_strategy( $wp_scripts, $handle, $strategy );
			}
			if ( '' === $strategy ) {
				foreach ( $this->outside_dependencies( $wp_scripts, $run['handles'] ) as $needed ) {
					$blocking[ $needed ] = true;
					$this->set_strategy( $wp_scripts, $needed, '' );
				}
			}
		}

		if ( ! $defer && ! $async ) {
			return;
		}

		foreach ( $in_group as $handle ) {
			if ( isset( $in_run[ $handle ] ) || isset( $blocking[ $handle ] ) ) {
				continue;
			}

			$object = $wp_scripts->registered[ $handle ];
			if ( ! is_string( $object->src ) || '' === $object->src || AssetTools::is_excluded( $handle, (string) $object->src, $exclude ) ) {
				continue;
			}

			$extra    = (array) $object->extra;
			$existing = isset( $extra['strategy'] ) ? (string) $extra['strategy'] : '';
			if ( isset( $extra['conditional'] ) || array() !== array_filter( (array) ( $extra['after'] ?? array() ) ) || ( '' !== $existing && empty( $this->own_strategies[ $handle ] ) ) ) {
				continue;
			}

			if ( $async && array() === (array) $object->deps && empty( $dependents[ $handle ] ) ) {
				$this->set_strategy( $wp_scripts, $handle, 'async' );
			} elseif ( $defer ) {
				$this->set_strategy( $wp_scripts, $handle, 'defer' );
			}
		}
	}

	/**
	 * Which scripts depend, directly or through other handles, on which.
	 *
	 * @param WP_Scripts $scripts The script queue.
	 * @param string[]   $all     Every handle that will be printed.
	 * @return array<string,string[]> Handle => handles that need it (only ones that print a file).
	 */
	private function dependents( WP_Scripts $scripts, array $all ): array {
		$direct = array();
		foreach ( $all as $handle ) {
			foreach ( (array) ( $scripts->registered[ $handle ]->deps ?? array() ) as $dependency ) {
				$direct[ (string) $dependency ][] = $handle;
			}
		}

		$result = array();
		foreach ( $all as $handle ) {
			$seen  = array();
			$queue = $direct[ $handle ] ?? array();
			while ( array() !== $queue ) {
				$next = (string) array_shift( $queue );
				if ( isset( $seen[ $next ] ) ) {
					continue;
				}
				$seen[ $next ] = true;
				$queue         = array_merge( $queue, $direct[ $next ] ?? array() );
			}

			foreach ( array_keys( $seen ) as $dependent ) {
				$source = $scripts->registered[ $dependent ]->src ?? false;
				if ( is_string( $source ) && '' !== $source ) {
					$result[ $handle ][] = $dependent;
				}
			}
		}

		return $result;
	}

	/**
	 * The loading strategy for a combined file: "defer" when switched on
	 * and nothing that must run immediately needs it, "async" when it is
	 * independent of everything, otherwise '' (blocking).
	 *
	 * @param WP_Scripts             $scripts    The script queue.
	 * @param string[]               $run        Handles of the run.
	 * @param array<string,string[]> $dependents Dependents per handle.
	 * @param bool                   $defer      Defer is switched on.
	 * @param bool                   $async      Async is switched on.
	 * @param string[]               $exclude    Exclusion list.
	 */
	private function run_strategy( WP_Scripts $scripts, array $run, array $dependents, bool $defer, bool $async, array $exclude ): string {
		if ( ! $defer && ! $async ) {
			return '';
		}

		$outside_dependents = array();
		foreach ( $run as $handle ) {
			foreach ( $dependents[ $handle ] ?? array() as $dependent ) {
				if ( ! in_array( $dependent, $run, true ) ) {
					$outside_dependents[ $dependent ] = true;
				}
			}
		}

		foreach ( array_keys( $outside_dependents ) as $dependent ) {
			$object = $scripts->registered[ $dependent ];
			$after  = array_filter( (array) ( $object->extra['after'] ?? array() ) );
			if ( array() !== $after || AssetTools::is_excluded( $dependent, (string) $object->src, $exclude ) ) {
				return '';
			}
		}

		if ( $async && array() === $outside_dependents && array() === $this->outside_dependencies( $scripts, $run ) ) {
			return 'async';
		}

		return $defer ? 'defer' : '';
	}

	/**
	 * Handles the run depends on that are not part of it (with their own
	 * dependencies), for as far as they are printed as files.
	 *
	 * @param WP_Scripts $scripts The script queue.
	 * @param string[]   $run     Handles of the run.
	 * @return string[]
	 */
	private function outside_dependencies( WP_Scripts $scripts, array $run ): array {
		$found = array();
		$queue = array();
		foreach ( $run as $handle ) {
			$queue = array_merge( $queue, (array) ( $scripts->registered[ $handle ]->deps ?? array() ) );
		}

		while ( array() !== $queue ) {
			$next = (string) array_shift( $queue );
			if ( isset( $found[ $next ] ) || in_array( $next, $run, true ) || ! isset( $scripts->registered[ $next ] ) ) {
				continue;
			}
			$found[ $next ] = true;
			$queue          = array_merge( $queue, (array) $scripts->registered[ $next ]->deps );
		}

		return array_keys( $found );
	}

	/**
	 * Sets a script's loading strategy through WordPress core ('' removes ours).
	 *
	 * @param WP_Scripts $scripts  The script queue.
	 * @param string     $handle   Handle.
	 * @param string     $strategy defer, async or '' for blocking.
	 */
	private function set_strategy( WP_Scripts $scripts, string $handle, string $strategy ): void {
		if ( ! isset( $scripts->registered[ $handle ] ) ) {
			return;
		}

		if ( '' === $strategy ) {
			if ( ! empty( $this->own_strategies[ $handle ] ) ) {
				unset( $scripts->registered[ $handle ]->extra['strategy'], $this->own_strategies[ $handle ] );
			}
			return;
		}

		$scripts->add_data( $handle, 'strategy', $strategy );
		$this->own_strategies[ $handle ] = true;
	}

	/**
	 * Whether a script may be combined, and the facts needed for it.
	 *
	 * @param WP_Scripts         $scripts The script queue.
	 * @param string             $handle  Handle.
	 * @param object             $asset   The registered script.
	 * @param string[]           $exclude Exclusion list.
	 * @param array<string,bool> $skipped Files known not to be combinable.
	 * @return array{url:string,path:string,identity:string}|null
	 */
	private function script_candidate( WP_Scripts $scripts, string $handle, object $asset, array $exclude, array $skipped ): ?array {
		$extra = (array) $asset->extra;
		if ( isset( $extra['conditional'] ) || ! empty( $extra['data'] ) || array() !== array_filter( (array) ( $extra['before'] ?? array() ) ) || array() !== array_filter( (array) ( $extra['after'] ?? array() ) ) ) {
			return null;
		}
		if ( ! empty( $extra['strategy'] ) && empty( $this->own_strategies[ $handle ] ) ) {
			return null;
		}

		$url = $this->final_src( $scripts, (string) $asset->src );
		if ( AssetTools::is_excluded( $handle, $url, $exclude ) ) {
			return null;
		}

		$path = $this->local_path( $url, 'js' );
		if ( '' === $path ) {
			return null;
		}

		$identity = $this->identity( $path );
		if ( isset( $skipped[ $identity ] ) ) {
			return null;
		}

		return array(
			'url'      => $url,
			'path'     => $path,
			'identity' => $identity,
		);
	}

	/**
	 * Builds the combined file of one run of scripts.
	 *
	 * @param string[]                                                    $run        Handles of the run.
	 * @param array<string,array{url:string,path:string,identity:string}> $candidates Facts per handle.
	 * @return array{handles:string[],url:string,name:string}|string|null The run, the identity of a file that cannot be combined, or null when no file could be written.
	 */
	private function build_script_run( array $run, array $candidates ) {
		$minify = $this->flag( 'js_minify' );
		$ident  = array( home_url(), $minify ? 'min' : 'raw', \Seooptimierung\Toolkit\VERSION );
		foreach ( $run as $handle ) {
			$ident[] = $candidates[ $handle ]['identity'];
		}

		$name   = substr( md5( implode( '|', $ident ) ), 0, 20 );
		$failed = null;
		$url    = $this->ensure_file(
			'js',
			$name,
			function () use ( $run, $candidates, $minify, &$failed ): string {
				$fs   = $this->filesystem();
				$body = '';
				foreach ( $run as $handle ) {
					$source = null === $fs ? false : $fs->get_contents( $candidates[ $handle ]['path'] );
					if ( ! is_string( $source ) || AssetTools::js_starts_strict( $source ) ) {
						$failed = $candidates[ $handle ]['identity'];
						return '';
					}

					$js = AssetTools::strip_source_map_comment( $source );
					if ( $minify && 1 !== preg_match( '/\.min\.js$/i', $candidates[ $handle ]['path'] ) ) {
						$js = AssetTools::minify_js( $js );
					}
					$body .= trim( $js ) . "\n;\n";
				}

				return $body;
			}
		);

		if ( null !== $failed ) {
			return $failed;
		}
		if ( '' === $url ) {
			return null;
		}

		return array(
			'handles' => $run,
			'url'     => $url,
			'name'    => $name,
		);
	}
}

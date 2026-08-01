<?php
/**
 * Guided import wizard UI.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Import\Batch;
use SeoWp\Import\Detector;

/**
 * Walks the site owner through detecting, dry-running and running the
 * import batches (posts, then terms, then Yoast author settings) via
 * admin-ajax, one small page at a time so large sites never time out.
 */
final class ImportWizard {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-import';

	/**
	 * Capability required to run an import.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Nonce action shared by the AJAX endpoint.
	 */
	private const NONCE_ACTION = 'seo_wp_import';

	/**
	 * AJAX action name.
	 */
	private const AJAX_ACTION = 'seo_wp_import_batch';

	/**
	 * Sources this wizard knows how to import from, with their display
	 * labels.
	 *
	 * @var array<string,string>
	 */
	private const SOURCES = array(
		'yoast'     => 'Yoast SEO',
		'rank_math' => 'Rank Math',
		'aioseo'    => 'All in One SEO',
	);

	/**
	 * Registers the submenu page, its assets and the AJAX endpoint.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Adds the Import submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Import', 'seo-wp' ),
			__( 'Import', 'seo-wp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads this page's script (and the shared admin stylesheet) on this
	 * page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
		wp_enqueue_script( 'seo-wp-import-wizard', plugins_url( 'assets/admin/import-wizard.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		wp_localize_script(
			'seo-wp-import-wizard',
			'seoWpImport',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => self::AJAX_ACTION,
				'i18n'    => array(
					'processing' => __( 'Processing…', 'seo-wp' ),
					'done'       => __( 'Done.', 'seo-wp' ),
					'error'      => __( 'Something went wrong.', 'seo-wp' ),
				),
			)
		);
	}

	/**
	 * Renders the wizard page: one section per detected source.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$detector = new Detector();
		$detected = $detector->detected();

		echo '<div class="wrap seo-wp-import-wizard">';
		echo '<h1>' . esc_html__( 'SEO-WP Import', 'seo-wp' ) . '</h1>';

		if ( array() === $detected ) {
			echo '<p>' . esc_html__( 'No supported SEO plugin data was detected on this site.', 'seo-wp' ) . '</p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'Data was found from the plugins below. Redirects are never migrated by this import.', 'seo-wp' ) . '</p>';

		foreach ( $detected as $source ) {
			$this->render_source_section( $source, $detector->redirects_present( $source ) );
		}

		echo '</div>';
	}

	/**
	 * Renders one source's dry-run/import controls.
	 *
	 * @param string $source        Source key.
	 * @param bool   $has_redirects Whether redirect data was found for this source.
	 */
	private function render_source_section( string $source, bool $has_redirects ): void {
		printf( '<div class="seo-wp-import-source" data-seo-wp-source="%s">', esc_attr( $source ) );
		printf( '<h2>%s</h2>', esc_html( self::SOURCES[ $source ] ?? $source ) );

		if ( $has_redirects ) {
			echo '<p class="description">' . esc_html__( 'Redirects were also found for this plugin and will not be imported.', 'seo-wp' ) . '</p>';
		}

		echo '<p>';
		echo '<button type="button" class="button seo-wp-import-run" data-seo-wp-dry-run="1">' . esc_html__( 'Dry run (preview only)', 'seo-wp' ) . '</button> ';
		echo '<button type="button" class="button button-primary seo-wp-import-run" data-seo-wp-dry-run="0">' . esc_html__( 'Import now', 'seo-wp' ) . '</button>';
		echo '</p>';
		echo '<div class="seo-wp-import-progress" hidden><progress max="100" value="0"></progress> <span class="seo-wp-import-status"></span></div>';
		echo '<div class="seo-wp-import-log"></div>';
		echo '</div>';
	}

	/**
	 * Handles one AJAX batch step: runs one page of one stage for a
	 * source and reports what to call next.
	 */
	public function handle_ajax(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$stage   = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : 'posts';
		$page    = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$dry_run = ! empty( $_POST['dry_run'] );

		if ( ! isset( self::SOURCES[ $source ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown source.', 'seo-wp' ) ), 400 );
		}

		$batch = new Batch();

		if ( 1 === $page && 'posts' === $stage ) {
			$batch->reset_log();
			$batch->log_skipped_redirects( $source );
		}

		switch ( $stage ) {
			case 'terms':
				$result     = $batch->run_terms_page( $source, $page, $dry_run );
				$next_stage = $result['has_more'] ? 'terms' : 'authors';
				break;
			case 'authors':
				$result     = $batch->run_authors_page( $source, $page, $dry_run );
				$next_stage = $result['has_more'] ? 'authors' : 'done';
				break;
			case 'posts':
			default:
				$result     = $batch->run_posts_page( $source, $page, $dry_run );
				$next_stage = $result['has_more'] ? 'posts' : 'terms';
				break;
		}

		$next_page = $next_stage === $stage ? $page + 1 : 1;

		wp_send_json_success(
			array(
				'done'      => 'done' === $next_stage,
				'stage'     => $next_stage,
				'page'      => $next_page,
				'processed' => $result['processed'],
				'entries'   => $result['log'],
			)
		);
	}
}

<?php
/**
 * Guided import wizard UI.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Import\Batch;
use Seooptimierung\Toolkit\Import\Detector;
use Seooptimierung\Toolkit\Import\PluginRegistry;
use Seooptimierung\Toolkit\Support\Ajax;

/**
 * Walks the site owner through detecting, dry-running and running the
 * import batches (posts, then terms, then author settings for Yoast/Rank
 * Math) via admin-ajax, one small page at a time so large sites never
 * time out.
 */
final class ImportWizard {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seooptimierung-import';

	/**
	 * Capability required to run an import.
	 */
	private const CAPABILITY = 'seo_wp_import';

	/**
	 * Nonce action shared by the AJAX endpoint.
	 */
	private const NONCE_ACTION = 'seo_wp_import';

	/**
	 * AJAX action name for a batch step.
	 */
	private const AJAX_ACTION = 'seo_wp_import_batch';

	/**
	 * AJAX action name for the explicit "deactivate the source plugin"
	 * click.
	 */
	private const DEACTIVATE_AJAX_ACTION = 'seo_wp_import_deactivate';

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
		add_action( 'wp_ajax_' . self::DEACTIVATE_AJAX_ACTION, array( $this, 'handle_deactivate_ajax' ) );
	}

	/**
	 * Adds the Import submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Import', 'seooptimierung-toolkit' ),
			__( 'Import', 'seooptimierung-toolkit' ),
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

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION );
		wp_enqueue_script( 'seo-wp-import-wizard', plugins_url( 'assets/admin/import-wizard.js', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION, true );

		wp_localize_script(
			'seo-wp-import-wizard',
			'seoWpImport',
			array(
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( self::NONCE_ACTION ),
				'action'           => self::AJAX_ACTION,
				'deactivateAction' => self::DEACTIVATE_AJAX_ACTION,
				'i18n'             => array(
					'processing'        => __( 'Processing…', 'seooptimierung-toolkit' ),
					'done'              => __( 'Done.', 'seooptimierung-toolkit' ),
					'error'             => __( 'Something went wrong.', 'seooptimierung-toolkit' ),
					'deactivating'      => __( 'Deactivating…', 'seooptimierung-toolkit' ),
					'deactivated'       => __( 'Deactivated.', 'seooptimierung-toolkit' ),
					'deactivateFailed'  => __( 'Could not deactivate it automatically — deactivate it from the Plugins screen.', 'seooptimierung-toolkit' ),
					'confirmDeactivate' => __( 'Deactivate this plugin now?', 'seooptimierung-toolkit' ),
					/* translators: {{processed}} and {{found}} are placeholders filled in by JavaScript, not sprintf() — keep them exactly as-is. */
					'progressSummary'   => __( '{{processed}} checked, {{found}} with data', 'seooptimierung-toolkit' ),
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
		echo '<h1>' . esc_html__( 'Seooptimierung Toolkit Import', 'seooptimierung-toolkit' ) . '</h1>';

		if ( array() === $detected ) {
			echo '<p>' . esc_html__( 'No supported SEO plugin data was detected on this site.', 'seooptimierung-toolkit' ) . '</p></div>';
			return;
		}

		echo '<p>' . esc_html__( 'Data was found from the plugins below. Object meta, term meta and (Yoast/Rank Math) global settings are imported; redirects are never migrated. Safe to re-run: a field already set in Seooptimierung Toolkit — whether from a previous import or edited by hand — is never overwritten, so running this again only picks up new or previously-empty content.', 'seooptimierung-toolkit' ) . '</p>';

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
			echo '<p class="description">' . esc_html__( 'Redirects were also found for this plugin and will not be imported.', 'seooptimierung-toolkit' ) . '</p>';
		}

		echo '<p>';
		echo '<button type="button" class="button seo-wp-import-run" data-seo-wp-dry-run="1">' . esc_html__( 'Dry run (preview only)', 'seooptimierung-toolkit' ) . '</button> ';
		echo '<button type="button" class="button button-primary seo-wp-import-run" data-seo-wp-dry-run="0">' . esc_html__( 'Import now', 'seooptimierung-toolkit' ) . '</button>';
		echo '</p>';
		echo '<div class="seo-wp-import-progress" hidden><progress max="100" value="0"></progress> <span class="seo-wp-import-status"></span></div>';
		echo '<div class="seo-wp-import-log"></div>';

		if ( '' !== PluginRegistry::active_plugin_file( $source ) ) {
			printf(
				'<p class="seo-wp-import-deactivate-wrap" hidden><button type="button" class="button seo-wp-import-deactivate">%s</button> <span class="seo-wp-import-deactivate-status"></span></p>',
				esc_html(
					sprintf(
						/* translators: %s: plugin name, e.g. "Yoast SEO". */
						__( 'Deactivate %s now', 'seooptimierung-toolkit' ),
						PluginRegistry::label( $source )
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * Handles one AJAX batch step: runs one page of one stage for a
	 * source and reports what to call next.
	 */
	public function handle_ajax(): void {
		Ajax::begin( 'import:batch' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$stage   = isset( $_POST['stage'] ) ? sanitize_key( wp_unslash( $_POST['stage'] ) ) : 'posts';
		$page    = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		$dry_run = ! empty( $_POST['dry_run'] );

		if ( ! isset( self::SOURCES[ $source ] ) ) {
			Ajax::error( array( 'message' => __( 'Unknown source.', 'seooptimierung-toolkit' ) ), 400 );
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
				$next_stage = $result['has_more'] ? 'authors' : 'settings';
				break;
			case 'settings':
				$result     = $batch->run_settings( $source, $dry_run );
				$next_stage = 'done';
				break;
			case 'posts':
			default:
				$result     = $batch->run_posts_page( $source, $page, $dry_run );
				$next_stage = $result['has_more'] ? 'posts' : 'terms';
				break;
		}

		$next_page = $next_stage === $stage ? $page + 1 : 1;

		Ajax::success(
			array(
				'done'      => 'done' === $next_stage,
				'stage'     => $next_stage,
				'page'      => $next_page,
				'processed' => $result['processed'],
				'entries'   => $result['log'],
			)
		);
	}

	/**
	 * Handles the explicit "Deactivate {plugin} now" button click. Never
	 * triggered by anything other than that click — no import step
	 * deactivates a source plugin on its own. Requires both this wizard's
	 * own capability and WordPress core's own activate_plugins capability,
	 * since deactivating another plugin is a more sensitive action than
	 * running this plugin's own import.
	 */
	public function handle_deactivate_ajax(): void {
		Ajax::begin( 'import:deactivate' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) || ! current_user_can( 'activate_plugins' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			Ajax::error( array( 'message' => __( 'Unknown source.', 'seooptimierung-toolkit' ) ), 400 );
		}

		if ( ! PluginRegistry::deactivate( $source ) ) {
			Ajax::error( array( 'message' => __( 'Could not deactivate it automatically — deactivate it from the Plugins screen.', 'seooptimierung-toolkit' ) ), 500 );
		}

		Ajax::success( array( 'message' => __( 'Deactivated.', 'seooptimierung-toolkit' ) ) );
	}
}

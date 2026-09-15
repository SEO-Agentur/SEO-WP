<?php
/**
 * Finds posts sharing an identical effective title or meta description.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Meta\TitleDescription;
use SeoWp\Support\Ajax;
use WP_Post;
use WP_Query;

/**
 * Scans every public post type in AJAX batches, resolving each post's
 * *effective* title/description exactly the way the front end would
 * (manual override, or the rendered template — see
 * TitleDescription::resolve_title_for_post()), and groups posts sharing
 * an identical value. A duplicate title/description across unrelated
 * pages is a common, real SEO problem template-based generation can
 * silently produce, and it isn't visible from the raw per-post fields
 * alone since two different templates can still render to the same text.
 */
final class DuplicateChecker {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-duplicates';

	/**
	 * Option key the last scan's results are stored under.
	 */
	private const OPTION_KEY = 'seo_wp_duplicate_scan';

	/**
	 * Capability required to view or run the scan.
	 */
	private const CAPABILITY = 'seo_wp_view_duplicates';

	/**
	 * Nonce action for the scan AJAX endpoint.
	 */
	private const NONCE_ACTION = 'seo_wp_duplicate_scan';

	/**
	 * AJAX action name.
	 */
	private const AJAX_ACTION = 'seo_wp_duplicate_scan_batch';

	/**
	 * Posts examined per AJAX call.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Transient used to prevent overlapping scan batch steps (two admin
	 * tabs, or a slow request racing a client-side retry) from
	 * interleaving their read-modify-write of OPTION_KEY.
	 */
	private const SCAN_LOCK = 'seo_wp_duplicate_scan_lock';

	/**
	 * Registers the submenu page, its assets and the AJAX endpoint.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
	}

	/**
	 * Adds the Duplicate Content submenu page. Registered with the
	 * virtual seo_wp_view_duplicates capability — see
	 * RoleManager::add_menu()'s docblock for why it has to stay virtual.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Duplicate Content', 'seo-wp' ),
			__( 'Duplicate Content', 'seo-wp' ),
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
		wp_enqueue_script( 'seo-wp-duplicate-checker', plugins_url( 'assets/admin/duplicate-checker.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		wp_localize_script(
			'seo-wp-duplicate-checker',
			'seoWpDuplicates',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => self::AJAX_ACTION,
				'i18n'    => array(
					'scanning' => __( 'Scanning…', 'seo-wp' ),
					'done'     => __( 'Scan complete.', 'seo-wp' ),
					'error'    => __( 'Something went wrong.', 'seo-wp' ),
				),
			)
		);
	}

	/**
	 * Renders the page: a "Scan now" button and, once a scan has
	 * completed, the duplicate groups it found.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$scan = self::scan_data();

		echo '<div class="wrap seo-wp-duplicate-checker">';
		echo '<h1>' . esc_html__( 'SEO-WP Duplicate Content', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Finds posts sharing the exact same effective title or meta description — the value actually used on the front end, whether from a manual override or a rendered template.', 'seo-wp' ) . '</p>';

		echo '<p>';
		echo '<button type="button" class="button button-primary seo-wp-duplicate-scan-start">' . esc_html__( 'Scan now', 'seo-wp' ) . '</button> ';
		echo '<span class="seo-wp-duplicate-scan-status"></span>';
		echo '</p>';

		if ( isset( $scan['scanned_at'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time of the last completed scan. */
						__( 'Last scanned: %s', 'seo-wp' ),
						gmdate( 'Y-m-d H:i:s', (int) $scan['scanned_at'] )
					)
				)
			);

			$this->render_duplicate_group( __( 'Duplicate titles', 'seo-wp' ), (array) ( $scan['titles'] ?? array() ) );
			$this->render_duplicate_group( __( 'Duplicate descriptions', 'seo-wp' ), (array) ( $scan['descriptions'] ?? array() ) );
		}

		echo '</div>';
	}

	/**
	 * Renders one duplicate-group table.
	 *
	 * @param string              $heading Section heading.
	 * @param array<string,int[]> $map     Value => list of post IDs sharing it.
	 */
	private function render_duplicate_group( string $heading, array $map ): void {
		$duplicates = array_filter( $map, static fn( array $ids ): bool => count( $ids ) > 1 );

		echo '<h2>' . esc_html( $heading ) . '</h2>';

		if ( array() === $duplicates ) {
			echo '<p>' . esc_html__( 'No duplicates found.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-wp-duplicate-table">';
		echo '<thead><tr><th>' . esc_html__( 'Value', 'seo-wp' ) . '</th><th>' . esc_html__( 'Posts', 'seo-wp' ) . '</th></tr></thead><tbody>';

		foreach ( $duplicates as $value => $ids ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( (string) $value ) );
			echo '<td>';
			$links = array();
			foreach ( $ids as $id ) {
				$edit_link = get_edit_post_link( (int) $id, 'raw' );
				$links[]   = is_string( $edit_link ) && '' !== $edit_link
					? sprintf( '<a href="%s">#%d</a>', esc_url( $edit_link ), (int) $id )
					: sprintf( '#%d', (int) $id );
			}
			echo implode( ', ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every entry is either esc_url()'d or a plain (int)-cast ID above.
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Handles one AJAX batch step of the scan.
	 */
	public function handle_ajax(): void {
		Ajax::begin( 'duplicate-checker:scan' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		if ( get_transient( self::SCAN_LOCK ) ) {
			Ajax::error( array( 'message' => __( 'A scan is already in progress.', 'seo-wp' ) ), 409 );
		}
		set_transient( self::SCAN_LOCK, 1, 30 );

		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;

		if ( 1 === $page ) {
			delete_option( self::OPTION_KEY );
		}

		$query = new WP_Query(
			array(
				'post_type'              => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				// The loop below resolves each post's effective title/
				// description via get_post_meta() (TitleDescription's
				// override lookups) — priming the meta cache here turns
				// that into one batched query instead of one per post.
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$scan          = self::scan_data();
		$title_service = new TitleDescription();

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$title = $title_service->resolve_title_for_post( $post );
			if ( '' !== $title ) {
				$scan['titles'][ $title ][] = (int) $post_id;
			}

			$description = $title_service->resolve_description_for_post( $post );
			if ( '' !== $description ) {
				$scan['descriptions'][ $description ][] = (int) $post_id;
			}
		}

		$has_more = $page < (int) $query->max_num_pages;
		if ( ! $has_more ) {
			$scan['scanned_at'] = time();
		}

		update_option( self::OPTION_KEY, $scan, false );
		delete_transient( self::SCAN_LOCK );

		Ajax::success(
			array(
				'done'      => ! $has_more,
				'page'      => $page + 1,
				'processed' => count( $query->posts ),
			)
		);
	}

	/**
	 * Reads the stored scan data, defaulting missing pieces to empty.
	 *
	 * @return array{titles: array<string,int[]>, descriptions: array<string,int[]>, scanned_at?: int}
	 */
	private static function scan_data(): array {
		$scan = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $scan ) ) {
			$scan = array();
		}

		$scan += array(
			'titles'       => array(),
			'descriptions' => array(),
		);

		return $scan;
	}
}

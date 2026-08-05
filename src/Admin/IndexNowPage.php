<?php
/**
 * Admin screen for IndexNow (Bing, Yandex, Seznam, Naver) settings, manual
 * URL submission and one-click bulk indexing.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Crawl\IndexNow;
use SeoWp\Support\Ajax;
use WP_Post;
use WP_Term;

/**
 * This is the page WordPress users generally mean by "Instant Indexing":
 * a settings form (enable toggle, optional custom API key) plus two ways
 * to trigger a submission on demand — pasting an arbitrary URL list, or a
 * one-click bulk run over every public post type and taxonomy — and the
 * submission history log. Distinct from GoogleIndexingPage, which covers
 * Google's own (unrelated) Indexing API on its own screen.
 */
final class IndexNowPage {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-instant-indexing';

	/**
	 * Capability required to view or manage this page.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Nonce action/field name for the settings form.
	 */
	private const SETTINGS_NONCE_ACTION = 'seo_wp_indexnow_settings';

	/**
	 * Nonce field name for the settings form.
	 */
	private const SETTINGS_NONCE_NAME = 'seo_wp_indexnow_settings_nonce';

	/**
	 * Nonce action/field name for the manual URL submission form.
	 */
	private const SUBMIT_NONCE_ACTION = 'seo_wp_indexnow_submit';

	/**
	 * Nonce field name for the manual URL submission form.
	 */
	private const SUBMIT_NONCE_NAME = 'seo_wp_indexnow_submit_nonce';

	/**
	 * Nonce action/field name for the clear-log form.
	 */
	private const CLEAR_NONCE_ACTION = 'seo_wp_indexnow_clear';

	/**
	 * Nonce field name for the clear-log form.
	 */
	private const CLEAR_NONCE_NAME = 'seo_wp_indexnow_clear_nonce';

	/**
	 * Nonce action used by the bulk-indexing AJAX batch requests.
	 */
	private const BULK_NONCE_ACTION = 'seo_wp_indexnow_bulk';

	/**
	 * URLs fetched (and submitted) per bulk AJAX batch.
	 */
	private const BULK_BATCH_SIZE = 200;

	/**
	 * Maximum number of URLs accepted from the manual paste box in one
	 * submission, matching IndexNow's own per-request URL limit.
	 */
	private const MAX_MANUAL_URLS = 10000;

	/**
	 * Registers the submenu page, its form handlers and the bulk AJAX
	 * endpoint.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_indexnow_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_seo_wp_indexnow_submit_urls', array( $this, 'handle_submit_urls' ) );
		add_action( 'admin_post_seo_wp_indexnow_clear', array( $this, 'handle_clear' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_seo_wp_indexnow_bulk_batch', array( $this, 'handle_bulk_batch_ajax' ) );
	}

	/**
	 * Adds the Instant Indexing submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Instant Indexing', 'seo-wp' ),
			__( 'Instant Indexing', 'seo-wp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the shared admin stylesheet and the bulk-indexing script on
	 * this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
		wp_enqueue_script( 'seo-wp-indexnow', plugins_url( 'assets/admin/indexnow.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		wp_localize_script(
			'seo-wp-indexnow',
			'seoWpIndexNow',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::BULK_NONCE_ACTION ),
				'action'  => 'seo_wp_indexnow_bulk_batch',
				'i18n'    => array(
					'starting'      => __( 'Starting…', 'seo-wp' ),
					'done'          => __( 'Done.', 'seo-wp' ),
					'error'         => __( 'Something went wrong — stopped early.', 'seo-wp' ),
					'nothingPicked' => __( 'Pick at least one post type or taxonomy first.', 'seo-wp' ),
					/* translators: 1: number of URLs submitted so far, 2: label of the content type currently being processed. */
					'progress'      => __( '%1$d URLs submitted so far — working through %2$s…', 'seo-wp' ),
				),
			)
		);
	}

	/**
	 * Renders the settings form, manual submission form, bulk-indexing
	 * section and the submission history.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		echo '<div class="wrap seo-wp-indexnow">';
		echo '<h1>' . esc_html__( 'SEO-WP Instant Indexing (IndexNow)', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Pings the IndexNow protocol on publish, update and delete, and lets you trigger a submission on demand. Supported by Bing, Yandex, Seznam and Naver. Google does not participate in IndexNow — see the separate Google Indexing API page for that.', 'seo-wp' ) . '</p>';

		$this->render_notices();

		$this->render_settings_form( $settings );
		$this->render_manual_submit_form();
		$this->render_bulk_section();
		$this->render_history();

		echo '</div>';
	}

	/**
	 * Renders any success/error banners from a just-completed form
	 * submission, based on the admin-post redirect's query args.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banners reflecting a prior action's redirect, not a state-changing action themselves.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'seo-wp' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		if ( isset( $_GET['invalid_key'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'That API key was not saved — it must be 16-64 hexadecimal characters (0-9, a-f) so it matches the /{key}.txt verification URL. The previous key was kept.', 'seo-wp' ) . '</p></div>';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		if ( isset( $_GET['submitted'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
			$submitted = (int) $_GET['submitted'];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
			$ok = isset( $_GET['ok'] ) && '1' === $_GET['ok'];

			if ( $ok ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					/* translators: %d: number of URLs submitted. */
					esc_html( sprintf( _n( '%d URL submitted to IndexNow.', '%d URLs submitted to IndexNow.', $submitted, 'seo-wp' ), $submitted ) )
				);
			} else {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'IndexNow submission failed — see the history table below for the error, or the Diagnostics page.', 'seo-wp' ) . '</p></div>';
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		if ( isset( $_GET['no_urls'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'No valid URLs found in the pasted list.', 'seo-wp' ) . '</p></div>';
		}
	}

	/**
	 * Renders the enable toggle, custom key field and key-file status.
	 *
	 * @param array<string,mixed> $settings Current plugin settings.
	 */
	private function render_settings_form( array $settings ): void {
		$enabled    = ! isset( $settings['enable_indexnow'] ) || ! empty( $settings['enable_indexnow'] );
		$custom_key = isset( $settings['indexnow_key'] ) ? (string) $settings['indexnow_key'] : '';
		$indexnow   = new IndexNow();
		$active_key = $indexnow->key();
		$key_file   = home_url( '/' . $active_key . '.txt' );

		echo '<h2>' . esc_html__( 'Settings', 'seo-wp' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_indexnow_save_settings" />';
		wp_nonce_field( self::SETTINGS_NONCE_ACTION, self::SETTINGS_NONCE_NAME );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="enable_indexnow" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Enable', 'seo-wp' ),
			checked( $enabled, true, false ),
			esc_html__( 'Automatically ping IndexNow on publish, update and delete', 'seo-wp' )
		);

		printf(
			'<tr><th scope="row"><label for="seo_wp_indexnow_key">%1$s</label></th><td><input type="text" id="seo_wp_indexnow_key" name="indexnow_key" value="%2$s" class="regular-text code" placeholder="%3$s" /><p class="description">%4$s</p></td></tr>',
			esc_html__( 'API key (optional)', 'seo-wp' ),
			esc_attr( $custom_key ),
			esc_attr( $active_key ),
			esc_html__( 'Leave blank to use an automatically generated key, or paste your own 16-64 character hexadecimal key (e.g. one already registered with Bing Webmaster Tools).', 'seo-wp' )
		);

		echo '<tr><th scope="row">' . esc_html__( 'Current key file', 'seo-wp' ) . '</th><td>';
		printf(
			'<code>%1$s</code> — <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( $key_file ),
			esc_html__( 'open', 'seo-wp' )
		);
		echo '<p class="description">' . esc_html__( 'This file must return your key as plain text for IndexNow to accept submissions — it is served automatically by this plugin.', 'seo-wp' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'seo-wp' ) );
		echo '</form>';
	}

	/**
	 * Renders the manual "paste a URL list" submission form.
	 */
	private function render_manual_submit_form(): void {
		echo '<h2>' . esc_html__( 'Submit specific URLs', 'seo-wp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'One URL per line. Works even if automatic pinging above is disabled.', 'seo-wp' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_indexnow_submit_urls" />';
		wp_nonce_field( self::SUBMIT_NONCE_ACTION, self::SUBMIT_NONCE_NAME );

		printf(
			'<textarea name="urls" rows="8" class="large-text code" placeholder="%1$s"></textarea>',
			esc_attr__( "https://example.com/page-one/\nhttps://example.com/page-two/", 'seo-wp' )
		);

		submit_button( __( 'Submit URLs to IndexNow', 'seo-wp' ) );
		echo '</form>';
	}

	/**
	 * Renders the one-click bulk indexing section: a checkbox per public
	 * post type and taxonomy (all checked by default, so the default
	 * click really does submit everything), and a progress area the
	 * indexnow.js script fills in via AJAX.
	 */
	private function render_bulk_section(): void {
		echo '<h2>' . esc_html__( 'Index everything (one click)', 'seo-wp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Submits every published URL of the checked types, in small batches so the browser tab stays responsive. Untick anything you don\'t want included.', 'seo-wp' ) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Post types', 'seo-wp' ) . '</th><td>';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $post_type ) {
			printf(
				'<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" class="seo-wp-indexnow-bulk-post-type" value="%1$s" checked="checked" /> %2$s</label>',
				esc_attr( $post_type->name ),
				esc_html( $post_type->labels->name )
			);
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Taxonomies', 'seo-wp' ) . '</th><td>';
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			printf(
				'<label style="display:inline-block;margin:0 16px 8px 0;"><input type="checkbox" class="seo-wp-indexnow-bulk-taxonomy" value="%1$s" checked="checked" /> %2$s</label>',
				esc_attr( $taxonomy->name ),
				esc_html( $taxonomy->labels->name )
			);
		}
		echo '</td></tr>';

		echo '</tbody></table>';

		echo '<p><button type="button" class="button button-primary seo-wp-indexnow-bulk-start">' . esc_html__( 'Start bulk indexing', 'seo-wp' ) . '</button></p>';

		echo '<div class="seo-wp-indexnow-progress" hidden>';
		echo '<p class="seo-wp-indexnow-status"></p>';
		echo '<div class="seo-wp-indexnow-log"></div>';
		echo '</div>';
	}

	/**
	 * Renders the submission history table and its clear-log form.
	 */
	private function render_history(): void {
		$entries = array_reverse( IndexNow::log_entries() );

		echo '<h2>' . esc_html__( 'Submission history', 'seo-wp' ) . '</h2>';

		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No submissions yet.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_indexnow_clear" />';
		wp_nonce_field( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );
		submit_button( __( 'Clear history', 'seo-wp' ), 'delete', 'submit', false );
		echo '</form>';

		echo '<table class="widefat striped seo-wp-indexnow-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Source', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'URLs', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Submitted', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$ok     = ! empty( $entry['ok'] );
			$detail = (string) ( $entry['detail'] ?? '' );
			$status = $ok ? __( 'OK', 'seo-wp' ) : __( 'Error', 'seo-wp' );

			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td><span style="color:%3$s;">%4$s</span>%5$s</td><td>%6$s</td></tr>',
				esc_html( (string) ( $entry['source'] ?? '' ) ),
				(int) ( $entry['count'] ?? 0 ),
				$ok ? '#008a20' : '#d63638',
				esc_html( $status ),
				'' !== $detail ? ' <span class="description">— ' . esc_html( $detail ) . '</span>' : '',
				esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['time'] ?? 0 ) ) )
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Handles the settings form submission.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::SETTINGS_NONCE_ACTION, self::SETTINGS_NONCE_NAME );

		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings['enable_indexnow'] = ! empty( $_POST['enable_indexnow'] );

		$raw_key       = isset( $_POST['indexnow_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['indexnow_key'] ) ) : '';
		$redirect_args = array(
			'page'    => self::PAGE_SLUG,
			'updated' => 1,
		);

		if ( '' === $raw_key ) {
			$settings['indexnow_key'] = '';
		} else {
			$sanitized = IndexNow::sanitize_key( $raw_key );
			if ( '' === $sanitized ) {
				unset( $redirect_args['updated'] );
				$redirect_args['invalid_key'] = 1;
			} else {
				$settings['indexnow_key'] = $sanitized;
			}
		}

		update_option( self::OPTION_KEY, $settings, 'yes' );

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles the manual paste-box submission: validates each line as an
	 * absolute URL, discards the rest, and submits the survivors.
	 */
	public function handle_submit_urls(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::SUBMIT_NONCE_ACTION, self::SUBMIT_NONCE_NAME );

		$raw   = isset( $_POST['urls'] ) ? (string) wp_unslash( $_POST['urls'] ) : '';
		$lines = preg_split( '/[\r\n]+/', $raw );
		$lines = is_array( $lines ) ? $lines : array();

		$urls = array();
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && false !== wp_http_validate_url( $line ) ) {
				$urls[] = $line;
			}
		}
		$urls = array_slice( array_unique( $urls ), 0, self::MAX_MANUAL_URLS );

		if ( array() === $urls ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'no_urls' => 1,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$result = ( new IndexNow() )->submit_urls( $urls, __( 'Manual paste', 'seo-wp' ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'submitted' => $result['submitted'],
					'ok'        => $result['ok'] ? 1 : 0,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the clear-history form submission.
	 */
	public function handle_clear(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::CLEAR_NONCE_ACTION, self::CLEAR_NONCE_NAME );

		IndexNow::clear_log();

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles one batch of the one-click bulk run: fetches up to
	 * BULK_BATCH_SIZE permalinks for the given post type or taxonomy
	 * starting at $offset, submits them immediately, and reports back
	 * whether more remain — indexnow.js drives the loop client-side so a
	 * large site never risks a single PHP request timing out.
	 */
	public function handle_bulk_batch_ajax(): void {
		Ajax::begin( 'indexnow:bulk' );
		check_ajax_referer( self::BULK_NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( (string) $_POST['kind'] ) ) : '';
		$name   = isset( $_POST['name'] ) ? sanitize_key( wp_unslash( (string) $_POST['name'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;

		if ( 'post_type' === $kind ) {
			$this->handle_post_type_batch( $name, $offset );
		} elseif ( 'taxonomy' === $kind ) {
			$this->handle_taxonomy_batch( $name, $offset );
		} else {
			Ajax::error( array( 'message' => __( 'Unknown batch kind.', 'seo-wp' ) ), 400 );
		}
	}

	/**
	 * Fetches, submits and reports one batch of a post type's published
	 * URLs. Terminates the request via Ajax::success()/error().
	 *
	 * @param string $post_type Post type name.
	 * @param int    $offset    Offset into the post type's published posts.
	 */
	private function handle_post_type_batch( string $post_type, int $offset ): void {
		if ( ! in_array( $post_type, get_post_types( array( 'public' => true ), 'names' ), true ) ) {
			Ajax::error( array( 'message' => __( 'Unknown post type.', 'seo-wp' ) ), 400 );
		}

		$query = new \WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => self::BULK_BATCH_SIZE,
				'offset'         => $offset,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => false,
			)
		);

		$urls = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post && IndexNow::is_indexable( $post ) ) {
				$permalink = get_permalink( $post );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					$urls[] = $permalink;
				}
			}
		}

		$total = (int) $query->found_posts;

		$post_type_object = get_post_type_object( $post_type );
		$label            = $post_type_object instanceof \WP_Post_Type ? $post_type_object->labels->name : $post_type;

		/* translators: %s: post type label, e.g. "Products". */
		$this->submit_and_report( $urls, $total, $offset, sprintf( __( 'Bulk: %s', 'seo-wp' ), $label ) );
	}

	/**
	 * Fetches, submits and reports one batch of a taxonomy's term links.
	 * Terminates the request via Ajax::success()/error().
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param int    $offset   Offset into the taxonomy's terms.
	 */
	private function handle_taxonomy_batch( string $taxonomy, int $offset ): void {
		if ( ! in_array( $taxonomy, get_taxonomies( array( 'public' => true ), 'names' ), true ) ) {
			Ajax::error( array( 'message' => __( 'Unknown taxonomy.', 'seo-wp' ) ), 400 );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::BULK_BATCH_SIZE,
				'offset'     => $offset,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		$terms = is_array( $terms ) ? $terms : array();

		$urls = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			if ( is_string( $link ) ) {
				$urls[] = $link;
			}
		}

		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$taxonomy_object = get_taxonomy( $taxonomy );
		$label           = false !== $taxonomy_object ? $taxonomy_object->labels->name : $taxonomy;

		/* translators: %s: taxonomy label, e.g. "Product categories". */
		$this->submit_and_report( $urls, $total, $offset, sprintf( __( 'Bulk: %s', 'seo-wp' ), $label ) );
	}

	/**
	 * Submits one batch's URLs (if any) and sends the AJAX response the
	 * client needs to continue or stop the loop for this queue item.
	 *
	 * @param string[] $urls   URLs collected for this batch (may be empty if all were filtered out).
	 * @param int      $total  Total item count for this post type/taxonomy, for progress reporting.
	 * @param int      $offset This batch's offset.
	 * @param string   $source Log source label.
	 */
	private function submit_and_report( array $urls, int $total, int $offset, string $source ): void {
		$result = array(
			'submitted' => 0,
			'ok'        => true,
			'error'     => '',
		);

		if ( array() !== $urls ) {
			$result = ( new IndexNow() )->submit_urls( $urls, $source );
		}

		Ajax::success(
			array(
				'submitted'   => $result['submitted'],
				'ok'          => $result['ok'],
				'error'       => $result['error'],
				'total'       => $total,
				'next_offset' => $offset + self::BULK_BATCH_SIZE,
				'has_more'    => ( $offset + self::BULK_BATCH_SIZE ) < $total,
			)
		);
	}
}

<?php
/**
 * Broken-link checker and orphan-page finder.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Support\Ajax;
use SeoWp\Support\ContentRenderer;
use WP_Post;
use WP_Query;

/**
 * Two related tools sharing one content scan: a "Scan content" pass walks
 * every published post's fully rendered content (and every nav menu),
 * building the set of post IDs something on the site actually links to —
 * anything public and published but NOT in that set is an orphan page.
 * Links that don't resolve to a known post (broken internal links, or any
 * external one) are queued and checked in the background via WP-Cron
 * (wp_remote_head/get, a handful per run) rather than synchronously,
 * since checking hundreds or thousands of URLs in one admin-ajax request
 * would time out. Results fill in gradually, not instantly.
 */
final class LinkAudit {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-link-audit';

	/**
	 * Option key the completed scan's linked-post-ID set is stored under.
	 */
	private const SCAN_OPTION = 'seo_wp_link_audit_scan';

	/**
	 * Option key the not-yet-checked link queue is stored under.
	 */
	private const QUEUE_OPTION = 'seo_wp_link_queue';

	/**
	 * Option key confirmed broken links are stored under.
	 */
	private const RESULTS_OPTION = 'seo_wp_broken_links';

	/**
	 * Capability required to view or run the audit.
	 */
	private const CAPABILITY = 'seo_wp_view_link_audit';

	/**
	 * Nonce action shared by both AJAX endpoints.
	 */
	private const NONCE_ACTION = 'seo_wp_link_audit';

	/**
	 * AJAX action for one content-scan batch step.
	 */
	private const SCAN_AJAX_ACTION = 'seo_wp_link_audit_scan';

	/**
	 * AJAX action for an on-demand, immediate queue check.
	 */
	private const CHECK_NOW_AJAX_ACTION = 'seo_wp_link_audit_check_now';

	/**
	 * The recurring background check's cron hook name.
	 */
	private const CRON_HOOK = 'seo_wp_check_links_batch';

	/**
	 * Option key holding the plugin's settings array (the configured
	 * check frequency lives under FREQUENCY_SETTING_KEY in here).
	 */
	private const SETTINGS_OPTION = 'seo_wp_settings';

	/**
	 * Settings key for the configured background-check frequency.
	 */
	private const FREQUENCY_SETTING_KEY = 'link_audit_frequency';

	/**
	 * Nonce action for the frequency-save form.
	 */
	private const FREQUENCY_NONCE_ACTION = 'seo_wp_link_audit_frequency';

	/**
	 * Nonce field name for the frequency-save form.
	 */
	private const FREQUENCY_NONCE_NAME = 'seo_wp_link_audit_frequency_nonce';

	/**
	 * Valid frequency values and the cron recurrence (a WP core schedule,
	 * or one of the two custom ones add_cron_schedule() registers) each
	 * maps to. 'off' means checked on demand only ("Check queued links
	 * now"), never on a schedule — the default, since a background check
	 * is a convenience an admin should opt into, not something running
	 * unasked.
	 *
	 * @var array<string,string>
	 */
	private const FREQUENCIES = array(
		'off'     => '',
		'daily'   => 'daily',
		'weekly'  => 'seo_wp_weekly',
		'monthly' => 'seo_wp_monthly',
	);

	/**
	 * Posts examined per content-scan AJAX call.
	 */
	private const SCAN_BATCH_SIZE = 50;

	/**
	 * Queued URLs checked per background run (cron tick or manual
	 * "Check now" click).
	 */
	private const CHECK_BATCH_SIZE = 15;

	/**
	 * Oldest broken-link results are dropped once the log exceeds this
	 * size.
	 */
	private const MAX_RESULTS = 500;

	/**
	 * Registers the cron schedules/hook (unconditionally — WP-Cron
	 * requests aren't wp-admin requests) and reconciles the actual
	 * scheduled event with the configured frequency, and the admin
	 * page/AJAX/save endpoints.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval is admin-configurable (daily/weekly/monthly), not a fixed value this sniff can evaluate.
		add_action( self::CRON_HOOK, array( $this, 'check_links_batch' ) );
		$this->sync_cron_schedule();

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::SCAN_AJAX_ACTION, array( $this, 'handle_scan_ajax' ) );
		add_action( 'wp_ajax_' . self::CHECK_NOW_AJAX_ACTION, array( $this, 'handle_check_now_ajax' ) );
		add_action( 'admin_post_seo_wp_link_audit_frequency_save', array( $this, 'handle_frequency_save' ) );
	}

	/**
	 * Registers the two custom recurrences WP core doesn't have
	 * (weekly/monthly) — 'daily' already exists in core.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Known schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function add_cron_schedules( array $schedules ): array {
		$schedules['seo_wp_weekly']  = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Weekly (SEO-WP link checks)', 'seo-wp' ),
		);
		$schedules['seo_wp_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly (SEO-WP link checks)', 'seo-wp' ),
		);

		return $schedules;
	}

	/**
	 * Reads the configured frequency and makes the real WP-Cron schedule
	 * match it: cleared entirely when set to "off" (checks then only run
	 * via the "Check queued links now" button), (re)scheduled at the
	 * right recurrence otherwise — including when the admin changes from
	 * one recurrence to another, which needs the old event cleared first
	 * since wp_schedule_event() would otherwise leave the original
	 * recurrence in place alongside a redundant duplicate.
	 */
	private function sync_cron_schedule(): void {
		$recurrence = self::FREQUENCIES[ $this->configured_frequency() ] ?? '';
		$scheduled  = wp_get_schedule( self::CRON_HOOK );

		if ( '' === $recurrence ) {
			if ( false !== $scheduled ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		if ( $scheduled !== $recurrence ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( time(), $recurrence, self::CRON_HOOK );
		}
	}

	/**
	 * The configured background-check frequency, defaulting to "off".
	 */
	private function configured_frequency(): string {
		$settings  = get_option( self::SETTINGS_OPTION, array() );
		$settings  = is_array( $settings ) ? $settings : array();
		$frequency = isset( $settings[ self::FREQUENCY_SETTING_KEY ] ) ? (string) $settings[ self::FREQUENCY_SETTING_KEY ] : 'off';

		return isset( self::FREQUENCIES[ $frequency ] ) ? $frequency : 'off';
	}

	/**
	 * Handles the frequency-select form submission.
	 */
	public function handle_frequency_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::FREQUENCY_NONCE_ACTION, self::FREQUENCY_NONCE_NAME );

		$submitted = isset( $_POST['frequency'] ) ? sanitize_key( wp_unslash( $_POST['frequency'] ) ) : 'off';
		$frequency = isset( self::FREQUENCIES[ $submitted ] ) ? $submitted : 'off';

		$settings                                = get_option( self::SETTINGS_OPTION, array() );
		$settings                                = is_array( $settings ) ? $settings : array();
		$settings[ self::FREQUENCY_SETTING_KEY ] = $frequency;
		update_option( self::SETTINGS_OPTION, $settings, 'yes' );

		$this->sync_cron_schedule();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Adds the Link Audit submenu page. Registered with the virtual
	 * seo_wp_view_link_audit capability — see RoleManager::add_menu()'s
	 * docblock for why it has to stay virtual (a role managed entirely
	 * through this plugin's own system, with no real manage_options, must
	 * still reach every page it's trusted with). RoleManager's own
	 * grant_capabilities() is what actually guarantees this is never
	 * lockable for such a role, via its "already trusted with Settings"
	 * rule.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Link Audit', 'seo-wp' ),
			__( 'Link Audit', 'seo-wp' ),
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
		wp_enqueue_script( 'seo-wp-link-audit', plugins_url( 'assets/admin/link-audit.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		wp_localize_script(
			'seo-wp-link-audit',
			'seoWpLinkAudit',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
				'scanAction'     => self::SCAN_AJAX_ACTION,
				'checkNowAction' => self::CHECK_NOW_AJAX_ACTION,
				'i18n'           => array(
					'scanning' => __( 'Scanning…', 'seo-wp' ),
					'checking' => __( 'Checking…', 'seo-wp' ),
					'done'     => __( 'Done.', 'seo-wp' ),
					'error'    => __( 'Something went wrong.', 'seo-wp' ),
				),
			)
		);
	}

	/**
	 * Renders the page: scan/check controls, then orphan pages and broken
	 * links once a scan has completed.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$scan   = self::scan_data();
		$queue  = self::queue();
		$broken = self::broken_links();

		echo '<div class="wrap seo-wp-link-audit">';
		echo '<h1>' . esc_html__( 'SEO-WP Link Audit', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Scans every published page\'s content and every navigation menu to find pages nothing links to (orphans), and queues every other link for a broken-link check — checked on demand with the button below, or automatically on whatever schedule you choose.', 'seo-wp' ) . '</p>';

		echo '<p>';
		echo '<button type="button" class="button button-primary seo-wp-link-audit-scan">' . esc_html__( 'Scan content now', 'seo-wp' ) . '</button> ';
		echo '<button type="button" class="button seo-wp-link-audit-check-now">' . esc_html__( 'Check queued links now', 'seo-wp' ) . '</button> ';
		echo '<span class="seo-wp-link-audit-status"></span>';
		echo '</p>';

		$this->render_frequency_form();

		if ( array() !== $queue ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of links still waiting to be checked. */
						_n( '%d link is still queued for a background check.', '%d links are still queued for a background check.', count( $queue ), 'seo-wp' ),
						count( $queue )
					)
				)
			);
		}

		if ( isset( $scan['scanned_at'] ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: date and time of the last completed content scan. */
						__( 'Last content scan: %s', 'seo-wp' ),
						gmdate( 'Y-m-d H:i:s', (int) $scan['scanned_at'] )
					)
				)
			);

			$this->render_orphans( $scan );
		}

		$this->render_broken_links( $broken );

		echo '</div>';
	}

	/**
	 * Renders the background-check frequency form.
	 */
	private function render_frequency_form(): void {
		$current = $this->configured_frequency();
		$labels  = array(
			'off'     => __( 'Manual only (recommended) — no automatic schedule', 'seo-wp' ),
			'daily'   => __( 'Daily', 'seo-wp' ),
			'weekly'  => __( 'Weekly', 'seo-wp' ),
			'monthly' => __( 'Monthly', 'seo-wp' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="seo-wp-link-audit-frequency-form">';
		echo '<input type="hidden" name="action" value="seo_wp_link_audit_frequency_save" />';
		wp_nonce_field( self::FREQUENCY_NONCE_ACTION, self::FREQUENCY_NONCE_NAME );

		echo '<p><label for="seo_wp_link_audit_frequency">' . esc_html__( 'Automatic background check', 'seo-wp' ) . '</label> ';
		echo '<select id="seo_wp_link_audit_frequency" name="frequency">';
		foreach ( $labels as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Save', 'seo-wp' ), 'secondary', 'submit', false );
		echo '</p>';
		echo '</form>';
	}

	/**
	 * Renders the orphan-pages table.
	 *
	 * @param array{linked_post_ids: array<int,bool>, scanned_at?: int} $scan Completed scan data.
	 */
	private function render_orphans( array $scan ): void {
		$orphans = $this->orphan_post_ids( $scan );

		echo '<h2>' . esc_html__( 'Orphan pages', 'seo-wp' ) . '</h2>';

		if ( array() === $orphans ) {
			echo '<p>' . esc_html__( 'No orphan pages found.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-wp-orphan-table">';
		echo '<thead><tr><th>' . esc_html__( 'Page', 'seo-wp' ) . '</th></tr></thead><tbody>';

		foreach ( $orphans as $id ) {
			$edit_link = get_edit_post_link( $id, 'raw' );
			printf(
				'<tr><td><a href="%1$s">%2$s</a> <span class="description">#%3$d</span></td></tr>',
				esc_url( is_string( $edit_link ) ? $edit_link : '' ),
				esc_html( get_the_title( $id ) ),
				(int) $id
			);
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the broken-links table.
	 *
	 * @param array<string,array{sources:int[],status:string,checked_at:int}> $broken Broken-link results.
	 */
	private function render_broken_links( array $broken ): void {
		echo '<h2>' . esc_html__( 'Broken links', 'seo-wp' ) . '</h2>';

		if ( array() === $broken ) {
			echo '<p>' . esc_html__( 'No broken links found yet.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-wp-broken-links-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Linked from', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Checked', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $broken as $url => $entry ) {
			$sources = array();
			foreach ( (array) ( $entry['sources'] ?? array() ) as $source_id ) {
				$sources[] = sprintf( '<a href="%1$s">#%2$d</a>', esc_url( (string) get_edit_post_link( (int) $source_id, 'raw' ) ), (int) $source_id );
			}

			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( (string) $url ) );
			printf( '<td>%s</td>', esc_html( (string) ( $entry['status'] ?? '' ) ) );
			echo '<td>' . implode( ', ', $sources ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every entry built from esc_url()/(int) above.
			printf( '<td>%s</td>', esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['checked_at'] ?? 0 ) ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Every published, public post type ID nothing links to, excluding
	 * the static front page (which is always reachable regardless of
	 * in-content links).
	 *
	 * @param array{linked_post_ids: array<int,bool>} $scan Completed scan data.
	 * @return int[]
	 */
	private function orphan_post_ids( array $scan ): array {
		$linked = array_map( 'intval', array_keys( (array) ( $scan['linked_post_ids'] ?? array() ) ) );

		$front_page_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		$all_ids = get_posts(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$orphans = array();
		foreach ( $all_ids as $id ) {
			$id = (int) $id;
			if ( $id === $front_page_id || in_array( $id, $linked, true ) ) {
				continue;
			}
			$orphans[] = $id;
		}

		return $orphans;
	}

	/**
	 * Handles one content-scan AJAX batch step.
	 */
	public function handle_scan_ajax(): void {
		Ajax::begin( 'link-audit:scan' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;

		if ( 1 === $page ) {
			delete_option( self::SCAN_OPTION );
			delete_option( self::QUEUE_OPTION );
		}

		$query = new WP_Query(
			array(
				'post_type'              => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::SCAN_BATCH_SIZE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$scan  = self::scan_data();
		$queue = self::queue();
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			foreach ( self::extract_links( ContentRenderer::render( $post ) ) as $link ) {
				$this->process_link( $link, (int) $post_id, $host, $scan, $queue );
			}
		}

		$has_more = $page < (int) $query->max_num_pages;

		if ( ! $has_more ) {
			foreach ( self::nav_menu_linked_post_ids() as $id ) {
				$scan['linked_post_ids'][ $id ] = true;
			}
			$scan['scanned_at'] = time();
		}

		update_option( self::SCAN_OPTION, $scan, false );
		update_option( self::QUEUE_OPTION, $queue, false );

		Ajax::success(
			array(
				'done'      => ! $has_more,
				'page'      => $page + 1,
				'processed' => count( $query->posts ),
			)
		);
	}

	/**
	 * Classifies one extracted link: a resolvable internal link marks its
	 * target post as linked-to; anything else (external, or an internal
	 * link that doesn't resolve to a real post) is queued for a broken-
	 * link check.
	 *
	 * @param string                                  $link    Raw href value.
	 * @param int                                     $post_id Post the link was found in.
	 * @param string                                  $host    This site's own host, for internal/external classification.
	 * @param array{linked_post_ids: array<int,bool>} $scan  Scan data, modified by reference.
	 * @param array<string,array{sources:int[]}>      $queue Link queue, modified by reference.
	 */
	private function process_link( string $link, int $post_id, string $host, array &$scan, array &$queue ): void {
		$target_host = (string) wp_parse_url( $link, PHP_URL_HOST );
		$is_internal = '' === $target_host || 0 === strcasecmp( $target_host, $host );
		$absolute    = '' === $target_host ? home_url( $link ) : $link;

		if ( $is_internal ) {
			$resolved_id = url_to_postid( $absolute );
			if ( $resolved_id > 0 ) {
				$scan['linked_post_ids'][ $resolved_id ] = true;
				return;
			}
		}

		if ( ! isset( $queue[ $absolute ] ) ) {
			$queue[ $absolute ] = array( 'sources' => array() );
		}
		if ( ! in_array( $post_id, $queue[ $absolute ]['sources'], true ) ) {
			$queue[ $absolute ]['sources'][] = $post_id;
		}
	}

	/**
	 * Post IDs linked to from any registered navigation menu, so pages
	 * only reachable via the menu (not from within any post's content)
	 * aren't false-flagged as orphans. Counts every registered menu
	 * regardless of whether it's assigned to a theme location — a safe
	 * simplification that can only under-report orphans, never wrongly
	 * flag a genuinely reachable page.
	 *
	 * @return int[]
	 */
	private static function nav_menu_linked_post_ids(): array {
		$ids   = array();
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) ) {
			return $ids;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === ( $item->object ?? '' ) && (int) $item->object_id > 0 ) {
					$ids[] = (int) $item->object_id;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Handles an on-demand "check queued links now" click: runs exactly
	 * one background-check batch immediately instead of waiting for cron.
	 */
	public function handle_check_now_ajax(): void {
		Ajax::begin( 'link-audit:check-now' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$this->check_links_batch();

		Ajax::success( array( 'remaining' => count( self::queue() ) ) );
	}

	/**
	 * Checks a batch of queued URLs and records any that are broken.
	 * Runs on the fifteen-minute cron schedule, or on demand via
	 * handle_check_now_ajax().
	 */
	public function check_links_batch(): void {
		$queue = self::queue();
		if ( array() === $queue ) {
			return;
		}

		$batch  = array_slice( $queue, 0, self::CHECK_BATCH_SIZE, true );
		$broken = self::broken_links();

		foreach ( $batch as $url => $data ) {
			unset( $queue[ $url ] );

			$status = self::check_url( (string) $url );

			if ( null !== $status ) {
				$broken[ $url ] = array(
					'sources'    => (array) ( $data['sources'] ?? array() ),
					'status'     => $status,
					'checked_at' => time(),
				);
			} else {
				unset( $broken[ $url ] );
			}
		}

		if ( count( $broken ) > self::MAX_RESULTS ) {
			uasort( $broken, static fn ( array $a, array $b ): int => ( $a['checked_at'] ?? 0 ) <=> ( $b['checked_at'] ?? 0 ) );
			$broken = array_slice( $broken, -self::MAX_RESULTS, null, true );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
		update_option( self::RESULTS_OPTION, $broken, false );
	}

	/**
	 * Checks a single URL, returning a human-readable status/error when
	 * it's broken, or null when it's fine. Tries HEAD first (cheaper),
	 * falling back to GET since some servers reject HEAD requests outright.
	 *
	 * @param string $url URL to check.
	 */
	private static function check_url( string $url ): ?string {
		$args = array(
			'timeout'     => 5,
			'redirection' => 5,
		);

		$response = wp_remote_head( $url, $args );
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( is_wp_error( $response ) || $code >= 400 || 0 === $code ) {
			$response = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		return $code >= 400 ? (string) $code : null;
	}

	/**
	 * Extracts checkable link targets from a block of HTML: every
	 * distinct href, minus empty values, same-page anchors, and non-http
	 * schemes (mailto:, tel:, javascript:, etc.). Pure string logic,
	 * independent of WordPress, for easy unit testing.
	 *
	 * @param string $html HTML content to scan.
	 * @return string[]
	 */
	public static function extract_links( string $html ): array {
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return array();
		}

		$links = array();

		foreach ( array_unique( $matches[1] ) as $href ) {
			$href = trim( $href );

			if ( '' === $href || '#' === $href[0] ) {
				continue;
			}

			$scheme = strtolower( (string) parse_url( $href, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.
			if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}

			$links[] = $href;
		}

		return array_values( $links );
	}

	/**
	 * Reads the stored scan data, defaulting missing pieces to empty.
	 *
	 * @return array{linked_post_ids: array<int,bool>, scanned_at?: int}
	 */
	private static function scan_data(): array {
		$scan = get_option( self::SCAN_OPTION, array() );
		if ( ! is_array( $scan ) ) {
			$scan = array();
		}

		$scan += array( 'linked_post_ids' => array() );

		return $scan;
	}

	/**
	 * The current not-yet-checked link queue.
	 *
	 * @return array<string,array{sources:int[]}>
	 */
	private static function queue(): array {
		$queue = get_option( self::QUEUE_OPTION, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * The current confirmed-broken-link results.
	 *
	 * @return array<string,array{sources:int[],status:string,checked_at:int}>
	 */
	private static function broken_links(): array {
		$broken = get_option( self::RESULTS_OPTION, array() );

		return is_array( $broken ) ? $broken : array();
	}
}

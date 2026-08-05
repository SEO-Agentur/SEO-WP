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

	/** AJAX action that starts a persistent background audit. */
	private const START_AJAX_ACTION = 'seo_wp_link_audit_start';

	/** AJAX action used by the admin page to poll audit progress. */
	private const STATUS_AJAX_ACTION = 'seo_wp_link_audit_status';

	/** Internal loopback action that advances the persistent job. */
	private const WORKER_AJAX_ACTION = 'seo_wp_link_audit_worker';

	/** Option containing the persistent background-job state. */
	private const JOB_OPTION = 'seo_wp_link_audit_job';

	/** Transient used to prevent overlapping workers. */
	private const WORKER_LOCK = 'seo_wp_link_audit_worker_lock';

	/** WP-Cron fallback hook when loopback requests are unavailable. */
	private const JOB_CRON_HOOK = 'seo_wp_link_audit_job_tick';

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
	private const SCAN_BATCH_SIZE = 25;

	/**
	 * Queued URLs checked per background run (cron tick or manual
	 * "Check now" click).
	 */
	private const CHECK_BATCH_SIZE = 20;

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
		add_action( self::JOB_CRON_HOOK, array( $this, 'handle_cron_worker' ), 10, 1 );
		$this->sync_cron_schedule();

		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::SCAN_AJAX_ACTION, array( $this, 'handle_scan_ajax' ) );
		add_action( 'wp_ajax_' . self::CHECK_NOW_AJAX_ACTION, array( $this, 'handle_check_now_ajax' ) );
		add_action( 'wp_ajax_' . self::START_AJAX_ACTION, array( $this, 'handle_start_ajax' ) );
		add_action( 'wp_ajax_' . self::STATUS_AJAX_ACTION, array( $this, 'handle_status_ajax' ) );
		add_action( 'wp_ajax_' . self::WORKER_AJAX_ACTION, array( $this, 'handle_worker_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::WORKER_AJAX_ACTION, array( $this, 'handle_worker_ajax' ) );
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
	 * as part of a manual full scan), (re)scheduled at the
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
		// Register the page only for authorised users, but use the stable
		// primitive `read` capability for WordPress' page registry. Custom
		// virtual capabilities can otherwise make admin.php report a 404
		// because the page hook was never registered.
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Link Audit', 'seo-wp' ),
			__( 'Link Audit', 'seo-wp' ),
			'read',
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
				'startAction'    => self::START_AJAX_ACTION,
				'statusAction'   => self::STATUS_AJAX_ACTION,
				'i18n'           => array(
					'scanning'          => __( 'Scanning', 'seo-wp' ),
					'checking'          => __( 'Checking link', 'seo-wp' ),
					'preparing'         => __( 'Preparing scan…', 'seo-wp' ),
					'elapsed'           => __( 'elapsed', 'seo-wp' ),
					'done'              => __( 'Done.', 'seo-wp' ),
					'runningBackground' => __( 'The audit continues in the background. You may leave this page.', 'seo-wp' ),
					'error'             => __( 'Something went wrong.', 'seo-wp' ),
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
		$job    = self::job_data();

		echo '<div class="wrap seo-wp-link-audit">';
		echo '<h1>' . esc_html__( 'SEO-WP Link Audit', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Scans every published page\'s content and every navigation menu to find pages nothing links to (orphans), and queues every other link for a broken-link check — checked on demand with the button below, or automatically on whatever schedule you choose.', 'seo-wp' ) . '</p>';

		echo '<p>';
		echo '<button type="button" class="button button-primary seo-wp-link-audit-scan">' . esc_html__( 'Run full link audit', 'seo-wp' ) . '</button> ';
		echo '<span class="seo-wp-link-audit-status" data-job-status="' . esc_attr( (string) ( $job['status'] ?? 'idle' ) ) . '"></span>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'The audit is stored as a persistent background job. It continues after this tab is closed and resumes automatically after short interruptions.', 'seo-wp' ) . '</p>';

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
			'off'     => __( 'Only when a full audit is started manually', 'seo-wp' ),
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
		echo '<h2>' . esc_html__( 'Link findings', 'seo-wp' ) . '</h2>';
		if ( array() === $broken ) {
			echo '<p>' . esc_html__( 'No broken links or redirects found yet.', 'seo-wp' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped seo-wp-broken-links-table"><thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'seo-wp' ) . '</th><th>' . esc_html__( 'Finding', 'seo-wp' ) . '</th><th>' . esc_html__( 'Destination / chain', 'seo-wp' ) . '</th><th>' . esc_html__( 'Linked from', 'seo-wp' ) . '</th><th>' . esc_html__( 'Source area', 'seo-wp' ) . '</th><th>' . esc_html__( 'Checked', 'seo-wp' ) . '</th></tr></thead><tbody>';
		foreach ( $broken as $url => $entry ) {
			$sources      = self::normalise_sources( (array) ( $entry['sources'] ?? array() ) );
			$source_links = array();
			$source_areas = array();
			foreach ( $sources as $source ) {
				$source_links[] = self::source_link_html( $source );
				$source_areas[] = esc_html( self::source_area_label( $source ) ); }
			$chain      = array_values( array_filter( array_map( 'strval', (array) ( $entry['chain'] ?? array() ) ) ) );
			$chain_html = array() !== $chain ? implode( '<br><span aria-hidden="true">↳</span> ', array_map( static fn ( string $item ): string => '<code>' . esc_html( $item ) . '</code>', $chain ) ) : ( ! empty( $entry['final_url'] ) ? '<code>' . esc_html( (string) $entry['final_url'] ) . '</code>' : '' );
			echo '<tr>';
			printf( '<td><code>%s</code></td>', esc_html( (string) $url ) );
			printf( '<td><strong>%1$s</strong><br><span class="description">%2$s</span></td>', esc_html( ucfirst( (string) ( $entry['type'] ?? 'broken' ) ) ), esc_html( (string) ( $entry['status'] ?? '' ) ) );
			echo '<td>' . $chain_html . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . implode( '<br>', $source_links ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . implode( '<br>', array_unique( $source_areas ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			printf( '<td>%s</td>', esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $entry['checked_at'] ?? 0 ) ) ) );
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

	/** Starts a fresh persistent background audit. */
	public function handle_start_ajax(): void {
		Ajax::begin( 'link-audit:start' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		delete_option( self::SCAN_OPTION );
		delete_option( self::QUEUE_OPTION );
		delete_option( self::RESULTS_OPTION );
		delete_transient( self::WORKER_LOCK );

		$total = (int) wp_count_posts( 'page' )->publish;
		$query = new WP_Query(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);
		$total = (int) $query->found_posts;
		$token = wp_generate_password( 32, false, false );
		$job   = array(
			'token'           => $token,
			'status'          => 'scanning',
			'started_at'      => time(),
			'updated_at'      => time(),
			'total_posts'     => $total,
			'processed_posts' => 0,
			'total_links'     => 0,
			'checked_links'   => 0,
			'current'         => __( 'Preparing content scan…', 'seo-wp' ),
			'error'           => '',
		);
		update_option( self::JOB_OPTION, $job, false );
		$this->dispatch_worker( $token );
		Ajax::success( $this->public_job_data( $job ) );
	}

	/** Returns the current persistent audit status. */
	public function handle_status_ajax(): void {
		Ajax::begin( 'link-audit:status' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}
		$job = self::job_data();
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'scanning', 'checking' ), true ) && time() - (int) ( $job['updated_at'] ?? 0 ) > 45 ) {
			$this->dispatch_worker( (string) ( $job['token'] ?? '' ) );
		}
		Ajax::success( $this->public_job_data( $job ) );
	}

	/**
	 * Advances the background job through an authenticated loopback
	 * request. No WP nonce here deliberately: this fires from
	 * dispatch_worker()'s own server-to-server loopback request, not a
	 * logged-in user's browser, so there is no user session to tie a
	 * nonce to — the random per-job token (compared with hash_equals()
	 * below) is this endpoint's actual authentication.
	 */
	public function handle_worker_ajax(): void {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see docblock: the token itself, verified via hash_equals() below, is the authentication for this loopback-only endpoint.
		$job   = self::job_data();
		if ( '' === $token || ! hash_equals( (string) ( $job['token'] ?? '' ), $token ) ) {
			wp_die( 'Invalid worker token', '', array( 'response' => 403 ) );
		}
		if ( get_transient( self::WORKER_LOCK ) ) {
			wp_die( 'Worker already active' );
		}
		set_transient( self::WORKER_LOCK, 1, 45 );
		try {
			$this->run_job_slice( $job );
		} catch ( \Throwable $e ) {
			$job['status']     = 'error';
			$job['error']      = $e->getMessage();
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
		}
		delete_transient( self::WORKER_LOCK );
		wp_die( 'OK' );
	}

	/**
	 * WP-Cron fallback for hosts that reject non-blocking loopback
	 * requests.
	 *
	 * @param string $token The job's own token, as scheduled by dispatch_worker().
	 */
	public function handle_cron_worker( string $token ): void {
		$job = self::job_data();
		if ( '' === $token || ! hash_equals( (string) ( $job['token'] ?? '' ), $token ) || ! in_array( (string) ( $job['status'] ?? '' ), array( 'scanning', 'checking' ), true ) ) {
			return;
		}
		if ( get_transient( self::WORKER_LOCK ) ) {
			return;
		}
		set_transient( self::WORKER_LOCK, 1, 45 );
		try {
			$this->run_job_slice( $job );
		} catch ( \Throwable $e ) {
			$job['status']     = 'error';
			$job['error']      = $e->getMessage();
			$job['updated_at'] = time();
			update_option( self::JOB_OPTION, $job, false );
		}
		delete_transient( self::WORKER_LOCK );
	}

	/**
	 * Processes one time-bounded slice and dispatches the next one.
	 *
	 * @param array<string,mixed> $job Current job state, see job_data().
	 */
	private function run_job_slice( array $job ): void {
		$status = (string) ( $job['status'] ?? '' );
		if ( 'scanning' === $status ) {
			$this->run_scan_slice( $job );
		} elseif ( 'checking' === $status ) {
			$before               = count( self::queue() );
			$progress             = $this->check_links_batch();
			$after                = count( self::queue() );
			$job['checked_links'] = (int) ( $job['checked_links'] ?? 0 ) + max( 0, $before - $after );
			$job['current']       = (string) ( $progress['current'] ?? '' );
			$job['updated_at']    = time();
			if ( 0 === $after ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				wp_clear_scheduled_hook( self::JOB_CRON_HOOK, array( (string) ( $job['token'] ?? '' ) ) );
			}
			update_option( self::JOB_OPTION, $job, false );
		}
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'scanning', 'checking' ), true ) ) {
			$this->dispatch_worker( (string) $job['token'] );
		}
	}

	/**
	 * Scans a dynamic batch of posts within a conservative runtime budget.
	 *
	 * @param array<string,mixed> $job Current job state, modified by reference; see job_data().
	 */
	private function run_scan_slice( array &$job ): void {
		$offset    = (int) ( $job['processed_posts'] ?? 0 );
		$query     = new WP_Query(
			array(
				'post_type'              => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::SCAN_BATCH_SIZE,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$scan      = self::scan_data();
		$queue     = self::queue();
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$deadline  = self::runtime_deadline();
		$processed = 0;
		foreach ( $query->posts as $post_id ) {
			if ( $processed > 0 && microtime( true ) >= $deadline ) {
				break; }
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue; }
			$title          = get_the_title( $post );
			$job['current'] = sprintf( '%s (#%d)', '' !== $title ? $title : __( '(no title)', 'seo-wp' ), (int) $post_id );
			$raw_links      = self::extract_links( (string) $post->post_content );
			$rendered_links = self::extract_links( ContentRenderer::render( $post ) );
			foreach ( $raw_links as $link ) {
				$this->process_link(
					$link,
					array(
						'type'    => 'content',
						'post_id' => (int) $post_id,
					),
					$host,
					$scan,
					$queue
				); }
			foreach ( array_diff( $rendered_links, $raw_links ) as $link ) {
				$this->process_link(
					$link,
					array(
						'type'    => 'template',
						'post_id' => (int) $post_id,
					),
					$host,
					$scan,
					$queue
				); }
			++$processed;
		}
		$job['processed_posts'] = $offset + $processed;
		$job['updated_at']      = time();
		update_option( self::SCAN_OPTION, $scan, false );
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( $job['processed_posts'] >= (int) ( $job['total_posts'] ?? 0 ) || 0 === count( $query->posts ) ) {
			$this->scan_navigation_menus( $host, $scan, $queue );
			$this->scan_widgets( $host, $scan, $queue );
			$scan['scanned_at'] = time();
			update_option( self::SCAN_OPTION, $scan, false );
			update_option( self::QUEUE_OPTION, $queue, false );
			$job['status']        = 'checking';
			$job['total_links']   = count( $queue );
			$job['checked_links'] = 0;
			$job['current']       = __( 'Preparing link checks…', 'seo-wp' );
			if ( 0 === count( $queue ) ) {
				$job['status']       = 'completed';
				$job['completed_at'] = time();
				wp_clear_scheduled_hook( self::JOB_CRON_HOOK, array( (string) ( $job['token'] ?? '' ) ) );
			}
		}
		update_option( self::JOB_OPTION, $job, false );
	}

	/**
	 * Sends a non-blocking loopback request; status polling can revive it
	 * if hosting blocks loopbacks.
	 *
	 * @param string $token The job's own token, checked by handle_worker_ajax()/handle_cron_worker().
	 */
	private function dispatch_worker( string $token ): void {
		if ( '' === $token ) {
			return;
		}

		if ( false === wp_next_scheduled( self::JOB_CRON_HOOK, array( $token ) ) ) {
			wp_schedule_single_event( time() + 60, self::JOB_CRON_HOOK, array( $token ) );
		}
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core's own filter (WP_Http), not one of ours.
				'body'      => array(
					'action' => self::WORKER_AJAX_ACTION,
					'token'  => $token,
				),
			)
		);
	}

	/**
	 * The persistent background job's current state, defaulting to idle
	 * when no job has ever run.
	 *
	 * @return array<string,mixed>
	 */
	private static function job_data(): array {
		$job = get_option( self::JOB_OPTION, array() );
		return is_array( $job ) ? $job : array( 'status' => 'idle' );
	}

	/**
	 * Strips the job state down to the fields the status-polling AJAX
	 * response actually needs (nothing internal like the worker token).
	 *
	 * @param array<string,mixed> $job Full job state, see job_data().
	 * @return array<string,mixed>
	 */
	private function public_job_data( array $job ): array {
		return array(
			'status'          => (string) ( $job['status'] ?? 'idle' ),
			'current'         => (string) ( $job['current'] ?? '' ),
			'total_posts'     => (int) ( $job['total_posts'] ?? 0 ),
			'processed_posts' => (int) ( $job['processed_posts'] ?? 0 ),
			'total_links'     => (int) ( $job['total_links'] ?? 0 ),
			'checked_links'   => (int) ( $job['checked_links'] ?? 0 ),
			'started_at'      => (int) ( $job['started_at'] ?? 0 ),
			'updated_at'      => (int) ( $job['updated_at'] ?? 0 ),
			'completed_at'    => (int) ( $job['completed_at'] ?? 0 ),
			'error'           => (string) ( $job['error'] ?? '' ),
		);
	}

	/**
	 * Handles one content-scan AJAX batch step.
	 */
	public function handle_scan_ajax(): void {
		Ajax::begin( 'link-audit:scan' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 ); }
		$page = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
		if ( 1 === $page ) {
			delete_option( self::SCAN_OPTION );
			delete_option( self::QUEUE_OPTION );
			delete_option( self::RESULTS_OPTION ); }
		$query     = new WP_Query(
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
		$scan      = self::scan_data();
		$queue     = self::queue();
		$host      = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$last_item = '';
		foreach ( $query->posts as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof WP_Post ) {
				continue; }
			$title          = get_the_title( $post );
			$last_item      = sprintf( '%s (#%d)', '' !== $title ? $title : __( '(no title)', 'seo-wp' ), (int) $post_id );
			$raw_links      = self::extract_links( (string) $post->post_content );
			$rendered_links = self::extract_links( ContentRenderer::render( $post ) );
			foreach ( $raw_links as $link ) {
				$this->process_link(
					$link,
					array(
						'type'    => 'content',
						'post_id' => (int) $post_id,
					),
					$host,
					$scan,
					$queue
				); }
			foreach ( array_diff( $rendered_links, $raw_links ) as $link ) {
				$this->process_link(
					$link,
					array(
						'type'    => 'template',
						'post_id' => (int) $post_id,
					),
					$host,
					$scan,
					$queue
				); }
		}
		$has_more = $page < (int) $query->max_num_pages;
		if ( ! $has_more ) {
			$this->scan_navigation_menus( $host, $scan, $queue );
			$this->scan_widgets( $host, $scan, $queue );
			$scan['scanned_at'] = time(); }
		update_option( self::SCAN_OPTION, $scan, false );
		update_option( self::QUEUE_OPTION, $queue, false );
		Ajax::success(
			array(
				'done'        => ! $has_more,
				'page'        => $page + 1,
				'processed'   => count( $query->posts ),
				'current'     => $last_item,
				'total_pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Classifies one extracted link: a resolvable internal link marks its
	 * target post as linked-to; anything else (external, or an internal
	 * link that doesn't resolve to a real post) is queued for a broken-
	 * link check.
	 *
	 * @param string                                  $link   Raw href value.
	 * @param array<string,mixed>                     $source Where this link was found, e.g. ['type' => 'content', 'post_id' => 123].
	 * @param string                                  $host   This site's own host, for internal/external classification.
	 * @param array{linked_post_ids: array<int,bool>} $scan   Scan data, modified by reference.
	 * @param array<string,array{sources:int[]}>      $queue  Link queue, modified by reference.
	 */
	private function process_link( string $link, array $source, string $host, array &$scan, array &$queue ): void {
		$target_host = (string) wp_parse_url( $link, PHP_URL_HOST );
		$is_internal = '' === $target_host || 0 === strcasecmp( $target_host, $host );
		$absolute    = '' === $target_host ? home_url( '/' . ltrim( $link, '/' ) ) : $link;
		if ( $is_internal ) {
			$resolved_id = url_to_postid( $absolute );
			if ( $resolved_id > 0 ) {
				$scan['linked_post_ids'][ $resolved_id ] = true;
				$canonical                               = get_permalink( $resolved_id );
				if ( is_string( $canonical ) && untrailingslashit( strtok( $canonical, '#' ) ) === untrailingslashit( strtok( $absolute, '#' ) ) ) {
					return;
				}
			}
		}
		if ( ! isset( $queue[ $absolute ] ) || ! is_array( $queue[ $absolute ] ) ) {
			$queue[ $absolute ] = array( 'sources' => array() ); }
		$queue[ $absolute ]['sources'] = self::normalise_sources( (array) ( $queue[ $absolute ]['sources'] ?? array() ) );
		$key                           = wp_json_encode( $source );
		foreach ( $queue[ $absolute ]['sources'] as $existing ) {
			if ( wp_json_encode( $existing ) === $key ) {
				return; }
		}
		$queue[ $absolute ]['sources'][] = $source;
	}

	/**
	 * Feeds every registered navigation menu's links through
	 * process_link(), so pages only reachable via the menu (not from
	 * within any post's content) aren't false-flagged as orphans. Counts
	 * every registered menu regardless of whether it's assigned to a
	 * theme location — a safe simplification that can only under-report
	 * orphans, never wrongly flag a genuinely reachable page.
	 *
	 * @param string                                  $host  This site's own host, for internal/external classification.
	 * @param array{linked_post_ids: array<int,bool>} $scan  Scan data, modified by reference.
	 * @param array<string,array{sources:int[]}>      $queue Link queue, modified by reference.
	 */
	private function scan_navigation_menus( string $host, array &$scan, array &$queue ): void {
		$menus = wp_get_nav_menus();
		if ( ! is_array( $menus ) ) {
			return; }
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			if ( ! is_array( $items ) ) {
				continue; }
			foreach ( $items as $item ) {
				$url = isset( $item->url ) ? (string) $item->url : '';
				if ( '' === $url ) {
					continue; }
				$this->process_link(
					$url,
					array(
						'type'    => 'menu',
						'menu_id' => (int) $menu->term_id,
						'label'   => (string) $menu->name,
					),
					$host,
					$scan,
					$queue
				);
			}
		}
	}


	/**
	 * Feeds every active (non-inactive) widget's rendered text through
	 * process_link(), the same as scan_navigation_menus() does for menus
	 * — so a page only linked to from a text/HTML widget isn't
	 * false-flagged as an orphan either.
	 *
	 * @param string                                  $host  This site's own host, for internal/external classification.
	 * @param array{linked_post_ids: array<int,bool>} $scan  Scan data, modified by reference.
	 * @param array<string,array{sources:int[]}>      $queue Link queue, modified by reference.
	 */
	private function scan_widgets( string $host, array &$scan, array &$queue ): void {
		$sidebars = wp_get_sidebars_widgets();
		if ( ! is_array( $sidebars ) ) {
			return;
		}

		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}

			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^(.+)-([0-9]+)$/', (string) $widget_id, $matches ) ) {
					continue;
				}

				$option = get_option( 'widget_' . $matches[1], array() );
				$data   = is_array( $option ) ? ( $option[ (int) $matches[2] ] ?? array() ) : array();

				foreach ( self::extract_links( self::flatten_widget_value( $data ) ) as $link ) {
					$this->process_link(
						$link,
						array(
							'type'      => 'widget',
							'widget_id' => (string) $widget_id,
							'label'     => (string) $sidebar_id,
						),
						$host,
						$scan,
						$queue
					);
				}
			}
		}
	}

	/**
	 * Flattens a widget's stored option value (a plain string for a
	 * legacy Text widget, or a nested array for a block-based widget's
	 * serialized attributes) into one string extract_links() can scan for
	 * hrefs, recursing into nested arrays.
	 *
	 * @param mixed $value A widget instance's stored option value.
	 */
	private static function flatten_widget_value( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		if ( ! is_array( $value ) ) {
			return '';
		}

		$parts = array();
		foreach ( $value as $item ) {
			$parts[] = self::flatten_widget_value( $item );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Normalises a queue entry's 'sources' list: an older, pre-M6 queue
	 * entry stored a bare post ID per source rather than the current
	 * {type, post_id, ...} shape, so a numeric entry is upgraded to that
	 * shape on the fly; anything already array-shaped passes through
	 * unchanged, anything else (a corrupt entry) is dropped.
	 *
	 * @param array<int,mixed> $sources Raw 'sources' list from the queue option.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalise_sources( array $sources ): array {
		$normalised = array();

		foreach ( $sources as $source ) {
			if ( is_numeric( $source ) ) {
				$source = array(
					'type'    => 'content',
					'post_id' => (int) $source,
				);
			}

			if ( is_array( $source ) ) {
				$normalised[] = $source;
			}
		}

		return $normalised;
	}

	/**
	 * A human-readable label for a normalised source's 'type', for the
	 * broken-links results table.
	 *
	 * @param array<string,mixed> $source A normalised source (see normalise_sources()).
	 */
	private static function source_area_label( array $source ): string {
		$type   = (string) ( $source['type'] ?? 'content' );
		$labels = array(
			'content'  => __( 'Content', 'seo-wp' ),
			'template' => __( 'Template / rendered element', 'seo-wp' ),
			'menu'     => __( 'Navigation menu', 'seo-wp' ),
			'widget'   => __( 'Widget', 'seo-wp' ),
		);

		return $labels[ $type ] ?? ucfirst( $type );
	}

	/**
	 * Builds a link (to the source post's editor, the menu editor or the
	 * widgets screen) for a normalised source, for the broken-links
	 * results table.
	 *
	 * @param array<string,mixed> $source A normalised source (see normalise_sources()).
	 */
	private static function source_link_html( array $source ): string {
		$type = (string) ( $source['type'] ?? 'content' );
		if ( isset( $source['post_id'] ) ) {
			$post_id   = (int) $source['post_id'];
			$edit_link = get_edit_post_link( $post_id, 'raw' );
			$title     = get_the_title( $post_id );
			return sprintf( '<a href="%1$s">%2$s</a> <span class="description">#%3$d</span>', esc_url( is_string( $edit_link ) ? $edit_link : '' ), esc_html( '' !== $title ? $title : __( '(no title)', 'seo-wp' ) ), $post_id ); }
		if ( 'menu' === $type ) {
			return sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( 'nav-menus.php?action=edit&menu=' . (int) ( $source['menu_id'] ?? 0 ) ) ), esc_html( (string) ( $source['label'] ?? __( 'Menu', 'seo-wp' ) ) ) ); }
		if ( 'widget' === $type ) {
			return sprintf( '<a href="%1$s">%2$s</a> <span class="description">%3$s</span>', esc_url( admin_url( 'widgets.php' ) ), esc_html( (string) ( $source['widget_id'] ?? __( 'Widget', 'seo-wp' ) ) ), esc_html( (string) ( $source['label'] ?? '' ) ) ); }
		return esc_html( self::source_area_label( $source ) );
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

		$progress = $this->check_links_batch();

		Ajax::success(
			array(
				'remaining' => count( self::queue() ),
				'current'   => (string) ( $progress['current'] ?? '' ),
				'processed' => (int) ( $progress['processed'] ?? 0 ),
			)
		);
	}

	/**
	 * Checks a batch of queued URLs and records any that are broken.
	 * Runs on the fifteen-minute cron schedule, or on demand via
	 * handle_check_now_ajax().
	 */
	public function check_links_batch(): array {
		$queue = self::queue();
		if ( array() === $queue ) {
			return array(
				'processed' => 0,
				'current'   => '',
			);
		}

		// Each AJAX/cron request intentionally handles only a bounded slice.
		// This prevents large sites or slow external hosts from exhausting the
		// PHP max_execution_time. The browser immediately requests the next
		// slice until the queue is empty.
		$deadline  = self::runtime_deadline();
		$batch     = array_slice( $queue, 0, self::CHECK_BATCH_SIZE, true );
		$results   = self::broken_links();
		$processed = 0;
		$current   = '';

		foreach ( $batch as $url => $data ) {
			if ( $processed > 0 && microtime( true ) >= $deadline ) {
				break;
			}

			$current = (string) $url;
			unset( $queue[ $url ] );
			$finding = self::check_url( $current );
			if ( null !== $finding ) {
				$finding['sources']    = self::normalise_sources( (array) ( $data['sources'] ?? array() ) );
				$finding['checked_at'] = time();
				$results[ $url ]       = $finding;
			} else {
				unset( $results[ $url ] );
			}
			++$processed;
		}

		if ( count( $results ) > self::MAX_RESULTS ) {
			uasort( $results, static fn ( array $a, array $b ): int => ( $a['checked_at'] ?? 0 ) <=> ( $b['checked_at'] ?? 0 ) );
			$results = array_slice( $results, -self::MAX_RESULTS, null, true );
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		update_option( self::RESULTS_OPTION, $results, false );

		return array(
			'processed' => $processed,
			'current'   => $current,
		);
	}

	/**
	 * Calculates a conservative deadline for one link-check request.
	 * Unlimited runtimes still use a short slice so progress remains visible.
	 */
	private static function runtime_deadline(): float {
		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = $limit > 0 ? max( 5, min( 20, (int) floor( $limit * 0.6 ) ) ) : 15;
		return microtime( true ) + $budget;
	}

	/**
	 * Checks a single URL, returning a human-readable status/error when
	 * it's broken, or null when it's fine. Tries HEAD first (cheaper),
	 * falling back to GET since some servers reject HEAD requests outright.
	 *
	 * @param string $url URL to check.
	 */
	private static function check_url( string $url ): ?array {
		$current = $url;
		$chain   = array();
		$codes   = array();
		for ( $step = 0; $step < 10; $step++ ) {
			$args     = array(
				'timeout'     => 5,
				'redirection' => 0,
				'user-agent'  => 'SEO-WP Link Audit/' . \SeoWp\VERSION,
			);
			$response = wp_remote_head( $current, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || 0 === $code || in_array( $code, array( 403, 405 ), true ) ) {
				$response = wp_remote_get( $current, $args ); }
			if ( is_wp_error( $response ) ) {
				return array(
					'type'      => 'broken',
					'status'    => $response->get_error_message(),
					'final_url' => $current,
					'chain'     => $chain,
				); }
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || '' === $location ) {
					return array(
						'type'      => 'broken',
						'status'    => $code . ' without Location header',
						'final_url' => $current,
						'chain'     => $chain,
					); }
				$next    = self::resolve_redirect_url( $current, $location );
				$codes[] = (string) $code;
				$chain[] = $next;
				if ( in_array( $next, array_merge( array( $url ), array_slice( $chain, 0, -1 ) ), true ) ) {
					return array(
						'type'      => 'redirect loop',
						'status'    => implode( ' → ', $codes ),
						'final_url' => $next,
						'chain'     => $chain,
					); }
				$current = $next;
				continue;
			}
			if ( $code >= 400 || 0 === $code ) {
				return array(
					'type'      => 'broken',
					'status'    => (string) $code,
					'final_url' => $current,
					'chain'     => $chain,
				); }
			if ( array() !== $chain ) {
				$codes[] = (string) $code;
				return array(
					'type'      => count( $chain ) > 1 ? 'redirect chain' : 'redirect',
					'status'    => implode( ' → ', $codes ),
					'final_url' => $current,
					'chain'     => $chain,
				); }
			return null;
		}
		return array(
			'type'      => 'redirect chain',
			'status'    => __( 'More than 10 redirects', 'seo-wp' ),
			'final_url' => $current,
			'chain'     => $chain,
		);
	}


	/**
	 * Resolves a redirect response's Location header against the URL that
	 * was requested: an already-absolute URL is returned as-is; a
	 * protocol-relative ("//host/path") header inherits the base URL's
	 * scheme; an absolute-path ("/path") header inherits its scheme and
	 * host; anything else is resolved against the base URL's own
	 * directory, the same way a browser resolves a relative Location
	 * header. An empty header (which check_url() already treats as
	 * "broken" before ever reaching this method) falls back to the base
	 * URL unchanged rather than resolving to something nonsensical. Pure
	 * string logic, independent of WordPress beyond wp_parse_url(), for
	 * easy unit testing.
	 *
	 * @param string $base     The URL that was requested (redirect chains call this again with each hop's own URL as the new base).
	 * @param string $location Raw Location header value.
	 */
	public static function resolve_redirect_url( string $base, string $location ): string {
		if ( '' === $location ) {
			return $base;
		}

		if ( preg_match( '#^https?://#i', $location ) ) {
			return $location;
		}

		$parts  = wp_parse_url( $base );
		$scheme = (string) ( $parts['scheme'] ?? 'https' );
		$host   = (string) ( $parts['host'] ?? '' );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';

		if ( str_starts_with( $location, '//' ) ) {
			return $scheme . ':' . $location;
		}

		if ( str_starts_with( $location, '/' ) ) {
			return $scheme . '://' . $host . $port . $location;
		}

		$path = (string) ( $parts['path'] ?? '/' );

		return $scheme . '://' . $host . $port . trailingslashit( dirname( $path ) ) . $location;
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

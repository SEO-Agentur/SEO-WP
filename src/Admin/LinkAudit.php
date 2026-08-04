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
 * every published post's fully rendered content, every nav menu item's
 * URL, and the two core widget types that commonly hold hand-written
 * links (Custom HTML, Text) — building the set of post IDs something on
 * the site actually links to (anything public and published but NOT in
 * that set is an orphan page), and queuing every other link (external, or
 * an internal one that doesn't resolve to a real post) for a check.
 * Checks run in the background via WP-Cron (wp_remote_head/get, a
 * handful per run) rather than synchronously, since checking hundreds or
 * thousands of URLs in one admin-ajax request would time out; results
 * fill in gradually, not instantly. Each queued/checked link remembers
 * exactly where it was found (post, nav menu, or widget) so a broken
 * link can actually be tracked down and fixed, not just reported.
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
	 * AJAX action for re-checking one specific link immediately.
	 */
	private const RECHECK_AJAX_ACTION = 'seo_wp_link_audit_recheck';

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
	 * Oldest link-issue results are dropped once the log exceeds this
	 * size.
	 */
	private const MAX_RESULTS = 500;

	/**
	 * Sources listed per row before collapsing the rest into "and N more".
	 */
	private const MAX_SOURCES_SHOWN = 5;

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
		add_action( 'wp_ajax_' . self::RECHECK_AJAX_ACTION, array( $this, 'handle_recheck_ajax' ) );
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
				'recheckAction'  => self::RECHECK_AJAX_ACTION,
				'i18n'           => array(
					'scanning'   => __( 'Scanning…', 'seo-wp' ),
					'checking'   => __( 'Checking…', 'seo-wp' ),
					'rechecking' => __( 'Rechecking…', 'seo-wp' ),
					'done'       => __( 'Done.', 'seo-wp' ),
					'fixed'      => __( 'Fixed — no longer an issue.', 'seo-wp' ),
					'error'      => __( 'Something went wrong.', 'seo-wp' ),
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

		$scan  = self::scan_data();
		$queue = self::queue();

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

		$this->render_link_issues( self::link_issues() );

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
	 * Renders the link-issues table: confirmed-broken links, and internal
	 * links that resolve only via a redirect (or a redirect chain).
	 *
	 * @param array<string,array{sources:array<array{type:string,id:int|string,label:string,edit_url:string}>,kind:string,status:string,chain:array<array{url:string,to:string,code:int}>,checked_at:int}> $issues Link-issue results.
	 */
	private function render_link_issues( array $issues ): void {
		echo '<h2>' . esc_html__( 'Link issues', 'seo-wp' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Broken links and redirects found in page content, navigation menus, and Custom HTML/Text widgets. Use "Recheck" after fixing one to confirm it\'s cleared — a full content scan isn\'t needed just to verify a single fix.', 'seo-wp' ) . '</p>';

		if ( array() === $issues ) {
			echo '<p>' . esc_html__( 'No broken links or redirect issues found yet.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-wp-link-issues-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'URL', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Issue', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Linked from', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Checked', 'seo-wp' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $issues as $url => $entry ) {
			echo '<tr data-seo-wp-link-issue-url="' . esc_attr( (string) $url ) . '">';
			printf( '<td><code>%s</code></td>', esc_html( (string) $url ) );
			echo '<td>' . $this->render_issue_status( (array) $entry ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_issue_status() escapes every value it outputs.
			echo '<td>' . $this->render_issue_sources( (array) ( $entry['sources'] ?? array() ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_issue_sources() escapes every value it outputs.
			printf( '<td>%s</td>', esc_html( gmdate( 'Y-m-d H:i:s', (int) ( $entry['checked_at'] ?? 0 ) ) ) );
			echo '<td><button type="button" class="button button-small seo-wp-link-audit-recheck" data-url="' . esc_attr( (string) $url ) . '">' . esc_html__( 'Recheck', 'seo-wp' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders one issue's Status cell: the plain broken status, or — for a
	 * link that only resolves via a redirect — the redirect target and,
	 * when there's more than one hop, the full chain.
	 *
	 * @param array{kind?:string,status?:string,chain?:array<array{url:string,to:string,code:int}>} $entry One link-issue entry.
	 */
	private function render_issue_status( array $entry ): string {
		$kind   = (string) ( $entry['kind'] ?? 'broken' );
		$status = (string) ( $entry['status'] ?? '' );

		if ( 'redirect' !== $kind ) {
			return sprintf(
				'<span class="seo-wp-link-issue-broken">%s</span>',
				esc_html( '' !== $status ? $status : __( 'Broken', 'seo-wp' ) )
			);
		}

		$chain = (array) ( $entry['chain'] ?? array() );
		$hops  = array();
		foreach ( $chain as $hop ) {
			$hops[] = sprintf(
				'%1$s → %2$s (%3$s)',
				esc_html( (string) ( $hop['url'] ?? '' ) ),
				esc_html( (string) ( $hop['to'] ?? '' ) ),
				esc_html( (string) ( $hop['code'] ?? '' ) )
			);
		}

		$label = count( $chain ) > 1
			? sprintf(
				/* translators: %d: number of redirect hops. */
				__( 'Redirect chain (%d hops)', 'seo-wp' ),
				count( $chain )
			)
			: __( 'Redirects', 'seo-wp' );

		return sprintf(
			'<span class="seo-wp-link-issue-redirect">%1$s</span><br><span class="description">%2$s</span>',
			esc_html( $label ),
			implode( '<br>', $hops ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every $hop entry is already esc_html()'d above.
		);
	}

	/**
	 * Renders one issue's "Linked from" cell: readable, edit-linked source
	 * labels tagged with where the link was found (Content/Menu/Widget),
	 * capped at MAX_SOURCES_SHOWN with the rest collapsed into "and N more"
	 * instead of a wall of raw IDs.
	 *
	 * @param array<array{type:string,id:int|string,label:string,edit_url:string}> $sources Sources the link was found in.
	 */
	private function render_issue_sources( array $sources ): string {
		if ( array() === $sources ) {
			return '';
		}

		$type_labels = array(
			'content' => __( 'Content', 'seo-wp' ),
			'menu'    => __( 'Menu', 'seo-wp' ),
			'widget'  => __( 'Widget', 'seo-wp' ),
		);

		$shown   = array_slice( $sources, 0, self::MAX_SOURCES_SHOWN );
		$extra   = count( $sources ) - count( $shown );
		$entries = array();

		foreach ( $shown as $source ) {
			$type       = (string) ( $source['type'] ?? '' );
			$type_label = $type_labels[ $type ] ?? ucfirst( $type );
			$label      = (string) ( $source['label'] ?? '' );
			$edit_url   = (string) ( $source['edit_url'] ?? '' );

			$entries[] = '' !== $edit_url
				? sprintf( '<a href="%1$s">%2$s</a> <span class="description">(%3$s)</span>', esc_url( $edit_url ), esc_html( $label ), esc_html( $type_label ) )
				: sprintf( '%1$s <span class="description">(%2$s)</span>', esc_html( $label ), esc_html( $type_label ) );
		}

		$html = implode( ', ', $entries );

		if ( $extra > 0 ) {
			$html .= ' ' . sprintf(
				/* translators: %d: number of additional sources not individually listed. */
				esc_html__( 'and %d more', 'seo-wp' ),
				$extra
			);
		}

		return $html;
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

			$source = self::content_source( $post );
			foreach ( self::extract_links( ContentRenderer::render( $post ) ) as $link ) {
				$this->process_link( $link, $source, $host, $scan, $queue );
			}
		}

		$has_more = $page < (int) $query->max_num_pages;

		if ( ! $has_more ) {
			foreach ( self::nav_menu_linked_post_ids() as $id ) {
				$scan['linked_post_ids'][ $id ] = true;
			}

			foreach ( self::menu_link_sources() as $found ) {
				$this->process_link( $found['url'], $found['source'], $host, $scan, $queue );
			}

			foreach ( self::widget_link_sources() as $found ) {
				$this->process_link( $found['url'], $found['source'], $host, $scan, $queue );
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
	 * link that doesn't resolve to a real post) is queued for a check,
	 * remembering exactly where it was found.
	 *
	 * @param string                                                        $link  Raw href/URL value.
	 * @param array{type:string,id:int|string,label:string,edit_url:string} $source Where the link was found.
	 * @param string                                                        $host  This site's own host, for internal/external classification.
	 * @param array{linked_post_ids: array<int,bool>}                       $scan  Scan data, modified by reference.
	 * @param array<string,array{sources:array}>                            $queue Link queue, modified by reference.
	 */
	private function process_link( string $link, array $source, string $host, array &$scan, array &$queue ): void {
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

		foreach ( $queue[ $absolute ]['sources'] as $existing ) {
			if ( ( $existing['type'] ?? '' ) === $source['type'] && ( $existing['id'] ?? null ) === $source['id'] ) {
				return;
			}
		}

		$queue[ $absolute ]['sources'][] = $source;
	}

	/**
	 * Builds the source descriptor for a link found in a post's rendered
	 * content.
	 *
	 * @param WP_Post $post Post the link was found in.
	 */
	private static function content_source( WP_Post $post ): array {
		$edit_link = get_edit_post_link( $post->ID, 'raw' );

		return array(
			'type'     => 'content',
			'id'       => $post->ID,
			'label'    => get_the_title( $post ),
			'edit_url' => is_string( $edit_link ) ? $edit_link : '',
		);
	}

	/**
	 * Every navigation-menu item's URL, tagged as a "menu" source —
	 * including custom links and links to external sites, not just links
	 * to a post/page/category (nav_menu_linked_post_ids() already handles
	 * marking those as non-orphans; this additionally queues the actual
	 * URL for a check, which that simpler pass doesn't do).
	 *
	 * @return array<array{url:string,source:array{type:string,id:int|string,label:string,edit_url:string}}>
	 */
	private static function menu_link_sources(): array {
		$found = array();
		$menus = wp_get_nav_menus();

		if ( ! is_array( $menus ) ) {
			return $found;
		}

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu );
			if ( ! is_array( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$url = isset( $item->url ) ? (string) $item->url : '';
				if ( '' === $url || '#' === $url ) {
					continue;
				}

				$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
				if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					continue;
				}

				$title = isset( $item->title ) ? (string) $item->title : '';

				$found[] = array(
					'url'    => $url,
					'source' => array(
						'type'     => 'menu',
						'id'       => (int) $item->ID,
						'label'    => sprintf( '%1$s (%2$s)', '' !== $title ? $title : $url, $menu->name ),
						'edit_url' => admin_url( 'nav-menus.php?action=edit&menu=' . (int) $menu->term_id ),
					),
				);
			}
		}

		return $found;
	}

	/**
	 * Every link found inside a Custom HTML or Text widget instance,
	 * tagged as a "widget" source. These two widget types are the ones
	 * that commonly hold hand-written links WordPress otherwise has no
	 * structured way to discover.
	 *
	 * @return array<array{url:string,source:array{type:string,id:int|string,label:string,edit_url:string}}>
	 */
	private static function widget_link_sources(): array {
		$found = array();

		foreach ( array( 'widget_custom_html', 'widget_text' ) as $option_name ) {
			$instances = get_option( $option_name, array() );
			if ( ! is_array( $instances ) ) {
				continue;
			}

			foreach ( $instances as $number => $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}

				$content = (string) ( $instance['content'] ?? '' );
				if ( '' === $content ) {
					continue;
				}

				$title = (string) ( $instance['title'] ?? '' );
				$label = '' !== $title
					? $title
					: sprintf(
						/* translators: %d: widget instance number. */
						__( 'Widget #%d', 'seo-wp' ),
						(int) $number
					);

				foreach ( self::extract_links( $content ) as $url ) {
					$found[] = array(
						'url'    => $url,
						'source' => array(
							'type'     => 'widget',
							'id'       => $option_name . ':' . $number,
							'label'    => $label,
							'edit_url' => admin_url( 'widgets.php' ),
						),
					);
				}
			}
		}

		return $found;
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
	 * Handles a per-row "Recheck" click: checks exactly one URL
	 * immediately (instead of waiting for its turn in the batch queue) and
	 * reports whether it's now fixed, so a link the admin just corrected
	 * can be confirmed and cleared without a full content scan.
	 */
	public function handle_recheck_ajax(): void {
		Ajax::begin( 'link-audit:recheck' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			Ajax::error( array( 'message' => __( 'Missing URL.', 'seo-wp' ) ), 400 );
		}

		$sources = (array) ( self::link_issues()[ $url ]['sources'] ?? array() );

		self::apply_check_result( $url, $sources );

		Ajax::success( array( 'fixed' => ! isset( self::link_issues()[ $url ] ) ) );
	}

	/**
	 * Checks a batch of queued URLs and records any that are broken or
	 * only resolve via a redirect. Runs on the configured cron schedule,
	 * or on demand via handle_check_now_ajax().
	 */
	public function check_links_batch(): void {
		$queue = self::queue();
		if ( array() === $queue ) {
			return;
		}

		$batch = array_slice( $queue, 0, self::CHECK_BATCH_SIZE, true );

		foreach ( $batch as $url => $data ) {
			unset( $queue[ $url ] );
			self::apply_check_result( (string) $url, (array) ( $data['sources'] ?? array() ) );
		}

		update_option( self::QUEUE_OPTION, $queue, false );
	}

	/**
	 * Checks one URL and writes (or clears) its stored link-issue entry —
	 * the shared step behind both the batch check and a single-URL
	 * recheck, so a link fixed since the last scan is actually removed
	 * from the list instead of lingering until the next full scan.
	 *
	 * @param string                                                               $url     URL to check.
	 * @param array<array{type:string,id:int|string,label:string,edit_url:string}> $sources Where this link was found.
	 */
	private static function apply_check_result( string $url, array $sources ): void {
		$result = self::check_url( $url );
		$issues = self::link_issues();

		if ( null === $result ) {
			unset( $issues[ $url ] );
		} else {
			$issues[ $url ] = array(
				'sources'    => $sources,
				'kind'       => $result['kind'],
				'status'     => $result['status'],
				'chain'      => $result['chain'] ?? array(),
				'checked_at' => time(),
			);
		}

		if ( count( $issues ) > self::MAX_RESULTS ) {
			uasort( $issues, static fn ( array $a, array $b ): int => ( $a['checked_at'] ?? 0 ) <=> ( $b['checked_at'] ?? 0 ) );
			$issues = array_slice( $issues, -self::MAX_RESULTS, null, true );
		}

		update_option( self::RESULTS_OPTION, $issues, false );
	}

	/**
	 * Checks a single URL, following redirects itself (rather than letting
	 * wp_remote_*() auto-follow them) so a redirect chain is visible
	 * instead of invisibly resolved. Returns null when the link is healthy
	 * (a direct 2xx response, no redirect at all); otherwise a result
	 * describing whether it's broken outright or only reachable via one or
	 * more redirect hops.
	 *
	 * @param string $url URL to check.
	 * @return array{kind:string,status:string,chain:array<array{url:string,to:string,code:int}>}|null
	 */
	private static function check_url( string $url ): ?array {
		$chain   = array();
		$current = $url;
		$args    = array(
			'timeout'     => 5,
			'redirection' => 0,
		);

		for ( $hop = 0; $hop < 6; $hop++ ) {
			$response = wp_remote_head( $current, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( is_wp_error( $response ) || 0 === $code || $code >= 400 ) {
				$response = wp_remote_get( $current, $args );
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			}

			if ( is_wp_error( $response ) ) {
				return array(
					'kind'   => 'broken',
					'status' => $response->get_error_message(),
					'chain'  => $chain,
				);
			}

			if ( $code >= 300 && $code < 400 ) {
				$location = wp_remote_retrieve_header( $response, 'location' );
				$location = is_string( $location ) ? $location : '';

				if ( '' === $location ) {
					return array(
						'kind'   => 'broken',
						'status' => (string) $code,
						'chain'  => $chain,
					);
				}

				$next = self::resolve_relative_url( $location, $current );

				if ( $next === $current || in_array( $next, wp_list_pluck( $chain, 'url' ), true ) ) {
					return array(
						'kind'   => 'broken',
						'status' => __( 'Redirect loop', 'seo-wp' ),
						'chain'  => $chain,
					);
				}

				$chain[] = array(
					'url'  => $current,
					'to'   => $next,
					'code' => $code,
				);

				$current = $next;
				continue;
			}

			if ( $code >= 400 || 0 === $code ) {
				return array(
					'kind'   => 'broken',
					'status' => (string) $code,
					'chain'  => $chain,
				);
			}

			// A healthy final response — but if it took one or more
			// redirect hops to get here, that's still worth surfacing:
			// the source link should point straight at the real target.
			if ( array() !== $chain ) {
				return array(
					'kind'   => 'redirect',
					'status' => (string) $code,
					'chain'  => $chain,
				);
			}

			return null;
		}

		return array(
			'kind'   => 'broken',
			'status' => __( 'Too many redirects', 'seo-wp' ),
			'chain'  => $chain,
		);
	}

	/**
	 * Resolves a Location header value (which may be relative) against the
	 * URL it was returned for, into an absolute URL. Public (and pure, no
	 * WordPress I/O) so it's directly unit-testable — see check_url()'s
	 * use of it, where a resolution bug would silently corrupt an entire
	 * redirect chain.
	 *
	 * @param string $location Raw Location header value.
	 * @param string $base     The URL that produced this redirect.
	 */
	public static function resolve_relative_url( string $location, string $base ): string {
		$location = trim( $location );
		if ( '' === $location ) {
			return $base;
		}

		if ( 0 === strpos( $location, '//' ) ) {
			$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
			return ( '' !== $scheme ? $scheme : 'https' ) . ':' . $location;
		}

		if ( '' !== (string) wp_parse_url( $location, PHP_URL_HOST ) ) {
			return $location;
		}

		$scheme = (string) wp_parse_url( $base, PHP_URL_SCHEME );
		$host   = (string) wp_parse_url( $base, PHP_URL_HOST );

		if ( isset( $location[0] ) && '/' === $location[0] ) {
			return $scheme . '://' . $host . $location;
		}

		$base_dir = trailingslashit( dirname( (string) wp_parse_url( $base, PHP_URL_PATH ) ) );

		return $scheme . '://' . $host . $base_dir . $location;
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
	 * @return array<string,array{sources:array<array{type:string,id:int|string,label:string,edit_url:string}>}>
	 */
	private static function queue(): array {
		$queue = get_option( self::QUEUE_OPTION, array() );

		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * The current confirmed link-issue results (broken links and
	 * redirects), keyed by URL.
	 *
	 * @return array<string,array{sources:array<array{type:string,id:int|string,label:string,edit_url:string}>,kind:string,status:string,chain:array<array{url:string,to:string,code:int}>,checked_at:int}>
	 */
	private static function link_issues(): array {
		$issues = get_option( self::RESULTS_OPTION, array() );

		return is_array( $issues ) ? $issues : array();
	}
}

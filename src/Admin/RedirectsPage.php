<?php
/**
 * Admin CRUD screen for redirect rules.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Crawl\Redirects;

/**
 * Add/edit/delete screen for Crawl\Redirects rules: a form (add, or edit
 * when ?edit={id} is present) plus a plain table of every existing rule.
 */
final class RedirectsPage {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-redirects';

	/**
	 * Capability required to manage redirects.
	 */
	private const CAPABILITY = 'seo_wp_manage_redirects';

	/**
	 * Nonce action/field name.
	 */
	private const NONCE_ACTION = 'seo_wp_redirects';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_redirects_nonce';

	/**
	 * Registers the submenu page and its form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_redirects_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_seo_wp_redirects_delete', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_seo_wp_redirects_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_seo_wp_redirects_import', array( $this, 'handle_import' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the Redirects submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Redirects', 'seo-wp' ),
			__( 'Redirects', 'seo-wp' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the shared admin stylesheet on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
		wp_enqueue_script( 'seo-wp-redirects', plugins_url( 'assets/admin/redirects.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );
	}

	/**
	 * Renders the redirects page: the add/edit form, an import/export
	 * panel, then a table of every existing rule.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$edit_id     = isset( $_GET['edit'] ) ? max( 0, (int) $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only "which row to edit" selector, not a state-changing action.
		$rules       = Redirects::all();
		$editing     = $edit_id > 0 && isset( $rules[ $edit_id ] ) ? $rules[ $edit_id ] : null;
		$prefill_src = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill from the 404 monitor's "create redirect" link, not a state-changing action.

		echo '<div class="wrap seo-wp-redirects">';
		echo '<h1>' . esc_html__( 'SEO-WP Redirects', 'seo-wp' ) . '</h1>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner, not a state-changing action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Redirect saved.', 'seo-wp' ) . '</p></div>';
		}

		$this->maybe_render_import_notice();

		$this->render_form( $edit_id, $editing, $prefill_src );
		$this->render_import_export();
		$this->render_table( $rules );

		echo '</div>';
	}

	/**
	 * Shows the result of a just-completed CSV import, if any.
	 */
	private function maybe_render_import_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner reflecting the just-completed import, not a state-changing action itself.
		if ( ! isset( $_GET['imported'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$imported = (int) $_GET['imported'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number of rows imported, 2: number of rows skipped. */
					__( 'Import complete: %1$d redirect(s) imported, %2$d row(s) skipped (no source column).', 'seo-wp' ),
					$imported,
					$skipped
				)
			)
		);
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @param int                                                                                  $edit_id     ID being edited, or 0 for a new rule.
	 * @param array{source:string,target:string,type:int,active:bool,is_regex?:bool,hits:int}|null $editing     The rule being edited, if any.
	 * @param string                                                                               $prefill_src Source path to prefill for a new rule (from the 404 monitor).
	 */
	private function render_form( int $edit_id, ?array $editing, string $prefill_src ): void {
		$source   = null !== $editing ? $editing['source'] : $prefill_src;
		$target   = null !== $editing ? $editing['target'] : '';
		$type     = null !== $editing ? $editing['type'] : 301;
		$active   = null === $editing || $editing['active'];
		$is_regex = null !== $editing && ! empty( $editing['is_regex'] );

		echo '<h2>' . ( $edit_id > 0 ? esc_html__( 'Edit redirect', 'seo-wp' ) : esc_html__( 'Add redirect', 'seo-wp' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="seo-wp-redirects-form">';
		echo '<input type="hidden" name="action" value="seo_wp_redirects_save" />';
		printf( '<input type="hidden" name="id" value="%d" />', (int) $edit_id );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="seo_wp_redirect_source">%1$s</label></th><td><input type="text" id="seo_wp_redirect_source" name="source" value="%2$s" class="regular-text code" placeholder="/old-page/" required /><p class="description" data-seo-wp-redirect-source-hint>%3$s</p></td></tr>',
			esc_html__( 'Source path', 'seo-wp' ),
			esc_attr( $source ),
			esc_html__( 'The exact site-relative path to match, e.g. /old-page/.', 'seo-wp' )
		);
		printf(
			'<tr><th scope="row"><label for="seo_wp_redirect_target">%1$s</label></th><td><input type="text" id="seo_wp_redirect_target" name="target" value="%2$s" class="regular-text code" placeholder="https://example.com/new-page/" /><p class="description" data-seo-wp-redirect-target-hint style="display:none;">%3$s</p></td></tr>',
			esc_html__( 'Target URL', 'seo-wp' ),
			esc_attr( $target ),
			esc_html__( 'Can reference capture groups from the pattern above as $1, $2, …', 'seo-wp' )
		);

		printf(
			'<tr><th scope="row">%1$s</th><td><label for="seo_wp_redirect_is_regex"><input type="checkbox" id="seo_wp_redirect_is_regex" name="is_regex" value="1" %2$s /> %3$s</label><p class="description">%4$s</p></td></tr>',
			esc_html__( 'Pattern', 'seo-wp' ),
			checked( $is_regex, true, false ),
			esc_html__( 'This is a regular expression, not a fixed path', 'seo-wp' ),
			esc_html__( 'E.g. ^/blog/(.+)$ — no delimiters needed, they\'re added automatically. An invalid pattern is saved but will never match anything, and is flagged below.', 'seo-wp' )
		);

		if ( null !== $editing && $is_regex && ! Redirects::is_valid_regex( $editing['source'] ) ) {
			echo '<tr><td colspan="2"><div class="notice notice-warning inline"><p>' . esc_html__( 'This pattern is not valid regex and will never match — double-check it.', 'seo-wp' ) . '</p></div></td></tr>';
		}

		echo '<tr><th scope="row"><label for="seo_wp_redirect_type">' . esc_html__( 'Type', 'seo-wp' ) . '</label></th><td><select id="seo_wp_redirect_type" name="type">';
		foreach ( $this->type_labels() as $value => $label ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $value, selected( $type, $value, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		printf(
			'<tr><th scope="row">%1$s</th><td><label for="seo_wp_redirect_active"><input type="checkbox" id="seo_wp_redirect_active" name="active" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Active', 'seo-wp' ),
			checked( $active, true, false ),
			esc_html__( 'Active', 'seo-wp' )
		);

		echo '</tbody></table>';
		submit_button( $edit_id > 0 ? __( 'Update redirect', 'seo-wp' ) : __( 'Add redirect', 'seo-wp' ) );
		echo '</form>';
	}

	/**
	 * Renders the CSV export link and import form.
	 */
	private function render_import_export(): void {
		$export_url = wp_nonce_url(
			add_query_arg( 'action', 'seo_wp_redirects_export', admin_url( 'admin-post.php' ) ),
			self::NONCE_ACTION . '_export'
		);

		echo '<h2>' . esc_html__( 'Import / export', 'seo-wp' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: CSV column list. */
				__( 'CSV columns: %s. Importing always adds new rows — it never overwrites or deduplicates existing ones, so review the list afterwards.', 'seo-wp' ),
				implode( ', ', Redirects::csv_header() )
			)
		) . '</p>';

		echo '<p><a href="' . esc_url( $export_url ) . '" class="button">' . esc_html__( 'Export CSV', 'seo-wp' ) . '</a></p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="seo-wp-redirects-import-form">';
		echo '<input type="hidden" name="action" value="seo_wp_redirects_import" />';
		wp_nonce_field( self::NONCE_ACTION . '_import', self::NONCE_NAME . '_import' );
		echo '<input type="file" name="csv_file" accept=".csv,text/csv" required /> ';
		submit_button( __( 'Import CSV', 'seo-wp' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders the table of existing rules.
	 *
	 * @param array<int,array{source:string,target:string,type:int,active:bool,is_regex?:bool,hits:int}> $rules Every stored rule.
	 */
	private function render_table( array $rules ): void {
		echo '<h2>' . esc_html__( 'Existing redirects', 'seo-wp' ) . '</h2>';

		if ( array() === $rules ) {
			echo '<p>' . esc_html__( 'No redirects yet.', 'seo-wp' ) . '</p>';
			return;
		}

		$type_labels = $this->type_labels();

		echo '<table class="widefat striped seo-wp-redirects-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Source', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Target', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Active', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Hits', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rules as $id => $rule ) {
			$is_regex   = ! empty( $rule['is_regex'] );
			$edit_url   = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'edit' => $id,
				),
				admin_url( 'admin.php' )
			);
			$delete_url = wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'seo_wp_redirects_delete',
						'id'     => $id,
					),
					admin_url( 'admin-post.php' )
				),
				self::NONCE_ACTION . '_delete_' . $id
			);

			echo '<tr>';
			echo '<td><code>' . esc_html( $rule['source'] ) . '</code>';
			if ( $is_regex ) {
				$valid = Redirects::is_valid_regex( $rule['source'] );
				printf(
					' <span class="seo-wp-badge %1$s" title="%2$s">%3$s</span>',
					$valid ? 'seo-wp-badge-info' : 'seo-wp-badge-warning',
					$valid ? esc_attr__( 'Regular expression', 'seo-wp' ) : esc_attr__( 'Invalid pattern — never matches', 'seo-wp' ),
					$valid ? esc_html__( 'regex', 'seo-wp' ) : esc_html__( 'invalid regex', 'seo-wp' )
				);
			}
			echo '</td>';
			printf( '<td><code>%s</code></td>', esc_html( '' !== $rule['target'] ? $rule['target'] : '—' ) );
			printf( '<td>%s</td>', esc_html( $type_labels[ $rule['type'] ] ?? (string) $rule['type'] ) );
			printf( '<td>%s</td>', $rule['active'] ? esc_html__( 'Yes', 'seo-wp' ) : esc_html__( 'No', 'seo-wp' ) );
			printf( '<td>%d</td>', (int) $rule['hits'] );
			printf(
				'<td><a href="%1$s">%2$s</a> | <a href="%3$s" onclick="return confirm(\'%4$s\')">%5$s</a></td>',
				esc_url( $edit_url ),
				esc_html__( 'Edit', 'seo-wp' ),
				esc_url( $delete_url ),
				esc_attr__( 'Delete this redirect?', 'seo-wp' ),
				esc_html__( 'Delete', 'seo-wp' )
			);
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Human-readable labels for each redirect type.
	 *
	 * @return array<int,string>
	 */
	private function type_labels(): array {
		return array(
			301 => __( '301 Permanent Redirect', 'seo-wp' ),
			302 => __( '302 Temporary Redirect', 'seo-wp' ),
			307 => __( '307 Temporary Redirect (method preserved)', 'seo-wp' ),
			410 => __( '410 Gone', 'seo-wp' ),
			451 => __( '451 Unavailable For Legal Reasons', 'seo-wp' ),
		);
	}

	/**
	 * Handles the add/edit form submission.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$id = isset( $_POST['id'] ) ? max( 0, (int) $_POST['id'] ) : 0;

		Redirects::save(
			$id,
			array(
				'source'   => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source'] ) ) : '',
				'target'   => isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( (string) $_POST['target'] ) ) : '',
				'type'     => isset( $_POST['type'] ) ? (int) $_POST['type'] : 301,
				'active'   => ! empty( $_POST['active'] ),
				'is_regex' => ! empty( $_POST['is_regex'] ),
			)
		);

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
	 * Handles a delete link click.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		$id = isset( $_GET['id'] ) ? max( 0, (int) $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the ID is needed to build the per-row nonce action checked on the next line; an (int) cast can't carry unsafe data.
		check_admin_referer( self::NONCE_ACTION . '_delete_' . $id );

		Redirects::delete( $id );

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Streams every rule as a downloadable CSV file.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION . '_export' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=seo-wp-redirects.csv' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a generated CSV straight to the browser response, not a local file; there is nothing for WP_Filesystem to do here.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, Redirects::csv_header() );
		foreach ( Redirects::all() as $rule ) {
			fputcsv( $out, array_values( Redirects::to_csv_row( $rule ) ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream opened above, not a local file.
		fclose( $out );

		exit;
	}

	/**
	 * Handles a CSV import: every row with a non-empty source is added as
	 * a new rule (existing rules are never matched/overwritten — a
	 * re-import always appends, so results stay predictable).
	 */
	public function handle_import(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION . '_import', self::NONCE_NAME . '_import' );

		$imported = 0;
		$skipped  = 0;

		$tmp_name = isset( $_FILES['csv_file']['tmp_name'] ) ? (string) $_FILES['csv_file']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- covered by check_admin_referer() above; $_FILES has no slashing to unslash.
		if ( '' !== $tmp_name && is_uploaded_file( $tmp_name ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading the just-validated upload's own temp file directly; WP_Filesystem has no CSV row API and would add nothing here.
			$handle = fopen( $tmp_name, 'r' );
			if ( is_resource( $handle ) ) {
				$header = fgetcsv( $handle );
				if ( is_array( $header ) ) {
					$header = array_map( static fn( $column ): string => strtolower( trim( (string) $column ) ), $header );

					// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- fgetcsv()'s own API: false means end-of-file (a genuine empty row returns array(null)), so the assignment has to happen in the loop condition to tell them apart.
					while ( false !== ( $row = fgetcsv( $handle ) ) ) {
						$assoc = array_combine( $header, array_pad( $row, count( $header ), '' ) );
						$rule  = is_array( $assoc ) ? Redirects::parse_csv_row( $assoc ) : null;

						if ( null === $rule ) {
							++$skipped;
							continue;
						}

						Redirects::save( 0, $rule );
						++$imported;
					}
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the upload's own temp file handle opened above.
				fclose( $handle );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE_SLUG,
					'imported' => $imported,
					'skipped'  => $skipped,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

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
	}

	/**
	 * Renders the redirects page: the add/edit form, then a table of every
	 * existing rule.
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

		$this->render_form( $edit_id, $editing, $prefill_src );
		$this->render_table( $rules );

		echo '</div>';
	}

	/**
	 * Renders the add/edit form.
	 *
	 * @param int                                                                   $edit_id     ID being edited, or 0 for a new rule.
	 * @param array{source:string,target:string,type:int,active:bool,hits:int}|null $editing     The rule being edited, if any.
	 * @param string                                                                $prefill_src Source path to prefill for a new rule (from the 404 monitor).
	 */
	private function render_form( int $edit_id, ?array $editing, string $prefill_src ): void {
		$source = null !== $editing ? $editing['source'] : $prefill_src;
		$target = null !== $editing ? $editing['target'] : '';
		$type   = null !== $editing ? $editing['type'] : 301;
		$active = null === $editing || $editing['active'];

		echo '<h2>' . ( $edit_id > 0 ? esc_html__( 'Edit redirect', 'seo-wp' ) : esc_html__( 'Add redirect', 'seo-wp' ) ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="seo-wp-redirects-form">';
		echo '<input type="hidden" name="action" value="seo_wp_redirects_save" />';
		printf( '<input type="hidden" name="id" value="%d" />', (int) $edit_id );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="form-table" role="presentation"><tbody>';

		printf(
			'<tr><th scope="row"><label for="seo_wp_redirect_source">%1$s</label></th><td><input type="text" id="seo_wp_redirect_source" name="source" value="%2$s" class="regular-text code" placeholder="/old-page/" required /></td></tr>',
			esc_html__( 'Source path', 'seo-wp' ),
			esc_attr( $source )
		);
		printf(
			'<tr><th scope="row"><label for="seo_wp_redirect_target">%1$s</label></th><td><input type="text" id="seo_wp_redirect_target" name="target" value="%2$s" class="regular-text code" placeholder="https://example.com/new-page/" /></td></tr>',
			esc_html__( 'Target URL', 'seo-wp' ),
			esc_attr( $target )
		);

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
	 * Renders the table of existing rules.
	 *
	 * @param array<int,array{source:string,target:string,type:int,active:bool,hits:int}> $rules Every stored rule.
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
			printf( '<td><code>%s</code></td>', esc_html( $rule['source'] ) );
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
				'source' => isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['source'] ) ) : '',
				'target' => isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( (string) $_POST['target'] ) ) : '',
				'type'   => isset( $_POST['type'] ) ? (int) $_POST['type'] : 301,
				'active' => ! empty( $_POST['active'] ),
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
}

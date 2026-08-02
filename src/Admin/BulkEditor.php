<?php
/**
 * Bulk-edit table for SEO fields across all objects.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Meta\Canonical;
use SeoWp\Meta\Robots;
use SeoWp\Meta\TitleDescription;
use WP_Post;
use WP_Query;

/**
 * Renders an editable table of Title/Description/Robots (noindex)/
 * Canonical for every public post type, one form submit updating every
 * changed row. A plain custom table rather than WP_List_Table: that class
 * is built for bulk *actions* (apply one action to selected rows), not
 * bulk *field editing* (many different values across many rows at once).
 */
final class BulkEditor {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-bulk-editor';

	/**
	 * Capability required to use the bulk editor.
	 */
	private const CAPABILITY = 'seo_wp_bulk_edit';

	/**
	 * Rows shown per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Nonce action/field name.
	 */
	private const NONCE_ACTION = 'seo_wp_bulk_editor';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_bulk_editor_nonce';

	/**
	 * Registers the submenu page, the save handler and its assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_seo_wp_bulk_editor_save', array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the Bulk Editor submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Bulk Editor', 'seo-wp' ),
			__( 'Bulk Editor', 'seo-wp' ),
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
	 * Renders the bulk editor page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, not a state-changing action.

		$query = new WP_Query(
			array(
				'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $paged,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);

		echo '<div class="wrap seo-wp-bulk-editor">';
		echo '<h1>' . esc_html__( 'SEO-WP Bulk Editor', 'seo-wp' ) . '</h1>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success banner, not a state-changing action.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Changes saved.', 'seo-wp' ) . '</p></div>';
		}

		if ( array() === $query->posts ) {
			echo '<p>' . esc_html__( 'No content found.', 'seo-wp' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="seo_wp_bulk_editor_save" />';
		printf( '<input type="hidden" name="paged" value="%d" />', (int) $paged );
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<table class="widefat seo-wp-bulk-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Content', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Title', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Canonical', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Robots', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$this->render_row( $post );
			}
		}

		echo '</tbody></table>';

		submit_button( __( 'Save changes', 'seo-wp' ) );
		echo '</form>';

		$this->render_pagination( $paged, (int) $query->max_num_pages );

		echo '</div>';
	}

	/**
	 * Renders one editable row.
	 *
	 * @param WP_Post $post Post to render.
	 */
	private function render_row( WP_Post $post ): void {
		$title       = (string) get_post_meta( $post->ID, TitleDescription::META_TITLE, true );
		$description = (string) get_post_meta( $post->ID, TitleDescription::META_DESCRIPTION, true );
		$canonical   = (string) get_post_meta( $post->ID, Canonical::META_CANONICAL, true );
		$noindex     = (string) get_post_meta( $post->ID, Robots::META_NOINDEX, true );
		$post_type   = get_post_type_object( $post->post_type );
		$edit_link   = (string) get_edit_post_link( $post->ID, 'raw' );

		echo '<tr>';
		printf(
			'<td><strong><a href="%1$s">%2$s</a></strong><br /><span class="description">%3$s</span></td>',
			esc_url( $edit_link ),
			esc_html( get_the_title( $post ) ),
			esc_html( null !== $post_type ? $post_type->labels->singular_name : $post->post_type )
		);
		printf( '<td><input type="text" name="seo_wp_bulk[%1$d][title]" value="%2$s" class="widefat" /></td>', (int) $post->ID, esc_attr( $title ) );
		printf( '<td><textarea name="seo_wp_bulk[%1$d][description]" rows="2" class="widefat">%2$s</textarea></td>', (int) $post->ID, esc_textarea( $description ) );
		printf( '<td><input type="text" name="seo_wp_bulk[%1$d][canonical]" value="%2$s" class="widefat" /></td>', (int) $post->ID, esc_attr( $canonical ) );

		echo '<td>';
		printf( '<select name="seo_wp_bulk[%1$d][noindex]">', (int) $post->ID );
		foreach (
			array(
				''  => __( 'Default', 'seo-wp' ),
				'0' => __( 'Index', 'seo-wp' ),
				'1' => __( 'Noindex', 'seo-wp' ),
			) as $value => $label
		) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $noindex, $value, false ), esc_html( $label ) );
		}
		echo '</select></td>';
		echo '</tr>';
	}

	/**
	 * Handles the bulk save form submission.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seo-wp' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_NAME );

		$rows = isset( $_POST['seo_wp_bulk'] ) && is_array( $_POST['seo_wp_bulk'] ) ? wp_unslash( $_POST['seo_wp_bulk'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- wp_unslash() is called here; sanitized field-by-field below.

		foreach ( $rows as $post_id => $fields ) {
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || ! is_array( $fields ) || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$this->save_field( $post_id, TitleDescription::META_TITLE, isset( $fields['title'] ) ? sanitize_text_field( (string) $fields['title'] ) : '' );
			$this->save_field( $post_id, TitleDescription::META_DESCRIPTION, isset( $fields['description'] ) ? sanitize_textarea_field( (string) $fields['description'] ) : '' );
			$this->save_field( $post_id, Canonical::META_CANONICAL, isset( $fields['canonical'] ) ? sanitize_text_field( (string) $fields['canonical'] ) : '' );

			$noindex = isset( $fields['noindex'] ) ? sanitize_text_field( (string) $fields['noindex'] ) : '';
			$this->save_field( $post_id, Robots::META_NOINDEX, in_array( $noindex, array( '0', '1' ), true ) ? $noindex : '' );
		}

		$paged = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SLUG,
					'paged'   => $paged,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Writes (or clears) a single post meta value.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param string $value   Value to store; an empty string deletes the meta instead.
	 */
	private function save_field( int $post_id, string $key, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $post_id, $key );
			return;
		}

		update_post_meta( $post_id, $key, $value );
	}

	/**
	 * Renders simple prev/next pagination links.
	 *
	 * @param int $current     Current page.
	 * @param int $total_pages Total number of pages.
	 */
	private function render_pagination( int $current, int $total_pages ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$links = paginate_links(
			array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $current,
				'total'   => $total_pages,
			)
		);

		if ( is_string( $links ) && '' !== $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . $links . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns pre-escaped markup.
		}
	}
}

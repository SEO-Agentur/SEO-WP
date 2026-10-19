<?php
/**
 * Internal link suggestions in the block editor.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Linking\Suggester;
use Seooptimierung\Toolkit\Support\Ajax;
use WP_Post;

/**
 * Loads the "Link suggestions" panel into the block editor and answers its
 * requests (see Linking\Suggester for how pages are chosen). Finding pages
 * that nothing links to is the Link Audit's job; this adds the other half —
 * proposing where to link from while writing.
 */
final class LinkSuggestions {

	/**
	 * Nonce action.
	 */
	private const NONCE_ACTION = 'seo_wp_link_suggestions';

	/**
	 * Registers the editor assets and the AJAX handler.
	 */
	public function register(): void {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'wp_ajax_seo_wp_link_suggestions', array( $this, 'handle_suggestions_ajax' ) );
	}

	/**
	 * Loads the panel script in the post editor.
	 */
	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_script(
			'seo-wp-link-suggestions',
			plugins_url( 'assets/admin/link-suggestions.js', \Seooptimierung\Toolkit\FILE ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-i18n' ),
			\Seooptimierung\Toolkit\VERSION,
			true
		);
		wp_set_script_translations( 'seo-wp-link-suggestions', 'seooptimierung-toolkit', \Seooptimierung\Toolkit\DIR . '/languages' );
		wp_localize_script(
			'seo-wp-link-suggestions',
			'seoWpLinkSuggestions',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			)
		);
	}

	/**
	 * Returns link suggestions for the post being edited.
	 */
	public function handle_suggestions_ajax(): void {
		Ajax::begin( 'internal-links:suggest' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$post    = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$html  = isset( $_POST['content'] ) ? substr( (string) wp_unslash( $_POST['content'] ), 0, 300000 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only analysed as text, never stored or printed.
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';

		Ajax::success( array( 'suggestions' => Suggester::suggest( $post, $html, $title ) ) );
	}
}

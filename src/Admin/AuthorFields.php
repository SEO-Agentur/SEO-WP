<?php
/**
 * Per-author "noindex this author's archive" checkbox on the user profile.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Meta\Robots;

/**
 * Meta\Robots already honours the user meta this writes (an author archive
 * is noindexed when it is '1'); until now only the Yoast/Rank Math
 * importers could set it. This adds the missing field to the profile
 * screen, shown to anyone who may edit that profile.
 */
final class AuthorFields {

	/**
	 * Nonce action for the profile field.
	 */
	private const NONCE_ACTION = 'seo_wp_author_noindex';

	/**
	 * Registers the profile-screen field and its save handlers.
	 */
	public function register(): void {
		add_action( 'show_user_profile', array( $this, 'render' ) );
		add_action( 'edit_user_profile', array( $this, 'render' ) );
		add_action( 'personal_options_update', array( $this, 'save' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save' ) );
	}

	/**
	 * Renders the checkbox.
	 *
	 * @param \WP_User $user User whose profile is being edited.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		$noindex = '1' === (string) get_user_meta( $user->ID, Robots::META_AUTHOR_NOINDEX, true );

		echo '<h2>' . esc_html__( 'Seooptimierung Toolkit', 'seooptimierung-toolkit' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tr>';
		echo '<th scope="row">' . esc_html__( 'Author archive', 'seooptimierung-toolkit' ) . '</th><td>';
		wp_nonce_field( self::NONCE_ACTION, 'seo_wp_author_noindex_nonce' );
		printf(
			'<label for="seo_wp_author_noindex"><input type="checkbox" id="seo_wp_author_noindex" name="seo_wp_author_noindex" value="1" %s /> %s</label>',
			checked( $noindex, true, false ),
			esc_html__( 'Noindex this author\'s archive page', 'seooptimierung-toolkit' )
		);
		echo '<p class="description">' . esc_html__( 'Keeps the page listing this author\'s posts out of search results. Useful for single-author sites or accounts that only exist for technical reasons.', 'seooptimierung-toolkit' ) . '</p>';
		echo '</td></tr></table>';
	}

	/**
	 * Saves the checkbox.
	 *
	 * @param int $user_id User being saved.
	 */
	public function save( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! current_user_can( 'edit_user', $user_id )
			|| ! isset( $_POST['seo_wp_author_noindex_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['seo_wp_author_noindex_nonce'] ) ), self::NONCE_ACTION )
		) {
			return;
		}

		if ( ! empty( $_POST['seo_wp_author_noindex'] ) ) {
			update_user_meta( $user_id, Robots::META_AUTHOR_NOINDEX, '1' );
		} else {
			delete_user_meta( $user_id, Robots::META_AUTHOR_NOINDEX );
		}
	}
}

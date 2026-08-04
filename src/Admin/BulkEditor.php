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
use SeoWp\Support\AiClient;
use SeoWp\Support\Ajax;
use SeoWp\Support\ContentRenderer;
use SeoWp\Support\DeepLClient;
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
		add_action( 'wp_ajax_seo_wp_bulk_ai_suggest', array( $this, 'handle_ai_suggest_ajax' ) );
		add_action( 'wp_ajax_seo_wp_bulk_ai_translate', array( $this, 'handle_ai_translate_ajax' ) );
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
		wp_enqueue_script( 'seo-wp-bulk-editor-ai', plugins_url( 'assets/admin/bulk-editor-ai.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		$settings = get_option( 'seo_wp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$providers = array();
		if ( ! empty( $settings['ai_anthropic_key'] ) ) {
			$providers['anthropic'] = __( 'Claude', 'seo-wp' );
		}
		if ( ! empty( $settings['ai_openai_key'] ) ) {
			$providers['openai'] = __( 'OpenAI', 'seo-wp' );
		}

		wp_localize_script(
			'seo-wp-bulk-editor-ai',
			'seoWpBulkAi',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( self::NONCE_ACTION ),
				'suggestAction'   => 'seo_wp_bulk_ai_suggest',
				'translateAction' => 'seo_wp_bulk_ai_translate',
				'providers'       => $providers,
				'deeplConfigured' => ! empty( $settings['ai_deepl_key'] ),
				'wpmlActive'      => has_filter( 'wpml_object_id' ),
				'i18n'            => array(
					'suggesting'  => __( 'Asking AI…', 'seo-wp' ),
					'translating' => __( 'Translating…', 'seo-wp' ),
					'error'       => __( 'Something went wrong.', 'seo-wp' ),
					'done'        => __( 'Done.', 'seo-wp' ),
					'noProviders' => __( 'No AI provider configured — add a key on the AI Settings page.', 'seo-wp' ),
					'noDeepl'     => __( 'No DeepL API key configured — add one on the AI Settings page.', 'seo-wp' ),
					'noWpml'      => __( 'WPML is not active.', 'seo-wp' ),
				),
			)
		);
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

		echo '<p>';
		echo '<button type="button" class="button seo-wp-bulk-ai-suggest-empty">' . esc_html__( 'Suggest AI titles/descriptions for empty fields on this page', 'seo-wp' ) . '</button> ';
		echo '<span class="seo-wp-bulk-ai-suggest-empty-status"></span>';
		echo '</p>';

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
		echo '<th>' . esc_html__( 'AI', 'seo-wp' ) . '</th>';
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

		echo '<tr class="seo-wp-bulk-row" data-seo-wp-post-id="' . (int) $post->ID . '">';
		printf(
			'<td><strong><a href="%1$s">%2$s</a></strong><br /><span class="description">%3$s</span></td>',
			esc_url( $edit_link ),
			esc_html( get_the_title( $post ) ),
			esc_html( null !== $post_type ? $post_type->labels->singular_name : $post->post_type )
		);
		printf( '<td><input type="text" name="seo_wp_bulk[%1$d][title]" value="%2$s" class="widefat seo-wp-bulk-ai-title" /></td>', (int) $post->ID, esc_attr( $title ) );
		printf( '<td><textarea name="seo_wp_bulk[%1$d][description]" rows="2" class="widefat seo-wp-bulk-ai-description">%2$s</textarea></td>', (int) $post->ID, esc_textarea( $description ) );
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

		echo '<td class="seo-wp-bulk-ai-cell">';
		echo '<select class="seo-wp-bulk-ai-provider"></select> ';
		echo '<button type="button" class="button seo-wp-bulk-ai-suggest">' . esc_html__( 'Suggest', 'seo-wp' ) . '</button> ';
		echo '<button type="button" class="button seo-wp-bulk-ai-translate">' . esc_html__( 'Translate', 'seo-wp' ) . '</button>';
		echo '<div class="seo-wp-bulk-ai-status"></div>';
		echo '</td>';

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
	 * Handles a "Suggest" click: asks the chosen AI provider for a
	 * title/description pair, in this plugin's "{KW}: {USP} | {USP} >
	 * {CTA}" template. Returns the suggestion for the browser to drop
	 * into the row's fields — this never writes anything itself; only the
	 * normal "Save changes" submit persists it.
	 */
	public function handle_ai_suggest_ajax(): void {
		Ajax::begin( 'bulk-editor:ai-suggest' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$post_id  = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			Ajax::error( array( 'message' => __( 'Unknown post.', 'seo-wp' ) ), 400 );
		}

		$settings = get_option( 'seo_wp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$prompt   = self::build_ai_prompt( $post );

		if ( 'anthropic' === $provider ) {
			$key = isset( $settings['ai_anthropic_key'] ) ? (string) $settings['ai_anthropic_key'] : '';
			if ( '' === $key ) {
				Ajax::error( array( 'message' => __( 'No Anthropic API key configured.', 'seo-wp' ) ), 400 );
			}
			$reply = AiClient::anthropic( $key, $prompt, isset( $settings['ai_anthropic_model'] ) ? (string) $settings['ai_anthropic_model'] : '' );
		} elseif ( 'openai' === $provider ) {
			$key = isset( $settings['ai_openai_key'] ) ? (string) $settings['ai_openai_key'] : '';
			if ( '' === $key ) {
				Ajax::error( array( 'message' => __( 'No OpenAI API key configured.', 'seo-wp' ) ), 400 );
			}
			$reply = AiClient::openai( $key, $prompt, isset( $settings['ai_openai_model'] ) ? (string) $settings['ai_openai_model'] : '' );
		} else {
			Ajax::error( array( 'message' => __( 'Unknown provider.', 'seo-wp' ) ), 400 );
		}

		if ( is_wp_error( $reply ) ) {
			Ajax::error( array( 'message' => $reply->get_error_message() ) );
		}

		$parsed = AiClient::parse_title_description_reply( $reply );
		if ( null === $parsed ) {
			Ajax::error( array( 'message' => __( 'Could not parse the AI reply.', 'seo-wp' ) ) );
		}

		Ajax::success( $parsed );
	}

	/**
	 * Builds the generation prompt for a post: its URL, current title,
	 * focus keyword (if set — otherwise the model is asked to infer one),
	 * and a plain-text excerpt of its actual rendered content.
	 *
	 * @param WP_Post $post Post to build a prompt for.
	 */
	private static function build_ai_prompt( WP_Post $post ): string {
		$url           = (string) get_permalink( $post );
		$focus_keyword = (string) get_post_meta( $post->ID, Metabox::META_FOCUS_KEYWORD, true );

		$excerpt = wp_strip_all_tags( ContentRenderer::render( $post ) );
		$excerpt = trim( (string) preg_replace( '/\s+/', ' ', $excerpt ) );
		$excerpt = mb_substr( $excerpt, 0, 1200 );

		$keyword_line = '' !== $focus_keyword
			? 'Focus keyword (use this exactly): ' . $focus_keyword
			: 'No focus keyword given — derive one short keyword from the URL and content below.';

		return "You write SEO title tags and meta descriptions for a web page, in a strict template format, in the same language as the page content below.\n\n"
			. 'Page URL: ' . $url . "\n"
			. 'Page title: ' . $post->post_title . "\n"
			. $keyword_line . "\n"
			. 'Page content excerpt: ' . $excerpt . "\n\n"
			. "Identify 2 short unique selling points (USPs) for the title and 3 for the description, plus one short call to action (CTA). Each USP/CTA should be 1-4 words.\n\n"
			. "Respond with ONLY a JSON object, no other text, no markdown code fences:\n"
			. '{"title": "KW: USP1 | USP2 > CTA", "description": "KW: USP1 | USP2 | USP3 > CTA"}' . "\n\n"
			. "Rules:\n"
			. "- Replace KW, USP1, USP2, USP3 and CTA with real content — never output the literal placeholder words.\n"
			. "- Title: keep the entire string under 60 characters.\n"
			. "- Description: keep the entire string under 155 characters.\n"
			. '- Use exactly this punctuation: a colon after the keyword, pipe characters between USPs, and ">" before the CTA.';
	}

	/**
	 * Handles a "Translate" click: pushes the row's current title/
	 * description through DeepL into every other WPML-active language
	 * that already has a translation of this post, writing directly into
	 * that translation's own title/description meta. Distinct from the
	 * AI-suggest flow: this always writes immediately (it targets other
	 * posts entirely, which aren't part of the current row's form), never
	 * deferred to "Save changes".
	 */
	public function handle_ai_translate_ajax(): void {
		Ajax::begin( 'bulk-editor:ai-translate' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seo-wp' ) ), 403 );
		}

		$post_id     = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['description'] ) ) : '';

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			Ajax::error( array( 'message' => __( 'Unknown post.', 'seo-wp' ) ), 400 );
		}

		if ( '' === $title && '' === $description ) {
			Ajax::error( array( 'message' => __( 'Nothing to translate — fill in a title or description first.', 'seo-wp' ) ), 400 );
		}

		$settings = get_option( 'seo_wp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$key      = isset( $settings['ai_deepl_key'] ) ? (string) $settings['ai_deepl_key'] : '';
		if ( '' === $key ) {
			Ajax::error( array( 'message' => __( 'No DeepL API key configured.', 'seo-wp' ) ), 400 );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filters, not ones of ours.
		if ( ! has_filter( 'wpml_active_languages' ) || ! has_filter( 'wpml_object_id' ) ) {
			Ajax::error( array( 'message' => __( 'WPML is not active.', 'seo-wp' ) ), 400 );
		}

		$current_lang = '';
		if ( has_filter( 'wpml_element_language_code' ) ) {
			$language_args = array(
				'element_id'   => $post_id,
				'element_type' => $post->post_type,
			);
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$current_lang = (string) apply_filters( 'wpml_element_language_code', null, $language_args );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$languages = is_array( $languages ) ? $languages : array();

		$translated = array();
		$errors     = array();

		foreach ( array_keys( $languages ) as $lang_code ) {
			$lang_code = (string) $lang_code;
			if ( $lang_code === $current_lang ) {
				continue;
			}

			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$translated_id = apply_filters( 'wpml_object_id', $post_id, $post->post_type, false, $lang_code );
			if ( ! is_numeric( $translated_id ) || (int) $translated_id <= 0 ) {
				continue;
			}
			$translated_id = (int) $translated_id;

			if ( '' !== $title ) {
				$result = DeepLClient::translate( $key, $title, $lang_code );
				if ( is_wp_error( $result ) ) {
					$errors[] = $lang_code . ': ' . $result->get_error_message();
				} else {
					update_post_meta( $translated_id, TitleDescription::META_TITLE, $result );
				}
			}

			if ( '' !== $description ) {
				$result = DeepLClient::translate( $key, $description, $lang_code );
				if ( is_wp_error( $result ) ) {
					$errors[] = $lang_code . ': ' . $result->get_error_message();
				} else {
					update_post_meta( $translated_id, TitleDescription::META_DESCRIPTION, $result );
				}
			}

			$translated[] = $lang_code;
		}

		Ajax::success(
			array(
				'translated' => $translated,
				'errors'     => $errors,
			)
		);
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

<?php
/**
 * Per-object SEO metabox (General/Social/Schema tabs).
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use SeoWp\Meta\Canonical;
use SeoWp\Meta\Robots;
use SeoWp\Meta\TitleDescription;
use SeoWp\Schema\GraphBuilder;
use SeoWp\Schema\Nodes\FaqPage;
use SeoWp\Schema\Nodes\Product;
use SeoWp\Social\OpenGraph;
use SeoWp\Social\TwitterCards;
use SeoWp\Support\LengthAdvisor;
use WP_Post;
use WP_Taxonomy;
use WP_Term;

/**
 * Renders the tabbed General/Social/Schema fields on post edit screens
 * (via a metabox) and General/Social fields on term edit screens (via
 * the taxonomy edit-form hooks — WordPress has no metabox API for terms),
 * for any public post type or taxonomy without hardcoding type names.
 */
final class Metabox {

	/**
	 * Nonce action/field name shared by the post metabox and term fields.
	 */
	private const NONCE_ACTION = 'seo_wp_metabox';

	/**
	 * Nonce field name.
	 */
	private const NONCE_NAME = 'seo_wp_metabox_nonce';

	/**
	 * Capability (granted per role via RoleManager) required to see or
	 * save any field this metabox renders.
	 */
	private const EDIT_META_CAP = 'seo_wp_edit_meta';

	/**
	 * Post meta key for the optional focus keyword hint. Not a scoring
	 * input — this plugin deliberately has no SEO-score feature — only a
	 * strong hint the Bulk Editor's AI title/description suggestions use
	 * when present, falling back to inferring one from the URL and
	 * content when it's empty.
	 */
	public const META_FOCUS_KEYWORD = '_seo_wp_focus_keyword';

	/**
	 * Advanced robots directive keys editable here.
	 *
	 * @var string[]
	 */
	private const ADVANCED_DIRECTIVE_KEYS = array( 'noarchive', 'nosnippet', 'noimageindex' );

	/**
	 * Translated labels for the advanced robots directives. A method
	 * rather than a class constant, since constant expressions can't
	 * call __().
	 *
	 * @return array<string,string>
	 */
	private static function advanced_directive_labels(): array {
		return array(
			'noarchive'    => __( 'No archive', 'seo-wp' ),
			'nosnippet'    => __( 'No snippet', 'seo-wp' ),
			'noimageindex' => __( 'No image index', 'seo-wp' ),
		);
	}

	/**
	 * Registers the post metabox, the term fields for every public
	 * taxonomy, and the shared admin assets.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_post_metabox' ) );
		add_action( 'save_post', array( $this, 'save_post' ) );

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			add_action( "{$taxonomy->name}_edit_form_fields", array( $this, 'render_term_fields' ) );
			add_action(
				"edited_{$taxonomy->name}",
				function ( int $term_id ) use ( $taxonomy ): void {
					$this->save_term( $term_id, $taxonomy );
				}
			);
		}

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Registers the metabox on every public post type.
	 */
	public function add_post_metabox(): void {
		if ( ! current_user_can( self::EDIT_META_CAP ) ) {
			return;
		}

		foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
			add_meta_box(
				'seo_wp_metabox',
				__( 'SEO-WP', 'seo-wp' ),
				array( $this, 'render_post_metabox' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Loads the shared admin assets on post-edit and term-edit screens
	 * only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php', 'term.php', 'edit-tags.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \SeoWp\FILE ), array(), \SeoWp\VERSION );
		wp_enqueue_script( 'seo-wp-metabox', plugins_url( 'assets/admin/metabox.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );

		wp_localize_script(
			'seo-wp-metabox',
			'seoWpMetabox',
			array(
				'i18n'        => array(
					'question' => __( 'Question', 'seo-wp' ),
					'answer'   => __( 'Answer', 'seo-wp' ),
					'remove'   => __( 'Remove', 'seo-wp' ),
					'noImage'  => __( 'No image set', 'seo-wp' ),
				),
				'preview'     => array(
					'siteName'  => (string) get_bloginfo( 'name' ),
					'separator' => (string) $this->separator_setting(),
					'domain'    => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
				),
				'lengthBands' => LengthAdvisor::bands(),
			)
		);

		$this->maybe_enqueue_sidebar( $hook_suffix );
	}

	/**
	 * Loads the block-editor sidebar panel script — post-edit screens
	 * only, since PluginSidebar is a block-editor concept with nothing
	 * to attach to on a term-edit screen. Registered as a separate
	 * script (not folded into metabox.js) so term-edit screens never
	 * load the wp-edit-post/wp-plugins/wp-element dependencies they'd
	 * otherwise pull in for nothing.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	private function maybe_enqueue_sidebar( string $hook_suffix ): void {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'seo-wp-editor-sidebar',
			plugins_url( 'assets/admin/editor-sidebar.js', \SeoWp\FILE ),
			array( 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'seo-wp-metabox' ),
			\SeoWp\VERSION,
			true
		);
	}

	/**
	 * Reads the configured title separator for the live preview, without
	 * pulling in the full Settings class just for one field.
	 */
	private function separator_setting(): string {
		$settings = get_option( 'seo_wp_settings', array() );

		return isset( $settings['separator'] ) && '' !== $settings['separator'] ? (string) $settings['separator'] : '-';
	}

	/**
	 * Renders the metabox on a post edit screen.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_post_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$faq        = get_post_meta( $post->ID, FaqPage::META_FAQ, true );
		$is_product = 'product' === $post->post_type && function_exists( 'wc_get_product' );

		$this->render_tabs( $this->read_values( $post->ID, true ), is_array( $faq ) ? $faq : array(), true, $is_product );
	}

	/**
	 * Renders the fields on a term edit screen.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function render_term_fields( WP_Term $term ): void {
		if ( ! current_user_can( self::EDIT_META_CAP ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'SEO-WP', 'seo-wp' ) . '</th><td>';
		$this->render_tabs( $this->read_values( $term->term_id, false ), array(), false, false );
		echo '</td></tr>';
	}

	/**
	 * Saves the metabox fields for a post.
	 *
	 * @param int $post_id Post being saved.
	 */
	public function save_post( int $post_id ): void {
		if ( ! $this->has_valid_nonce() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( self::EDIT_META_CAP ) ) {
			return;
		}

		$this->save_common_fields( $post_id, true );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified by has_valid_nonce() above; sanitized field-by-field in sanitize_faq().
		$questions = isset( $_POST['seo_wp_faq_question'] ) && is_array( $_POST['seo_wp_faq_question'] ) ? wp_unslash( $_POST['seo_wp_faq_question'] ) : array();
		$answers   = isset( $_POST['seo_wp_faq_answer'] ) && is_array( $_POST['seo_wp_faq_answer'] ) ? wp_unslash( $_POST['seo_wp_faq_answer'] ) : array();
		// phpcs:enable

		$this->update_meta( $post_id, true, FaqPage::META_FAQ, $this->sanitize_faq( $questions, $answers ) );
	}

	/**
	 * Saves the fields for a term.
	 *
	 * @param int         $term_id  Term being saved.
	 * @param WP_Taxonomy $taxonomy Taxonomy the term belongs to.
	 */
	private function save_term( int $term_id, WP_Taxonomy $taxonomy ): void {
		if ( ! $this->has_valid_nonce() || ! current_user_can( $taxonomy->cap->edit_terms ) || ! current_user_can( self::EDIT_META_CAP ) ) {
			return;
		}

		$this->save_common_fields( $term_id, false );
	}

	/**
	 * Whether the shared metabox nonce is present and valid.
	 */
	private function has_valid_nonce(): bool {
		return isset( $_POST[ self::NONCE_NAME ] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION );
	}

	/**
	 * Reads the current values for the fields shared by posts and terms.
	 *
	 * @param int  $object_id Post or term ID.
	 * @param bool $is_post   Whether $object_id is a post ID (vs. a term ID).
	 * @return array<string,mixed>
	 */
	private function read_values( int $object_id, bool $is_post ): array {
		$keys = array(
			TitleDescription::META_TITLE,
			TitleDescription::META_DESCRIPTION,
			self::META_FOCUS_KEYWORD,
			Canonical::META_CANONICAL,
			Robots::META_NOINDEX,
			Robots::META_NOFOLLOW,
			OpenGraph::META_TITLE,
			OpenGraph::META_DESCRIPTION,
			OpenGraph::META_IMAGE,
			TwitterCards::META_TITLE,
			TwitterCards::META_DESCRIPTION,
			TwitterCards::META_IMAGE,
			GraphBuilder::META_SCHEMA_TYPE,
			Product::META_BRAND,
			Product::META_GTIN,
			Product::META_MPN,
			Product::META_PRICE_VALID_UNTIL,
		);

		$values = array();
		foreach ( $keys as $key ) {
			$values[ $key ] = $is_post
				? (string) get_post_meta( $object_id, $key, true )
				: (string) get_term_meta( $object_id, $key, true );
		}

		$advanced                        = $is_post
			? get_post_meta( $object_id, Robots::META_ADVANCED, true )
			: get_term_meta( $object_id, Robots::META_ADVANCED, true );
		$values[ Robots::META_ADVANCED ] = is_array( $advanced ) ? $advanced : array();

		return $values;
	}

	/**
	 * Saves the fields shared by posts and terms.
	 *
	 * @param int  $object_id Post or term ID.
	 * @param bool $is_post   Whether $object_id is a post ID (vs. a term ID).
	 */
	private function save_common_fields( int $object_id, bool $is_post ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce already verified by has_valid_nonce() in save_post()/save_term() before this is called.
		$text_keys = array(
			TitleDescription::META_TITLE,
			TitleDescription::META_DESCRIPTION,
			self::META_FOCUS_KEYWORD,
			OpenGraph::META_TITLE,
			OpenGraph::META_DESCRIPTION,
			OpenGraph::META_IMAGE,
			TwitterCards::META_TITLE,
			TwitterCards::META_DESCRIPTION,
			TwitterCards::META_IMAGE,
			Product::META_BRAND,
			Product::META_GTIN,
			Product::META_MPN,
		);

		foreach ( $text_keys as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$this->update_meta( $object_id, $is_post, $key, $value );
		}

		$canonical_override = isset( $_POST[ Canonical::META_CANONICAL ] ) ? sanitize_text_field( wp_unslash( $_POST[ Canonical::META_CANONICAL ] ) ) : '';
		$this->update_meta( $object_id, $is_post, Canonical::META_CANONICAL, Canonical::sanitize_override( $canonical_override ) );

		$price_valid_until = isset( $_POST[ Product::META_PRICE_VALID_UNTIL ] ) ? sanitize_text_field( wp_unslash( $_POST[ Product::META_PRICE_VALID_UNTIL ] ) ) : '';
		$this->update_meta( $object_id, $is_post, Product::META_PRICE_VALID_UNTIL, Product::normalise_price_valid_until( $price_valid_until ) );

		$noindex = isset( $_POST[ Robots::META_NOINDEX ] ) ? sanitize_text_field( wp_unslash( $_POST[ Robots::META_NOINDEX ] ) ) : '';
		$this->update_meta( $object_id, $is_post, Robots::META_NOINDEX, in_array( $noindex, array( '0', '1' ), true ) ? $noindex : '' );

		$this->update_meta( $object_id, $is_post, Robots::META_NOFOLLOW, empty( $_POST[ Robots::META_NOFOLLOW ] ) ? '' : '1' );

		$advanced_raw = isset( $_POST[ Robots::META_ADVANCED ] ) && is_array( $_POST[ Robots::META_ADVANCED ] )
			? array_map( 'sanitize_key', wp_unslash( $_POST[ Robots::META_ADVANCED ] ) )
			: array();
		$this->update_meta( $object_id, $is_post, Robots::META_ADVANCED, array_values( array_intersect( self::ADVANCED_DIRECTIVE_KEYS, $advanced_raw ) ) );

		$schema_type = isset( $_POST[ GraphBuilder::META_SCHEMA_TYPE ] ) ? sanitize_text_field( wp_unslash( $_POST[ GraphBuilder::META_SCHEMA_TYPE ] ) ) : '';
		$this->update_meta( $object_id, $is_post, GraphBuilder::META_SCHEMA_TYPE, GraphBuilder::is_valid_schema_type( $schema_type ) ? $schema_type : '' );
		// phpcs:enable
	}

	/**
	 * Writes (or clears) a single post/term meta value.
	 *
	 * @param int          $object_id Post or term ID.
	 * @param bool         $is_post   Whether $object_id is a post ID (vs. a term ID).
	 * @param string       $key       Meta key.
	 * @param string|array $value     Value to store; an empty string/array deletes the meta instead.
	 */
	private function update_meta( int $object_id, bool $is_post, string $key, $value ): void {
		if ( '' === $value || array() === $value ) {
			$is_post ? delete_post_meta( $object_id, $key ) : delete_term_meta( $object_id, $key );
			return;
		}

		if ( $is_post ) {
			update_post_meta( $object_id, $key, $value );
		} else {
			update_term_meta( $object_id, $key, $value );
		}
	}

	/**
	 * Zips submitted FAQ question/answer arrays into valid entries,
	 * dropping any pair missing either half.
	 *
	 * @param string[] $questions Submitted questions.
	 * @param string[] $answers   Submitted answers.
	 * @return array<int,array{question:string,answer:string}>
	 */
	private function sanitize_faq( array $questions, array $answers ): array {
		$faq = array();

		foreach ( $questions as $index => $question ) {
			$question = sanitize_text_field( (string) $question );
			$answer   = isset( $answers[ $index ] ) ? sanitize_textarea_field( (string) $answers[ $index ] ) : '';

			if ( '' === $question || '' === $answer ) {
				continue;
			}

			$faq[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $faq;
	}

	/**
	 * Renders the tab navigation and panels. The Schema tab (type override)
	 * is shown for both posts and terms; the FAQ repeater within it is
	 * posts-only, since FaqPage only ever builds a node for singular
	 * content.
	 *
	 * @param array<string,mixed>                             $values     Field values, keyed by meta key.
	 * @param array<int,array{question:string,answer:string}> $faq        FAQ entries (posts only, empty for terms).
	 * @param bool                                            $show_faq   Whether to render the FAQ repeater (posts only).
	 * @param bool                                            $is_product Whether the object being edited is a WooCommerce product.
	 */
	private function render_tabs( array $values, array $faq, bool $show_faq, bool $is_product ): void {
		echo '<div class="seo-wp-metabox">';

		echo '<div class="seo-wp-metabox-tabs">';
		echo '<button type="button" class="seo-wp-tab-button seo-wp-tab-active" data-seo-wp-tab="general">' . esc_html__( 'General', 'seo-wp' ) . '</button>';
		echo '<button type="button" class="seo-wp-tab-button" data-seo-wp-tab="social">' . esc_html__( 'Social', 'seo-wp' ) . '</button>';
		echo '<button type="button" class="seo-wp-tab-button" data-seo-wp-tab="schema">' . esc_html__( 'Schema', 'seo-wp' ) . '</button>';
		echo '</div>';

		echo '<div class="seo-wp-tab-panel seo-wp-tab-active" data-seo-wp-panel="general">';
		$this->field_text( TitleDescription::META_TITLE, __( 'Title', 'seo-wp' ), (string) $values[ TitleDescription::META_TITLE ], 'title' );
		$this->field_textarea( TitleDescription::META_DESCRIPTION, __( 'Meta description', 'seo-wp' ), (string) $values[ TitleDescription::META_DESCRIPTION ], 'description' );
		$this->field_text( self::META_FOCUS_KEYWORD, __( 'Focus keyword (optional)', 'seo-wp' ), (string) $values[ self::META_FOCUS_KEYWORD ] );
		$this->field_text( Canonical::META_CANONICAL, __( 'Canonical URL', 'seo-wp' ), (string) $values[ Canonical::META_CANONICAL ] );
		$this->field_robots( $values );
		$this->render_serp_preview();
		echo '</div>';

		echo '<div class="seo-wp-tab-panel" data-seo-wp-panel="social">';
		$this->field_text( OpenGraph::META_TITLE, __( 'Open Graph title', 'seo-wp' ), (string) $values[ OpenGraph::META_TITLE ] );
		$this->field_textarea( OpenGraph::META_DESCRIPTION, __( 'Open Graph description', 'seo-wp' ), (string) $values[ OpenGraph::META_DESCRIPTION ] );
		$this->field_text( OpenGraph::META_IMAGE, __( 'Open Graph image URL', 'seo-wp' ), (string) $values[ OpenGraph::META_IMAGE ] );
		$this->field_text( TwitterCards::META_TITLE, __( 'Twitter title', 'seo-wp' ), (string) $values[ TwitterCards::META_TITLE ] );
		$this->field_textarea( TwitterCards::META_DESCRIPTION, __( 'Twitter description', 'seo-wp' ), (string) $values[ TwitterCards::META_DESCRIPTION ] );
		$this->field_text( TwitterCards::META_IMAGE, __( 'Twitter image URL', 'seo-wp' ), (string) $values[ TwitterCards::META_IMAGE ] );
		$this->render_social_preview();
		echo '</div>';

		echo '<div class="seo-wp-tab-panel" data-seo-wp-panel="schema">';
		$this->field_schema_type( (string) $values[ GraphBuilder::META_SCHEMA_TYPE ] );
		if ( $is_product ) {
			$this->field_product( $values );
		}
		if ( $show_faq ) {
			$this->field_faq( $faq );
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Renders a single-line text field, optionally with a live length
	 * traffic-light badge (metabox.js fills in/updates it; $length_field
	 * is a LengthAdvisor::bands() key, e.g. 'title').
	 *
	 * @param string $name         Field name (also the meta key).
	 * @param string $label        Field label.
	 * @param string $value        Current value.
	 * @param string $length_field LengthAdvisor field key for the badge, or '' for none.
	 */
	private function field_text( string $name, string $label, string $value, string $length_field = '' ): void {
		printf(
			'<p class="seo-wp-field"><label for="seo-wp-%1$s">%2$s</label>%3$s<input type="text" id="seo-wp-%1$s" name="%1$s" value="%4$s" class="widefat" /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$this->length_badge_markup( $length_field, mb_strlen( $value ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- length_badge_markup() escapes every attribute itself.
			esc_attr( $value )
		);
	}

	/**
	 * Renders a multi-line textarea field, same length-badge option as
	 * field_text().
	 *
	 * @param string $name         Field name (also the meta key).
	 * @param string $label        Field label.
	 * @param string $value        Current value.
	 * @param string $length_field LengthAdvisor field key for the badge, or '' for none.
	 */
	private function field_textarea( string $name, string $label, string $value, string $length_field = '' ): void {
		printf(
			'<p class="seo-wp-field"><label for="seo-wp-%1$s">%2$s</label>%3$s<textarea id="seo-wp-%1$s" name="%1$s" rows="3" class="widefat">%4$s</textarea></p>',
			esc_attr( $name ),
			esc_html( $label ),
			$this->length_badge_markup( $length_field, mb_strlen( $value ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- length_badge_markup() escapes every attribute itself.
			esc_textarea( $value )
		);
	}

	/**
	 * Builds the length-badge <span> markup for field_text()/
	 * field_textarea(), or '' when the field has no length field.
	 * metabox.js re-renders this live as the field is typed into; this is
	 * only the pre-JS/initial state (same split as BulkEditor's own
	 * length_badge()).
	 *
	 * @param string $length_field LengthAdvisor field key, or ''.
	 * @param int    $length       Current character count.
	 */
	private function length_badge_markup( string $length_field, int $length ): string {
		if ( '' === $length_field ) {
			return '';
		}

		return sprintf(
			'<span class="seo-wp-length-badge is-%1$s" data-seo-wp-length-field="%2$s">%3$d</span>',
			esc_attr( LengthAdvisor::status( $length_field, $length ) ),
			esc_attr( $length_field ),
			(int) $length
		);
	}

	/**
	 * Renders the container for the live Google search-result preview.
	 * Content is filled in and kept in sync by metabox.js as the title/
	 * description fields (or their fallbacks — the post title/excerpt) are
	 * typed; there is nothing to render server-side since it must react to
	 * unsaved edits.
	 */
	private function render_serp_preview(): void {
		echo '<div class="seo-wp-field seo-wp-serp-preview">';
		echo '<span class="seo-wp-preview-label">' . esc_html__( 'Search result preview', 'seo-wp' ) . '</span>';
		echo '<div class="seo-wp-serp-box">';
		echo '<div class="seo-wp-serp-url" data-seo-wp-serp-url></div>';
		echo '<div class="seo-wp-serp-title" data-seo-wp-serp-title></div>';
		echo '<div class="seo-wp-serp-description" data-seo-wp-serp-description></div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the container for the live social-card preview (Facebook/
	 * Twitter/LinkedIn all render an Open Graph card the same basic way).
	 * Kept in sync by metabox.js; see render_serp_preview().
	 */
	private function render_social_preview(): void {
		echo '<div class="seo-wp-field seo-wp-social-preview">';
		echo '<span class="seo-wp-preview-label">' . esc_html__( 'Social share preview', 'seo-wp' ) . '</span>';
		echo '<div class="seo-wp-social-card">';
		echo '<div class="seo-wp-social-image" data-seo-wp-social-image></div>';
		echo '<div class="seo-wp-social-body">';
		echo '<div class="seo-wp-social-domain" data-seo-wp-social-domain></div>';
		echo '<div class="seo-wp-social-title" data-seo-wp-social-title></div>';
		echo '<div class="seo-wp-social-description" data-seo-wp-social-description></div>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the noindex/nofollow/advanced robots controls.
	 *
	 * @param array<string,mixed> $values Field values, keyed by meta key.
	 */
	private function field_robots( array $values ): void {
		echo '<p class="seo-wp-field">';
		foreach (
			array(
				''  => __( 'Default', 'seo-wp' ),
				'0' => __( 'Index', 'seo-wp' ),
				'1' => __( 'Noindex', 'seo-wp' ),
			) as $option_value => $option_label
		) {
			printf(
				'<label><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label> ',
				esc_attr( Robots::META_NOINDEX ),
				esc_attr( $option_value ),
				checked( (string) $values[ Robots::META_NOINDEX ], $option_value, false ),
				esc_html( $option_label )
			);
		}
		echo '</p>';

		printf(
			'<p class="seo-wp-field"><label><input type="checkbox" name="%1$s" value="1" %2$s /> %3$s</label></p>',
			esc_attr( Robots::META_NOFOLLOW ),
			checked( '1' === $values[ Robots::META_NOFOLLOW ], true, false ),
			esc_html__( 'Nofollow', 'seo-wp' )
		);

		$advanced = (array) $values[ Robots::META_ADVANCED ];
		echo '<p class="seo-wp-field">';
		foreach ( self::advanced_directive_labels() as $directive => $directive_label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label> ',
				esc_attr( Robots::META_ADVANCED ),
				esc_attr( $directive ),
				checked( in_array( $directive, $advanced, true ), true, false ),
				esc_html( $directive_label )
			);
		}
		echo '</p>';
	}

	/**
	 * Renders the schema type override field: a text input with a custom,
	 * JS-driven autocomplete list of common Schema.org type names. Not the
	 * native <input list> + <datalist> combination — that pairing's actual
	 * suggestion-popup behavior is inconsistent across browsers (most
	 * notably Safari/WebKit) to the point of unreliability, so this
	 * renders its own dropdown (metabox.js) reading the same option list
	 * from a still-server-rendered, but otherwise inert, <datalist> used
	 * purely as a data source. Any value is accepted here —
	 * GraphBuilder::is_valid_schema_type() is the actual gate, both on
	 * save (invalid values are silently dropped) and again at render time
	 * as a second safety net.
	 *
	 * @param string $value Current value.
	 */
	private function field_schema_type( string $value ): void {
		echo '<p class="seo-wp-field seo-wp-schema-type-field">';
		printf(
			'<label for="seo-wp-%1$s">%2$s</label>',
			esc_attr( GraphBuilder::META_SCHEMA_TYPE ),
			esc_html__( 'Schema type', 'seo-wp' )
		);
		printf(
			'<input type="text" id="seo-wp-%1$s" name="%1$s" value="%2$s" placeholder="%3$s" class="widefat" autocomplete="off" />',
			esc_attr( GraphBuilder::META_SCHEMA_TYPE ),
			esc_attr( $value ),
			esc_attr__( 'Automatic', 'seo-wp' )
		);
		echo '<datalist id="seo-wp-schema-type-suggestions">';
		foreach ( GraphBuilder::SUGGESTED_TYPES as $suggested_type ) {
			printf( '<option value="%s"></option>', esc_attr( $suggested_type ) );
		}
		echo '</datalist>';
		printf(
			'<span class="description">%s</span>',
			esc_html__( 'Leave empty to use the automatic type (Article or WebPage). Any Schema.org type name works, not just the suggestions.', 'seo-wp' )
		);
		echo '</p>';
	}

	/**
	 * Renders the WooCommerce product schema fields: brand, GTIN, MPN and
	 * a price-valid-until date. None of these have a WooCommerce-core
	 * equivalent, so they're this plugin's own optional fields, folded
	 * into the Product JSON-LD node when present (Schema\Nodes\Product).
	 *
	 * @param array<string,mixed> $values Field values, keyed by meta key.
	 */
	private function field_product( array $values ): void {
		$this->field_text( Product::META_BRAND, __( 'Brand', 'seo-wp' ), (string) $values[ Product::META_BRAND ] );
		$this->field_text( Product::META_GTIN, __( 'GTIN', 'seo-wp' ), (string) $values[ Product::META_GTIN ] );
		$this->field_text( Product::META_MPN, __( 'MPN (manufacturer part number)', 'seo-wp' ), (string) $values[ Product::META_MPN ] );

		printf(
			'<p class="seo-wp-field"><label for="seo-wp-%1$s">%2$s</label><input type="date" id="seo-wp-%1$s" name="%1$s" value="%3$s" /><span class="description">%4$s</span></p>',
			esc_attr( Product::META_PRICE_VALID_UNTIL ),
			esc_html__( 'Price valid until', 'seo-wp' ),
			esc_attr( (string) $values[ Product::META_PRICE_VALID_UNTIL ] ),
			esc_html__( 'Optional. Leave empty unless the current price is a limited-time offer.', 'seo-wp' )
		);
	}

	/**
	 * Renders the FAQ repeater.
	 *
	 * @param array<int,array{question:string,answer:string}> $faq Current FAQ entries.
	 */
	private function field_faq( array $faq ): void {
		echo '<div class="seo-wp-faq-rows">';
		foreach ( $faq as $item ) {
			$this->field_faq_row( (string) ( $item['question'] ?? '' ), (string) ( $item['answer'] ?? '' ) );
		}
		echo '</div>';
		echo '<button type="button" class="button seo-wp-faq-add">' . esc_html__( 'Add question', 'seo-wp' ) . '</button>';
	}

	/**
	 * Renders a single FAQ question/answer row.
	 *
	 * @param string $question Question text.
	 * @param string $answer   Answer text.
	 */
	private function field_faq_row( string $question, string $answer ): void {
		printf(
			'<div class="seo-wp-faq-row"><input type="text" name="seo_wp_faq_question[]" value="%1$s" placeholder="%2$s" class="widefat" /><textarea name="seo_wp_faq_answer[]" rows="2" class="widefat" placeholder="%3$s">%4$s</textarea><button type="button" class="button-link seo-wp-faq-remove">%5$s</button></div>',
			esc_attr( $question ),
			esc_attr__( 'Question', 'seo-wp' ),
			esc_attr__( 'Answer', 'seo-wp' ),
			esc_textarea( $answer ),
			esc_html__( 'Remove', 'seo-wp' )
		);
	}
}

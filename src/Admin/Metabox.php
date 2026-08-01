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
use SeoWp\Schema\Nodes\FaqPage;
use SeoWp\Social\OpenGraph;
use SeoWp\Social\TwitterCards;
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
				'i18n' => array(
					'question' => __( 'Question', 'seo-wp' ),
					'answer'   => __( 'Answer', 'seo-wp' ),
					'remove'   => __( 'Remove', 'seo-wp' ),
				),
			)
		);
	}

	/**
	 * Renders the metabox on a post edit screen.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_post_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$faq = get_post_meta( $post->ID, FaqPage::META_FAQ, true );

		$this->render_tabs( $this->read_values( $post->ID, true ), is_array( $faq ) ? $faq : array(), true );
	}

	/**
	 * Renders the fields on a term edit screen.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public function render_term_fields( WP_Term $term ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		echo '<tr class="form-field"><th scope="row">' . esc_html__( 'SEO-WP', 'seo-wp' ) . '</th><td>';
		$this->render_tabs( $this->read_values( $term->term_id, false ), array(), false );
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

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
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
		if ( ! $this->has_valid_nonce() || ! current_user_can( $taxonomy->cap->edit_terms ) ) {
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
			Canonical::META_CANONICAL,
			Robots::META_NOINDEX,
			Robots::META_NOFOLLOW,
			OpenGraph::META_TITLE,
			OpenGraph::META_DESCRIPTION,
			OpenGraph::META_IMAGE,
			TwitterCards::META_TITLE,
			TwitterCards::META_DESCRIPTION,
			TwitterCards::META_IMAGE,
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
			Canonical::META_CANONICAL,
			OpenGraph::META_TITLE,
			OpenGraph::META_DESCRIPTION,
			OpenGraph::META_IMAGE,
			TwitterCards::META_TITLE,
			TwitterCards::META_DESCRIPTION,
			TwitterCards::META_IMAGE,
		);

		foreach ( $text_keys as $key ) {
			$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
			$this->update_meta( $object_id, $is_post, $key, $value );
		}

		$noindex = isset( $_POST[ Robots::META_NOINDEX ] ) ? sanitize_text_field( wp_unslash( $_POST[ Robots::META_NOINDEX ] ) ) : '';
		$this->update_meta( $object_id, $is_post, Robots::META_NOINDEX, in_array( $noindex, array( '0', '1' ), true ) ? $noindex : '' );

		$this->update_meta( $object_id, $is_post, Robots::META_NOFOLLOW, empty( $_POST[ Robots::META_NOFOLLOW ] ) ? '' : '1' );

		$advanced_raw = isset( $_POST[ Robots::META_ADVANCED ] ) && is_array( $_POST[ Robots::META_ADVANCED ] )
			? array_map( 'sanitize_key', wp_unslash( $_POST[ Robots::META_ADVANCED ] ) )
			: array();
		$this->update_meta( $object_id, $is_post, Robots::META_ADVANCED, array_values( array_intersect( self::ADVANCED_DIRECTIVE_KEYS, $advanced_raw ) ) );
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
	 * Renders the tab navigation and panels.
	 *
	 * @param array<string,mixed>                             $values          Field values, keyed by meta key.
	 * @param array<int,array{question:string,answer:string}> $faq FAQ entries (posts only).
	 * @param bool                                            $show_schema_tab Whether to render the Schema tab (posts only).
	 */
	private function render_tabs( array $values, array $faq, bool $show_schema_tab ): void {
		echo '<div class="seo-wp-metabox">';

		echo '<div class="seo-wp-metabox-tabs">';
		echo '<button type="button" class="seo-wp-tab-button seo-wp-tab-active" data-seo-wp-tab="general">' . esc_html__( 'General', 'seo-wp' ) . '</button>';
		echo '<button type="button" class="seo-wp-tab-button" data-seo-wp-tab="social">' . esc_html__( 'Social', 'seo-wp' ) . '</button>';
		if ( $show_schema_tab ) {
			echo '<button type="button" class="seo-wp-tab-button" data-seo-wp-tab="schema">' . esc_html__( 'Schema', 'seo-wp' ) . '</button>';
		}
		echo '</div>';

		echo '<div class="seo-wp-tab-panel seo-wp-tab-active" data-seo-wp-panel="general">';
		$this->field_text( TitleDescription::META_TITLE, __( 'Title', 'seo-wp' ), (string) $values[ TitleDescription::META_TITLE ] );
		$this->field_textarea( TitleDescription::META_DESCRIPTION, __( 'Meta description', 'seo-wp' ), (string) $values[ TitleDescription::META_DESCRIPTION ] );
		$this->field_text( Canonical::META_CANONICAL, __( 'Canonical URL', 'seo-wp' ), (string) $values[ Canonical::META_CANONICAL ] );
		$this->field_robots( $values );
		echo '</div>';

		echo '<div class="seo-wp-tab-panel" data-seo-wp-panel="social">';
		$this->field_text( OpenGraph::META_TITLE, __( 'Open Graph title', 'seo-wp' ), (string) $values[ OpenGraph::META_TITLE ] );
		$this->field_textarea( OpenGraph::META_DESCRIPTION, __( 'Open Graph description', 'seo-wp' ), (string) $values[ OpenGraph::META_DESCRIPTION ] );
		$this->field_text( OpenGraph::META_IMAGE, __( 'Open Graph image URL', 'seo-wp' ), (string) $values[ OpenGraph::META_IMAGE ] );
		$this->field_text( TwitterCards::META_TITLE, __( 'Twitter title', 'seo-wp' ), (string) $values[ TwitterCards::META_TITLE ] );
		$this->field_textarea( TwitterCards::META_DESCRIPTION, __( 'Twitter description', 'seo-wp' ), (string) $values[ TwitterCards::META_DESCRIPTION ] );
		$this->field_text( TwitterCards::META_IMAGE, __( 'Twitter image URL', 'seo-wp' ), (string) $values[ TwitterCards::META_IMAGE ] );
		echo '</div>';

		if ( $show_schema_tab ) {
			echo '<div class="seo-wp-tab-panel" data-seo-wp-panel="schema">';
			$this->field_faq( $faq );
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Renders a single-line text field.
	 *
	 * @param string $name  Field name (also the meta key).
	 * @param string $label Field label.
	 * @param string $value Current value.
	 */
	private function field_text( string $name, string $label, string $value ): void {
		printf(
			'<p class="seo-wp-field"><label for="seo-wp-%1$s">%2$s</label><input type="text" id="seo-wp-%1$s" name="%1$s" value="%3$s" class="widefat" /></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( $value )
		);
	}

	/**
	 * Renders a multi-line textarea field.
	 *
	 * @param string $name  Field name (also the meta key).
	 * @param string $label Field label.
	 * @param string $value Current value.
	 */
	private function field_textarea( string $name, string $label, string $value ): void {
		printf(
			'<p class="seo-wp-field"><label for="seo-wp-%1$s">%2$s</label><textarea id="seo-wp-%1$s" name="%1$s" rows="3" class="widefat">%3$s</textarea></p>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( $value )
		);
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

<?php
/**
 * Image SEO screen: find images without alt text and fix them.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Admin;

use Seooptimierung\Toolkit\Media\AltText;
use Seooptimierung\Toolkit\Support\Ai;
use Seooptimierung\Toolkit\Support\Ajax;
use WP_Post;
use WP_Query;

/**
 * Two checks and the tools to act on them:
 *
 * 1. Media library: every image whose alt text is empty (and not marked
 *    decorative), editable inline, with an AI suggestion per image or for
 *    the whole page, and a one-click "use the title".
 * 2. Content: posts and pages that contain an <img> with no alt attribute
 *    at all (found by a batched scan, since that means reading every
 *    post's content).
 *
 * Also hosts the two opt-in switches handled by Media\AltText.
 */
final class ImageSeo {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seooptimierung-image-seo';

	/**
	 * Capability required to use this screen.
	 */
	private const CAPABILITY = 'seo_wp_manage_settings';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option key holding the last content scan's result.
	 */
	private const SCAN_OPTION = 'seo_wp_image_alt_scan';

	/**
	 * Nonce action shared by the screen's form and AJAX calls.
	 */
	private const NONCE_ACTION = 'seo_wp_image_seo';

	/**
	 * Images listed per page.
	 */
	private const PER_PAGE = 20;

	/**
	 * Posts scanned per content-scan request.
	 */
	private const SCAN_BATCH = 40;

	/**
	 * Largest image file sent to an AI model, in bytes.
	 */
	private const MAX_AI_IMAGE_BYTES = 4000000;

	/**
	 * Registers the page, its assets and its handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_seo_wp_image_seo_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'wp_ajax_seo_wp_image_alt_save', array( $this, 'handle_save_ajax' ) );
		add_action( 'wp_ajax_seo_wp_image_alt_suggest', array( $this, 'handle_suggest_ajax' ) );
		add_action( 'wp_ajax_seo_wp_image_content_scan', array( $this, 'handle_scan_ajax' ) );
	}

	/**
	 * Adds the Image SEO submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Image SEO', 'seooptimierung-toolkit' ),
			__( 'Image SEO', 'seooptimierung-toolkit' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Loads the stylesheet and script on this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'seo-wp-admin', plugins_url( 'assets/admin/admin.css', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION );
		wp_enqueue_script( 'seo-wp-image-seo', plugins_url( 'assets/admin/image-seo.js', \Seooptimierung\Toolkit\FILE ), array(), \Seooptimierung\Toolkit\VERSION, true );

		wp_localize_script(
			'seo-wp-image-seo',
			'seoWpImageSeo',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
				'providers' => Ai::providers(),
				'i18n'      => array(
					'saving'     => __( 'Saving…', 'seooptimierung-toolkit' ),
					'saved'      => __( 'Saved', 'seooptimierung-toolkit' ),
					'asking'     => __( 'Asking AI…', 'seooptimierung-toolkit' ),
					'error'      => __( 'Something went wrong.', 'seooptimierung-toolkit' ),
					'scanning'   => __( 'Scanning content…', 'seooptimierung-toolkit' ),
					/* translators: 1: posts scanned so far, 2: total posts. */
					'progress'   => __( 'Scanned %1$s of %2$s…', 'seooptimierung-toolkit' ),
					'scanDone'   => __( 'Scan finished — reload the page to see the result.', 'seooptimierung-toolkit' ),
					'suggestAll' => __( 'Suggesting for all rows…', 'seooptimierung-toolkit' ),
				),
			)
		);
	}

	/**
	 * Renders the screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$filter = $this->current_filter();
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.

		$query     = new WP_Query( $this->query_args( $filter, $paged ) );
		$missing   = $this->count( 'missing' );
		$decorated = $this->count( 'decorative' );
		$total     = $this->total_images();

		echo '<div class="wrap seo-wp-image-seo">';
		echo '<h1>' . esc_html__( 'Seooptimierung Toolkit Image SEO', 'seooptimierung-toolkit' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only banner.
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'seooptimierung-toolkit' ) . '</p></div>';
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: images in the media library, 2: images without alt text, 3: images marked decorative. */
					__( '%1$s images in the media library — %2$s without alt text, %3$s marked decorative.', 'seooptimierung-toolkit' ),
					number_format_i18n( $total ),
					number_format_i18n( $missing ),
					number_format_i18n( $decorated )
				)
			)
		);
		echo '<p class="description">' . esc_html__( 'Alt text describes an image for screen readers and search engines. Describe what is visible, briefly; mark purely decorative images as decorative so they are not counted as missing.', 'seooptimierung-toolkit' ) . '</p>';

		$this->render_settings_form();
		$this->render_filters( $filter, $missing, $decorated, $total );
		$this->render_table( $query, $filter, $paged );
		$this->render_content_scan();

		echo '</div>';
	}

	/**
	 * The active list filter: missing (default), all or decorative.
	 */
	private function current_filter(): string {
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'missing'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.

		return in_array( $filter, array( 'missing', 'all', 'decorative' ), true ) ? $filter : 'missing';
	}

	/**
	 * WP_Query args for one page of the list.
	 *
	 * @param string $filter missing, all or decorative.
	 * @param int    $paged  Page number.
	 * @return array<string,mixed>
	 */
	private function query_args( string $filter, int $paged ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( 'missing' === $filter ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- an admin-only list of images lacking a meta value; no other way to express it.
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array(
						'key'     => '_wp_attachment_image_alt',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_wp_attachment_image_alt',
						'value'   => '',
						'compare' => '=',
					),
				),
				array(
					'key'     => AltText::META_DECORATIVE,
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( 'decorative' === $filter ) {
			$args['meta_key']   = AltText::META_DECORATIVE; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- admin-only list.
			$args['meta_value'] = '1'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- admin-only list.
		}

		return $args;
	}

	/**
	 * Number of images matching a filter.
	 *
	 * @param string $filter missing or decorative.
	 */
	private function count( string $filter ): int {
		$args                   = $this->query_args( $filter, 1 );
		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';
		$query                  = new WP_Query( $args );

		return (int) $query->found_posts;
	}

	/**
	 * Number of images in the media library.
	 */
	private function total_images(): int {
		$total = 0;

		foreach ( (array) wp_count_attachments( 'image' ) as $mime => $count ) {
			if ( 'trash' !== $mime ) {
				$total += (int) $count;
			}
		}

		return $total;
	}

	/**
	 * Renders the two opt-in switches.
	 */
	private function render_settings_form(): void {
		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="seo-wp-image-seo-settings">';
		echo '<input type="hidden" name="action" value="seo_wp_image_seo_settings" />';
		wp_nonce_field( self::NONCE_ACTION, 'seo_wp_image_seo_nonce' );

		printf(
			'<p><label><input type="checkbox" name="image_alt_on_upload" value="1" %1$s /> %2$s</label></p>',
			checked( ! empty( $settings['image_alt_on_upload'] ), true, false ),
			esc_html__( 'Fill in alt text automatically when an image is uploaded (made from the file name; camera names like IMG_1234 are skipped)', 'seooptimierung-toolkit' )
		);
		printf(
			'<p><label><input type="checkbox" name="image_alt_fallback" value="1" %1$s /> %2$s</label><br /><span class="description">%3$s</span></p>',
			checked( ! empty( $settings['image_alt_fallback'] ), true, false ),
			esc_html__( 'Show the image title as alt text on the website for images that have no alt attribute at all', 'seooptimierung-toolkit' ),
			esc_html__( 'Only fills a missing attribute — an alt you set (even an empty one) is never replaced, and decorative images stay as they are. Fixing the alt text itself below is always better.', 'seooptimierung-toolkit' )
		);

		submit_button( __( 'Save', 'seooptimierung-toolkit' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Handles the settings form.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) );
		}

		check_admin_referer( self::NONCE_ACTION, 'seo_wp_image_seo_nonce' );

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings['image_alt_on_upload'] = ! empty( $_POST['image_alt_on_upload'] );
		$settings['image_alt_fallback']  = ! empty( $_POST['image_alt_fallback'] );

		update_option( self::OPTION_KEY, $settings, 'yes' );

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
	 * Renders the filter links.
	 *
	 * @param string $filter    Active filter.
	 * @param int    $missing   Images without alt.
	 * @param int    $decorated Decorative images.
	 * @param int    $total     All images.
	 */
	private function render_filters( string $filter, int $missing, int $decorated, int $total ): void {
		$links = array(
			'missing'    => sprintf( /* translators: %s: number. */ __( 'Missing alt text (%s)', 'seooptimierung-toolkit' ), number_format_i18n( $missing ) ),
			'all'        => sprintf( /* translators: %s: number. */ __( 'All images (%s)', 'seooptimierung-toolkit' ), number_format_i18n( $total ) ),
			'decorative' => sprintf( /* translators: %s: number. */ __( 'Decorative (%s)', 'seooptimierung-toolkit' ), number_format_i18n( $decorated ) ),
		);

		echo '<ul class="subsubsub">';
		$parts = array();
		foreach ( $links as $key => $label ) {
			$parts[] = sprintf(
				'<li><a href="%1$s" class="%2$s">%3$s</a></li>',
				esc_url(
					add_query_arg(
						array(
							'page'   => self::PAGE_SLUG,
							'filter' => $key,
						),
						admin_url( 'admin.php' )
					)
				),
				$key === $filter ? 'current' : '',
				esc_html( $label )
			);
		}
		echo implode( ' | ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is built with esc_url()/esc_html() above.
		echo '</ul><br class="clear" />';
	}

	/**
	 * Renders the image table with pagination.
	 *
	 * @param WP_Query $query  The page's query.
	 * @param string   $filter Active filter.
	 * @param int      $paged  Current page.
	 */
	private function render_table( WP_Query $query, string $filter, int $paged ): void {
		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html( 'missing' === $filter ? __( 'Every image has alt text. Nothing to fix here.', 'seooptimierung-toolkit' ) : __( 'No images in this list.', 'seooptimierung-toolkit' ) ) . '</p>';
			return;
		}

		if ( 'missing' === $filter && array() !== Ai::providers() ) {
			echo '<p><button type="button" class="button seo-wp-image-suggest-all">' . esc_html__( 'Suggest alt text for every image on this page', 'seooptimierung-toolkit' ) . '</button> <span class="seo-wp-image-bulk-status"></span></p>';
		}

		echo '<table class="widefat striped seo-wp-image-table"><thead><tr>';
		echo '<th style="width:70px;">' . esc_html__( 'Image', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'File', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th>' . esc_html__( 'Alt text', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th style="width:110px;">' . esc_html__( 'Decorative', 'seooptimierung-toolkit' ) . '</th>';
		echo '<th style="width:200px;"></th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $attachment ) {
			if ( $attachment instanceof WP_Post ) {
				$this->render_row( $attachment );
			}
		}

		echo '</tbody></table>';

		$links = paginate_links(
			array(
				'base'      => add_query_arg(
					array(
						'page'   => self::PAGE_SLUG,
						'filter' => $filter,
						'paged'  => '%#%',
					),
					admin_url( 'admin.php' )
				),
				'format'    => '',
				'current'   => $paged,
				'total'     => (int) $query->max_num_pages,
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
			)
		);
		if ( $links ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $links ) . '</div></div>';
		}
	}

	/**
	 * Renders one image row.
	 *
	 * @param WP_Post $attachment Image attachment.
	 */
	private function render_row( WP_Post $attachment ): void {
		$id         = $attachment->ID;
		$alt        = (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
		$decorative = '1' === (string) get_post_meta( $id, AltText::META_DECORATIVE, true );
		$file       = basename( (string) get_attached_file( $id ) );
		$edit       = get_edit_post_link( $id );
		$fallback   = AltText::fallback_for( $id );

		printf( '<tr data-id="%d" data-fallback="%s">', (int) $id, esc_attr( $fallback ) );
		echo '<td>' . wp_get_attachment_image( $id, array( 60, 60 ), false, array( 'alt' => '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated <img> markup.
		printf(
			'<td>%1$s<br /><span class="description">%2$s</span></td>',
			$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : esc_html( get_the_title( $id ) ),
			esc_html( $file )
		);
		printf(
			'<td><input type="text" class="large-text seo-wp-alt-input" value="%1$s" maxlength="200" /></td>',
			esc_attr( $alt )
		);
		printf(
			'<td><label><input type="checkbox" class="seo-wp-alt-decorative" %1$s /> %2$s</label></td>',
			checked( $decorative, true, false ),
			esc_html__( 'Decorative', 'seooptimierung-toolkit' )
		);
		echo '<td>';
		echo '<button type="button" class="button button-primary seo-wp-alt-save">' . esc_html__( 'Save', 'seooptimierung-toolkit' ) . '</button> ';
		if ( '' !== $fallback ) {
			echo '<button type="button" class="button seo-wp-alt-title">' . esc_html__( 'Use title', 'seooptimierung-toolkit' ) . '</button> ';
		}
		if ( array() !== Ai::providers() ) {
			echo '<button type="button" class="button seo-wp-alt-suggest">' . esc_html__( 'Suggest', 'seooptimierung-toolkit' ) . '</button>';
		}
		echo ' <span class="seo-wp-alt-status" aria-live="polite"></span></td>';
		echo '</tr>';
	}

	/**
	 * Renders the content scan section and the last result.
	 */
	private function render_content_scan(): void {
		$result = get_option( self::SCAN_OPTION, array() );
		$result = is_array( $result ) ? $result : array();

		echo '<h2>' . esc_html__( 'Images in content without an alt attribute', 'seooptimierung-toolkit' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Looks through the content of all published posts and pages for <img> tags that have no alt attribute at all (an empty alt="" counts as a decision and is fine). Open the post to fix them.', 'seooptimierung-toolkit' ) . '</p>';
		echo '<p><button type="button" class="button seo-wp-image-scan">' . esc_html__( 'Scan content now', 'seooptimierung-toolkit' ) . '</button> <span class="seo-wp-image-scan-status"></span></p>';

		if ( ! isset( $result['posts'] ) ) {
			return;
		}

		$posts = (array) $result['posts'];

		if ( array() === $posts ) {
			echo '<p><span style="color:#00a32a;">&#10003;</span> ' . esc_html__( 'The last scan found no image without an alt attribute.', 'seooptimierung-toolkit' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:800px;"><thead><tr><th>' . esc_html__( 'Post', 'seooptimierung-toolkit' ) . '</th><th>' . esc_html__( 'Images without alt', 'seooptimierung-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $posts as $post_id => $count ) {
			$edit = get_edit_post_link( (int) $post_id );
			printf(
				'<tr><td>%1$s</td><td>%2$d</td></tr>',
				$edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( (int) $post_id ) ) . '</a>' : esc_html( get_the_title( (int) $post_id ) ),
				(int) $count
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Saves one image's alt text and decorative flag.
	 */
	public function handle_save_ajax(): void {
		Ajax::begin( 'image-seo:save' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$alt        = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['alt'] ) ) : '';
		$decorative = ! empty( $_POST['decorative'] ) && 'false' !== $_POST['decorative'];

		if ( $decorative ) {
			update_post_meta( $id, AltText::META_DECORATIVE, '1' );
			update_post_meta( $id, '_wp_attachment_image_alt', '' );
		} else {
			delete_post_meta( $id, AltText::META_DECORATIVE );
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}

		Ajax::success( array( 'alt' => $decorative ? '' : $alt ) );
	}

	/**
	 * Asks the chosen AI provider for an alt text. Writes nothing: the
	 * browser puts the suggestion into the field, and only "Save" stores it.
	 */
	public function handle_suggest_ajax(): void {
		Ajax::begin( 'image-seo:suggest' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		if ( $id <= 0 || ! wp_attachment_is_image( $id ) || ! current_user_can( 'edit_post', $id ) || ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( (string) $_POST['provider'] ) ) : '';
		if ( '' !== $provider && ! isset( Ai::providers()[ $provider ] ) ) {
			Ajax::error( array( 'message' => __( 'Unknown provider.', 'seooptimierung-toolkit' ) ), 400 );
		}

		$parent = (int) wp_get_post_parent_id( $id );
		$prompt = AltText::build_prompt(
			AltText::humanize( (string) get_the_title( $id ) ),
			wp_strip_all_tags( (string) wp_get_attachment_caption( $id ) ),
			$parent > 0 ? wp_strip_all_tags( (string) get_the_title( $parent ) ) : '',
			self::language_name()
		);

		$reply = Ai::generate( $provider, $prompt, self::image_payload( $id ), 120 );
		if ( is_wp_error( $reply ) ) {
			Ajax::error( array( 'message' => $reply->get_error_message() ) );
		}

		$alt = AltText::clean_reply( $reply );
		if ( '' === $alt ) {
			Ajax::error( array( 'message' => __( 'The AI returned no usable text.', 'seooptimierung-toolkit' ) ) );
		}

		Ajax::success( array( 'alt' => $alt ) );
	}

	/**
	 * Scans one batch of posts for <img> tags without an alt attribute.
	 */
	public function handle_scan_ajax(): void {
		Ajax::begin( 'image-seo:scan' );
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			Ajax::error( array( 'message' => __( 'You are not allowed to do this.', 'seooptimierung-toolkit' ) ), 403 );
		}

		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;

		$query = new WP_Query(
			array(
				'post_type'              => array_values( array_diff( get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) ),
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::SCAN_BATCH,
				'offset'                 => $offset,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$result = 0 === $offset ? array( 'posts' => array() ) : get_option( self::SCAN_OPTION, array() );
		$result = is_array( $result ) && isset( $result['posts'] ) ? $result : array( 'posts' => array() );

		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$count = self::count_images_without_alt( $post->post_content );
				if ( $count > 0 ) {
					$result['posts'][ $post->ID ] = $count;
				}
			}
		}

		$next = $offset + self::SCAN_BATCH;
		$done = $next >= (int) $query->found_posts;

		update_option( self::SCAN_OPTION, $result, false );

		Ajax::success(
			array(
				'next'  => $next,
				'total' => (int) $query->found_posts,
				'done'  => $done,
			)
		);
	}

	/**
	 * Number of <img> tags in HTML that have no alt attribute at all. Pure
	 * string logic.
	 *
	 * @param string $html HTML or block markup.
	 */
	public static function count_images_without_alt( string $html ): int {
		return (int) preg_match_all( '/<img\b(?![^>]*\salt\s*=)[^>]*>/i', $html );
	}

	/**
	 * The language the alt text should be written in, from the site locale.
	 */
	private static function language_name(): string {
		$locale = (string) get_locale();
		$map    = array(
			'de' => 'German',
			'en' => 'English',
			'fr' => 'French',
			'es' => 'Spanish',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'pt' => 'Portuguese',
		);
		$code   = strtolower( substr( $locale, 0, 2 ) );

		return isset( $map[ $code ] ) ? $map[ $code ] . ' (' . $locale . ')' : $locale;
	}

	/**
	 * The image as a base64 payload for a vision-capable model, or null
	 * when it can't be sent (unsupported type, too large, unreadable) — the
	 * request then goes out with the title and context only.
	 *
	 * @param int $id Attachment ID.
	 * @return array{mime:string,data:string}|null
	 */
	private static function image_payload( int $id ): ?array {
		$paths = array();

		foreach ( array( 'large', 'medium_large' ) as $size ) {
			$info = image_get_intermediate_size( $id, $size );
			if ( is_array( $info ) && ! empty( $info['path'] ) ) {
				$paths[] = trailingslashit( wp_get_upload_dir()['basedir'] ) . $info['path'];
			}
		}
		$paths[] = (string) get_attached_file( $id );

		foreach ( $paths as $path ) {
			if ( ! is_readable( $path ) || filesize( $path ) > self::MAX_AI_IMAGE_BYTES ) {
				continue;
			}

			$type = wp_check_filetype( $path );
			$mime = (string) $type['type'];
			if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
				continue;
			}

			$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload file, not a URL.
			if ( false !== $bytes ) {
				return array(
					'mime' => $mime,
					'data' => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the AI APIs take the image as base64.
				);
			}
		}

		return null;
	}
}

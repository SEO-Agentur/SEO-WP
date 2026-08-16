<?php
/**
 * Reports published posts that haven't been updated in a while.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Admin;

use DateTimeImmutable;
use WP_Post_Type;
use WP_Query;

/**
 * A read-only report, no scan/scheduling involved: every public post
 * type's published posts, oldest post_modified first, filtered to only
 * those older than a threshold chosen right on the page. Content
 * maintenance is squarely an editorial decision this plugin has no
 * business making automatically — this only surfaces what might need a
 * look, same spirit as Duplicate Content and Link Audit.
 */
final class ContentFreshness {

	/**
	 * Submenu page slug.
	 */
	private const PAGE_SLUG = 'seo-wp-freshness';

	/**
	 * Capability required to view this page.
	 */
	private const CAPABILITY = 'seo_wp_view_freshness';

	/**
	 * Posts listed per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * Threshold choices offered on the page, in months.
	 *
	 * @var int[]
	 */
	private const THRESHOLD_CHOICES = array( 3, 6, 12, 24, 36 );

	/**
	 * Threshold used when none (or an invalid one) is requested.
	 */
	private const DEFAULT_THRESHOLD_MONTHS = 12;

	/**
	 * Registers the submenu page and its assets.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Adds the Content Freshness submenu page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			Settings::PAGE_SLUG,
			__( 'Content Freshness', 'seo-wp' ),
			__( 'Content Freshness', 'seo-wp' ),
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
		wp_enqueue_script( 'seo-wp-content-freshness', plugins_url( 'assets/admin/content-freshness.js', \SeoWp\FILE ), array(), \SeoWp\VERSION, true );
	}

	/**
	 * Resolves the cutoff timestamp before which a post counts as stale.
	 * Pure, so it's easy to unit test without WordPress' notion of "now".
	 *
	 * @param int $threshold_months    Months of staleness threshold.
	 * @param int $reference_timestamp "Now", as a Unix timestamp.
	 */
	public static function cutoff_timestamp( int $threshold_months, int $reference_timestamp ): int {
		$threshold_months    = self::normalise_threshold( $threshold_months );
		$reference_timestamp = max( 0, $reference_timestamp );

		return (int) ( new DateTimeImmutable( '@' . $reference_timestamp ) )->modify( "-{$threshold_months} months" )->getTimestamp();
	}

	/**
	 * Restricts an arbitrary threshold to one of the offered choices,
	 * falling back to the default for anything else — keeps the query
	 * predictable and the <select> always showing a real selection.
	 *
	 * @param int $threshold_months Candidate threshold, in months.
	 */
	public static function normalise_threshold( int $threshold_months ): int {
		return in_array( $threshold_months, self::THRESHOLD_CHOICES, true ) ? $threshold_months : self::DEFAULT_THRESHOLD_MONTHS;
	}

	/**
	 * Renders the report page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) && ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter/pagination, not a state-changing action.
		$threshold = self::normalise_threshold( isset( $_GET['months'] ) ? (int) $_GET['months'] : self::DEFAULT_THRESHOLD_MONTHS );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, not a state-changing action.
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		$cutoff = self::cutoff_timestamp( $threshold, time() );

		echo '<div class="wrap seo-wp-content-freshness">';
		echo '<h1>' . esc_html__( 'SEO-WP Content Freshness', 'seo-wp' ) . '</h1>';
		echo '<p>' . esc_html__( 'Published posts that haven\'t been updated in a while — a common signal both search engines and readers use to judge relevance. Purely informational: nothing here is changed automatically.', 'seo-wp' ) . '</p>';

		$this->render_threshold_form( $threshold );

		$query = new WP_Query(
			array(
				'post_type'              => get_post_types( array( 'public' => true ), 'names' ),
				'post_status'            => 'publish',
				'posts_per_page'         => self::PER_PAGE,
				'paged'                  => $paged,
				'orderby'                => 'modified',
				'order'                  => 'ASC',
				'date_query'             => array(
					array(
						'column' => 'post_modified',
						'before' => gmdate( 'Y-m-d H:i:s', $cutoff ),
					),
				),
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$this->render_results_table( $query );
		$this->render_pagination( $query, $threshold, $paged );

		echo '</div>';
	}

	/**
	 * Renders the "not updated in the last …" threshold selector.
	 *
	 * @param int $threshold Currently selected threshold, in months.
	 */
	private function render_threshold_form( int $threshold ): void {
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="seo-wp-freshness-filter">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label for="seo-wp-freshness-months">' . esc_html__( 'Not updated in the last:', 'seo-wp' ) . '</label> ';
		echo '<select name="months" id="seo-wp-freshness-months">';
		foreach ( self::THRESHOLD_CHOICES as $choice ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $choice,
				selected( $threshold, $choice, false ),
				esc_html(
					sprintf(
						/* translators: %d: number of months. */
						_n( '%d month', '%d months', $choice, 'seo-wp' ),
						$choice
					)
				)
			);
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'seo-wp' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Renders the results table for the current query.
	 *
	 * @param WP_Query $query Executed query.
	 */
	private function render_results_table( WP_Query $query ): void {
		if ( ! $query->have_posts() ) {
			echo '<p>' . esc_html__( 'Nothing found — every post is within this threshold.', 'seo-wp' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped seo-wp-freshness-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'seo-wp' ) . '</th>';
		echo '<th>' . esc_html__( 'Last updated', 'seo-wp' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $query->posts as $post ) {
			$edit_link   = get_edit_post_link( $post->ID, 'raw' );
			$type_object = get_post_type_object( $post->post_type );
			$type_label  = $type_object instanceof WP_Post_Type ? $type_object->labels->singular_name : $post->post_type;

			echo '<tr>';
			echo '<td>';
			if ( is_string( $edit_link ) && '' !== $edit_link ) {
				printf( '<a href="%s">%s</a>', esc_url( $edit_link ), esc_html( get_the_title( $post ) ) );
			} else {
				echo esc_html( get_the_title( $post ) );
			}
			echo '</td>';
			echo '<td>' . esc_html( $type_label ) . '</td>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ), $post->post_modified ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders simple page-number pagination.
	 *
	 * @param WP_Query $query     Executed query.
	 * @param int      $threshold Currently selected threshold, in months.
	 * @param int      $paged     Currently displayed page number.
	 */
	private function render_pagination( WP_Query $query, int $threshold, int $paged ): void {
		$total_pages = (int) $query->max_num_pages;
		if ( $total_pages <= 1 ) {
			return;
		}

		echo '<p class="seo-wp-freshness-pagination">';
		for ( $page = 1; $page <= $total_pages; $page++ ) {
			if ( $page === $paged ) {
				printf( '<strong>%d</strong> ', (int) $page );
				continue;
			}

			$url = add_query_arg(
				array(
					'page'   => self::PAGE_SLUG,
					'months' => $threshold,
					'paged'  => $page,
				),
				admin_url( 'admin.php' )
			);
			printf( '<a href="%s">%d</a> ', esc_url( $url ), (int) $page );
		}
		echo '</p>';
	}
}

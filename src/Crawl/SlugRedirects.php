<?php
/**
 * Keeps links working when a published page's URL changes.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Crawl;

use WP_Post;

/**
 * When the address of a published post or page changes (its slug, or the
 * slug of a parent page), two things follow:
 *
 * 1. A 301 redirect from the old address to the new one is created, so
 *    visitors, bookmarks and search engines keep arriving. Existing rules
 *    that pointed at the old address are re-pointed instead of chained, and a
 *    rule that would send the new address back to the old one is removed.
 * 2. Internal links to the old address in other content — post content and
 *    custom links in menus — are rewritten to the new one, so the site stops
 *    linking to a redirect.
 *
 * Both are on by default and can be switched off separately (Settings >
 * Crawl). Each change is written to a short log shown on the Redirects page.
 */
final class SlugRedirects {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Option key of the change log.
	 */
	public const LOG_OPTION = 'seo_wp_slug_log';

	/**
	 * Entries kept in the log.
	 */
	private const LOG_LIMIT = 50;

	/**
	 * Most posts whose content is rewritten for one URL change.
	 */
	private const MAX_POSTS = 1000;

	/**
	 * Registers the hook.
	 */
	public function register(): void {
		add_action( 'post_updated', array( $this, 'handle_post_updated' ), 20, 3 );
	}

	/**
	 * Reacts to a post update that changed the post's public address.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $after   Post after the update.
	 * @param WP_Post $before  Post before the update.
	 */
	public function handle_post_updated( $post_id, $after, $before ): void {
		if ( ! $after instanceof WP_Post || ! $before instanceof WP_Post ) {
			return;
		}

		$redirect = $this->setting( 'auto_redirect_slug_change' );
		$rewrite  = $this->setting( 'auto_update_internal_links' );

		if ( ( ! $redirect && ! $rewrite )
			|| 'publish' !== $before->post_status || 'publish' !== $after->post_status
			|| wp_is_post_revision( $after ) || wp_is_post_autosave( $after )
			|| ! is_post_type_viewable( $after->post_type ) ) {
			return;
		}

		$old = (string) get_permalink( $before );
		$new = (string) get_permalink( $after );

		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}

		$pairs = array( $old => $new );

		// A parent page's new slug moves every page below it.
		if ( is_post_type_hierarchical( $after->post_type ) ) {
			foreach ( get_pages(
				array(
					'child_of'    => (int) $post_id,
					'post_type'   => $after->post_type,
					'post_status' => 'publish',
				)
			) as $child ) {
				$new_child = (string) get_permalink( $child );
				$old_child = self::map_descendant( $new_child, $new, $old );
				if ( '' !== $old_child && $old_child !== $new_child ) {
					$pairs[ $old_child ] = $new_child;
				}
			}
		}

		if ( $redirect ) {
			foreach ( $pairs as $from => $to ) {
				self::redirect( $from, $to );
			}
		}

		$updated = $rewrite ? $this->rewrite_links( $pairs ) : 0;

		$this->log( $old, $new, count( $pairs ), $updated, $redirect );
	}

	/**
	 * The old address of a page below a moved parent: the child's new
	 * address with the new parent prefix swapped for the old one. Pure
	 * string logic.
	 *
	 * @param string $new_child  Child's address now.
	 * @param string $new_parent Parent's address now.
	 * @param string $old_parent Parent's address before.
	 */
	public static function map_descendant( string $new_child, string $new_parent, string $old_parent ): string {
		$new_parent = trailingslashit( $new_parent );
		$old_parent = trailingslashit( $old_parent );

		if ( 0 !== strpos( trailingslashit( $new_child ), $new_parent ) ) {
			return '';
		}

		$rest   = substr( $new_child, strlen( rtrim( $new_parent, '/' ) ) );
		$mapped = rtrim( $old_parent, '/' ) . $rest;

		return $mapped;
	}

	/**
	 * Creates the redirect from one address to another, avoiding chains
	 * and loops among the existing rules.
	 *
	 * @param string $from Old absolute URL.
	 * @param string $to   New absolute URL.
	 */
	private static function redirect( string $from, string $to ): void {
		$source = Redirects::normalize_path( $from );
		$target = Redirects::normalize_path( $to );
		if ( '' === $source || $source === $target ) {
			return;
		}

		$exists = false;

		foreach ( Redirects::all() as $id => $rule ) {
			if ( ! empty( $rule['is_regex'] ) ) {
				continue;
			}

			$rule_source = Redirects::normalize_path( (string) ( $rule['source'] ?? '' ) );
			$rule_target = Redirects::normalize_path( (string) ( $rule['target'] ?? '' ) );

			// A rule that sent the new address somewhere would now loop or chain.
			if ( $rule_source === $target ) {
				Redirects::delete( (int) $id );
				continue;
			}

			// An older redirect that ended at the old address now ends at the new one.
			if ( '' !== $rule_target && $rule_target === $source && (int) ( $rule['type'] ?? 301 ) < 400 ) {
				$rule['target'] = $to;
				Redirects::save( (int) $id, $rule );
				continue;
			}

			if ( $rule_source === $source ) {
				$exists         = true;
				$rule['target'] = $to;
				$rule['type']   = 301;
				$rule['active'] = true;
				Redirects::save( (int) $id, $rule );
			}
		}

		if ( ! $exists ) {
			Redirects::save(
				0,
				array(
					'source'   => $source,
					'target'   => $to,
					'type'     => 301,
					'active'   => true,
					'is_regex' => false,
				)
			);
		}
	}

	/**
	 * Rewrites links to old addresses in other content and in menu custom
	 * links. Returns how many posts or menu items changed.
	 *
	 * @param array<string,string> $pairs Old URL => new URL.
	 */
	private function rewrite_links( array $pairs ): int {
		global $wpdb;

		$changed = 0;
		$home    = home_url( '/' );

		foreach ( array_keys( $pairs ) as $old ) {
			$path = (string) wp_parse_url( $old, PHP_URL_PATH );
			if ( '' === $path || '/' === $path ) {
				continue;
			}

			$like = '%' . $wpdb->esc_like( $path ) . '%';

			// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a one-off, admin-triggered rewrite of stored content; core has no API for a bulk content search.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status NOT IN ('auto-draft','trash','inherit') AND post_type NOT IN ('revision','nav_menu_item','customize_changeset','oembed_cache') LIMIT %d",
					$like,
					self::MAX_POSTS
				)
			);

			foreach ( (array) $rows as $row ) {
				$content = self::replace_links( (string) $row->post_content, $pairs, $home );
				if ( $content !== $row->post_content ) {
					$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => (int) $row->ID ) );
					clean_post_cache( (int) $row->ID );
					++$changed;
				}
			}

			$items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_menu_item_url' AND meta_value LIKE %s LIMIT %d",
					$like,
					self::MAX_POSTS
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery

			foreach ( (array) $items as $item ) {
				$url = self::replace_links( (string) $item->meta_value, $pairs, $home );
				if ( $url !== $item->meta_value ) {
					update_post_meta( (int) $item->post_id, '_menu_item_url', $url );
					++$changed;
				}
			}
		}

		return $changed;
	}

	/**
	 * Replaces links to old addresses with the new ones in a block of
	 * content. Handles the absolute address (either scheme), the
	 * protocol-relative form and the root-relative path, and only matches
	 * a whole address — "/a/" inside "/a/b/" or "/a-b/" stays untouched.
	 * Pure string logic, independent of WordPress.
	 *
	 * @param string               $content Content (HTML, block markup, or a single URL).
	 * @param array<string,string> $pairs   Old absolute URL => new absolute URL.
	 * @param string               $home    The site's home URL (used to tell this site's addresses from others').
	 */
	public static function replace_links( string $content, array $pairs, string $home ): string {
		$host = (string) parse_url( $home, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing, no WordPress dependency needed here.

		uksort(
			$pairs,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		foreach ( $pairs as $old => $new ) {
			$old_path = (string) parse_url( $old, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing.
			$new_path = (string) parse_url( $new, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- pure string parsing.
			if ( '' === $old_path || '/' === $old_path || '' === $host ) {
				continue;
			}

			$after = '(?![A-Za-z0-9_\-.%])';

			// Absolute, either scheme, and protocol-relative.
			foreach ( array( 'https://', 'http://', '//' ) as $prefix ) {
				$from    = $prefix . $host . $old_path;
				$to      = $prefix . $host . $new_path;
				$content = (string) preg_replace_callback(
					'~(?<![A-Za-z0-9_\-.:/])' . preg_quote( $from, '~' ) . $after . '~',
					static function () use ( $to ): string {
						return $to;
					},
					$content
				);
			}

			// Root-relative, only where an attribute value or a list item starts.
			$content = (string) preg_replace_callback(
				'~(?<=["\'(=\s,])' . preg_quote( $old_path, '~' ) . $after . '~',
				static function () use ( $new_path ): string {
					return $new_path;
				},
				$content
			);
		}

		return $content;
	}

	/**
	 * Whether a boolean setting is on (on when never saved).
	 *
	 * @param string $key Settings key.
	 */
	private function setting( string $key ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return ! is_array( $settings ) || ! array_key_exists( $key, $settings ) || ! empty( $settings[ $key ] );
	}

	/**
	 * Appends an entry to the change log.
	 *
	 * @param string $old      Old address of the page that was edited.
	 * @param string $target   New address.
	 * @param int    $pages    Addresses affected (the page plus any below it).
	 * @param int    $updated  Posts and menu items whose links were rewritten.
	 * @param bool   $redirect Whether redirects were created.
	 */
	private function log( string $old, string $target, int $pages, int $updated, bool $redirect ): void {
		$log   = get_option( self::LOG_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'     => time(),
			'old'      => $old,
			'new'      => $target,
			'pages'    => $pages,
			'updated'  => $updated,
			'redirect' => $redirect,
		);

		update_option( self::LOG_OPTION, array_slice( $log, -self::LOG_LIMIT ), false );
	}

	/**
	 * The change log, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function log_entries(): array {
		$log = get_option( self::LOG_OPTION, array() );

		return is_array( $log ) ? array_reverse( $log ) : array();
	}
}

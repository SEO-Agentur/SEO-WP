<?php
/**
 * Object-cache fragment storage for rendered head output, keyed per object and language.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Support;

use Seooptimierung\Toolkit\Wpml\Hreflang;
use WP_Post;
use WP_Term;

/**
 * Caches the string output of an expensive-to-compute head fragment (the
 * JSON-LD schema graph, the WPML hreflang lookup) in the object cache,
 * keyed by fragment name + current object + current language. Invalidated
 * on the next save/delete of a post or term, site-wide — coarser than
 * per-object invalidation, but simple and correct.
 */
final class FragmentCache {

	/**
	 * Object cache group for rendered head fragments.
	 */
	private const GROUP = 'seo_wp_fragments';

	/**
	 * How long a fragment stays cached.
	 */
	private const TTL = DAY_IN_SECONDS;

	/**
	 * Registers cache invalidation on content changes that could affect
	 * any cached fragment.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'maybe_invalidate_on_save' ) );
		add_action( 'deleted_post', array( $this, 'invalidate' ) );
		add_action( 'edited_term', array( $this, 'invalidate' ) );
		add_action( 'delete_term', array( $this, 'invalidate' ) );
	}

	/**
	 * Invalidates on a real save only. save_post fires unconditionally for
	 * every wp_insert_post() call, including autosaves and revisions —
	 * without this guard, any editor's normal autosave loop would keep
	 * every cached fragment perpetually cold (same issue as, and fixed the
	 * same way as, Sitemaps\Router::maybe_invalidate_cache_on_save()).
	 *
	 * @param int $post_id Post being saved.
	 */
	public function maybe_invalidate_on_save( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$this->invalidate();
	}

	/**
	 * Returns the cached fragment, or runs $generator() and caches its
	 * string result.
	 *
	 * @param string   $fragment  Fragment name, e.g. 'schema' or 'hreflang'.
	 * @param callable $generator Produces the fragment when not cached.
	 */
	public function remember( string $fragment, callable $generator ): string {
		$key    = $this->key( $fragment );
		$found  = false;
		$cached = wp_cache_get( $key, self::GROUP, false, $found );

		if ( $found ) {
			return $cached;
		}

		$value = (string) $generator();

		wp_cache_set( $key, $value, self::GROUP, self::TTL );

		return $value;
	}

	/**
	 * Flushes every cached fragment.
	 */
	public function invalidate(): void {
		wp_cache_flush_group( self::GROUP );
	}

	/**
	 * Builds the cache key for a fragment on the current request.
	 *
	 * @param string $fragment Fragment name.
	 */
	private function key( string $fragment ): string {
		return $fragment . ':' . $this->object_key() . ':' . $this->language();
	}

	/**
	 * Identifies the current object, falling back to a hash of the
	 * current URL for requests without a single backing object.
	 */
	private function object_key(): string {
		$object_id = $this->current_object_id();

		return null !== $object_id ? (string) $object_id : md5( Url::current() );
	}

	/**
	 * Resolves the current request's object ID, when it has one.
	 */
	private function current_object_id(): ?int {
		if ( is_singular() ) {
			$post = get_queried_object();
			return $post instanceof WP_Post ? $post->ID : null;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return $term instanceof WP_Term ? $term->term_id : null;
		}

		return null;
	}

	/**
	 * Resolves the current language, when WPML or Polylang (or a
	 * compatible plugin) is active.
	 */
	private function language(): string {
		return Hreflang::current_language();
	}
}

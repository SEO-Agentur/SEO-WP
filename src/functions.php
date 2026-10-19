<?php
/**
 * Template functions for themes and other plugins.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'seooptimierung_breadcrumbs' ) ) {
	/**
	 * Prints (or returns) the breadcrumbs for the current page.
	 *
	 * @param array<string,mixed> $args Optional: separator, home_label, link_current (bool), class.
	 * @param bool                $display Print the markup (default) or return it.
	 * @return string The markup ('' when there is no trail).
	 */
	function seooptimierung_breadcrumbs( array $args = array(), bool $display = true ): string {
		$html = \Seooptimierung\Toolkit\Support\Breadcrumbs::render( $args );

		if ( '' !== $html ) {
			\Seooptimierung\Toolkit\Support\Breadcrumbs::enqueue_style();
		}

		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Breadcrumbs::render() escapes every value.
		}

		return $html;
	}
}

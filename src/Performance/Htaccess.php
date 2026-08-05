<?php
/**
 * Optional .htaccess rules for Apache/LiteSpeed: Gzip, Brotli, browser
 * caching (Expires/Cache-Control), hotlink protection and disabling
 * directory browsing.
 *
 * @package SeoWp
 */

declare(strict_types=1);

namespace SeoWp\Performance;

/**
 * Unlike ServerHeaders (pure PHP, works on any web server), these five
 * features genuinely need web-server-level configuration — there's no
 * reliable way to make Apache compress a static .css file, or add an
 * Expires header to a static .jpg, from inside a PHP request that never
 * runs for that file in the first place. This writes a single marked
 * block into the site's .htaccess using WordPress core's own
 * insert_with_markers() — the same safe mechanism core itself uses for
 * its rewrite-rules block, touching only the marked region — wrapping
 * every directive in <IfModule> so a module the host doesn't have is
 * simply skipped rather than causing a 500. Nothing here runs
 * automatically: the admin screen only writes rules when the site owner
 * explicitly clicks "Apply to .htaccess", never on a plain settings save.
 */
final class Htaccess {

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Marker this plugin's block is bounded by in .htaccess, e.g.
	 * "# BEGIN SEO-WP Performance" / "# END SEO-WP Performance".
	 */
	public const MARKER = 'SEO-WP Performance';

	/**
	 * The plugin settings array.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether a given boolean setting is turned on (off by default).
	 *
	 * @param string $key Settings key.
	 */
	private function enabled( string $key ): bool {
		return ! empty( $this->settings()[ $key ] );
	}

	/**
	 * Whether this server is expected to read a .htaccess file at all.
	 * On Nginx (or anything else) .htaccess is inert — writing to it
	 * would do nothing.
	 */
	public function server_supports_htaccess(): bool {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';

		return false !== stripos( $software, 'apache' ) || false !== stripos( $software, 'litespeed' );
	}

	/**
	 * Absolute path to the site's .htaccess, matching where WordPress
	 * core itself writes its rewrite rules.
	 */
	public function htaccess_path(): string {
		return ( defined( 'ABSPATH' ) ? ABSPATH : '' ) . '.htaccess';
	}

	/**
	 * Builds the rules block for whichever toggles are currently on —
	 * also used by the admin screen to preview the rules before (or
	 * without) writing anything.
	 */
	public function build_rules(): string {
		$blocks = array();

		if ( $this->enabled( 'enable_gzip' ) ) {
			$blocks[] = $this->gzip_block();
		}
		if ( $this->enabled( 'enable_brotli' ) ) {
			$blocks[] = $this->brotli_block();
		}
		if ( $this->enabled( 'enable_browser_caching' ) ) {
			$blocks[] = $this->browser_caching_block();
		}
		if ( $this->enabled( 'enable_hotlink_protection' ) ) {
			$blocks[] = $this->hotlink_protection_block();
		}
		if ( $this->enabled( 'disable_directory_browsing' ) ) {
			$blocks[] = "<IfModule mod_autoindex.c>\n\tOptions -Indexes\n</IfModule>";
		}

		return implode( "\n\n", $blocks );
	}

	/**
	 * The Gzip (mod_deflate) rules block.
	 */
	private function gzip_block(): string {
		return "<IfModule mod_deflate.c>\n"
			. "\tAddOutputFilterByType DEFLATE text/html text/plain text/css text/xml text/javascript\n"
			. "\tAddOutputFilterByType DEFLATE application/javascript application/x-javascript application/xml application/json application/rss+xml application/atom+xml application/font-woff application/vnd.ms-fontobject\n"
			. "\tAddOutputFilterByType DEFLATE image/svg+xml\n"
			. '</IfModule>';
	}

	/**
	 * The Brotli (mod_brotli) rules block.
	 */
	private function brotli_block(): string {
		return "<IfModule mod_brotli.c>\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/xml text/javascript\n"
			. "\tAddOutputFilterByType BROTLI_COMPRESS application/javascript application/x-javascript application/xml application/json application/rss+xml application/atom+xml\n"
			. '</IfModule>';
	}

	/**
	 * The browser-caching (mod_expires + mod_headers) rules block.
	 */
	private function browser_caching_block(): string {
		return "<IfModule mod_expires.c>\n"
			. "\tExpiresActive On\n"
			. "\tExpiresByType image/jpg \"access plus 1 year\"\n"
			. "\tExpiresByType image/jpeg \"access plus 1 year\"\n"
			. "\tExpiresByType image/png \"access plus 1 year\"\n"
			. "\tExpiresByType image/webp \"access plus 1 year\"\n"
			. "\tExpiresByType image/svg+xml \"access plus 1 year\"\n"
			. "\tExpiresByType image/x-icon \"access plus 1 year\"\n"
			. "\tExpiresByType font/woff2 \"access plus 1 year\"\n"
			. "\tExpiresByType application/font-woff \"access plus 1 year\"\n"
			. "\tExpiresByType text/css \"access plus 1 month\"\n"
			. "\tExpiresByType application/javascript \"access plus 1 month\"\n"
			. "\tExpiresByType text/javascript \"access plus 1 month\"\n"
			. "\tExpiresByType text/html \"access plus 0 seconds\"\n"
			. "</IfModule>\n"
			. "<IfModule mod_headers.c>\n"
			. "\t<FilesMatch \"\\.(jpg|jpeg|png|gif|webp|svg|ico|woff|woff2|css|js)$\">\n"
			. "\t\tHeader set Cache-Control \"public, max-age=31536000, immutable\"\n"
			. "\t</FilesMatch>\n"
			. '</IfModule>';
	}

	/**
	 * The hotlink-protection (mod_rewrite) rules block.
	 */
	private function hotlink_protection_block(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_quote( $host, '#' );

		return "<IfModule mod_rewrite.c>\n"
			. "\tRewriteEngine On\n"
			. "\tRewriteCond %{HTTP_REFERER} !^$\n"
			. "\tRewriteCond %{HTTP_REFERER} !^https?://([^.]+\\.)?{$host}/ [NC]\n"
			. "\tRewriteRule \\.(jpe?g|png|gif|webp|svg)$ - [NC,F,L]\n"
			. '</IfModule>';
	}

	/**
	 * Writes (or, when every toggle is off, removes) the marked block.
	 *
	 * @return true|\WP_Error
	 */
	public function write() {
		if ( ! $this->server_supports_htaccess() ) {
			return new \WP_Error(
				'seo_wp_htaccess_unsupported',
				__( 'This server doesn\'t appear to be Apache or LiteSpeed — .htaccess has no effect here. Use the shown snippet in your server\'s own configuration instead.', 'seo-wp' )
			);
		}

		$path             = $this->htaccess_path();
		$file_exists      = file_exists( $path );
		$directory_usable = is_writable( dirname( $path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a permissions pre-check for insert_with_markers() below, which itself uses direct filesystem calls the same way; WP_Filesystem isn't initialized this early in an admin-post handler.

		if ( ! $file_exists && ! $directory_usable ) {
			return new \WP_Error(
				'seo_wp_htaccess_unwritable',
				__( '.htaccess doesn\'t exist and its directory isn\'t writable by PHP — create the file manually and try again.', 'seo-wp' )
			);
		}
		if ( $file_exists && ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- see above.
			return new \WP_Error(
				'seo_wp_htaccess_unwritable',
				__( '.htaccess isn\'t writable by PHP — fix its file permissions and try again.', 'seo-wp' )
			);
		}

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$rules  = $this->build_rules();
		$lines  = '' === $rules ? array() : explode( "\n", $rules );
		$result = insert_with_markers( $path, self::MARKER, $lines );

		return $result ? true : new \WP_Error( 'seo_wp_htaccess_write_failed', __( 'Writing to .htaccess failed for an unknown reason.', 'seo-wp' ) );
	}
}

<?php
/**
 * Alt text helpers: filling gaps on upload and on output, and the pure
 * string logic behind it.
 *
 * @package Seooptimierung\Toolkit
 */

declare(strict_types=1);

namespace Seooptimierung\Toolkit\Media;

/**
 * Two opt-in conveniences (both off by default) plus the helpers the
 * Image SEO screen shares with them:
 *
 * - "Fill alt text on upload": a new image without alt text gets one made
 *   from its file name.
 * - "Output a fallback alt": an image that reaches the page without any alt
 *   attribute is given one from its title, so it never goes out unlabelled.
 *   An alt that is present — including a deliberate empty alt="" — is never
 *   touched, and images marked decorative are left alone.
 */
final class AltText {

	/**
	 * Attachment meta: the image is decorative, an empty alt is intended.
	 */
	public const META_DECORATIVE = '_seo_wp_alt_decorative';

	/**
	 * Option key holding the plugin's settings array.
	 */
	private const OPTION_KEY = 'seo_wp_settings';

	/**
	 * Registers the upload and output hooks.
	 */
	public function register(): void {
		add_action( 'add_attachment', array( $this, 'maybe_fill_on_upload' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'maybe_fallback_attributes' ), 10, 2 );
		add_filter( 'wp_content_img_tag', array( $this, 'maybe_fallback_content_tag' ), 10, 3 );
	}

	/**
	 * Gives a freshly uploaded image an alt text made from its file name.
	 *
	 * @param int $attachment_id New attachment.
	 */
	public function maybe_fill_on_upload( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;

		if ( ! $this->enabled( 'image_alt_on_upload' ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}

		if ( '' !== (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) {
			return;
		}

		$alt = self::humanize( basename( (string) get_attached_file( $attachment_id ) ) );
		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	/**
	 * Fills an empty alt on an attachment image's attributes.
	 *
	 * @param array<string,string> $attr       Image attributes.
	 * @param \WP_Post             $attachment The attachment.
	 * @return array<string,string>
	 */
	public function maybe_fallback_attributes( $attr, $attachment ) {
		if ( ! is_array( $attr ) || ! $attachment instanceof \WP_Post || ! $this->enabled( 'image_alt_fallback' ) ) {
			return $attr;
		}

		if ( '' === trim( (string) ( $attr['alt'] ?? '' ) ) && '1' !== (string) get_post_meta( $attachment->ID, self::META_DECORATIVE, true ) ) {
			$attr['alt'] = self::fallback_for( $attachment->ID );
		}

		return $attr;
	}

	/**
	 * Adds an alt attribute to an <img> in content that has none at all.
	 *
	 * @param string $html          The <img> tag.
	 * @param string $context       Filter context (unused).
	 * @param int    $attachment_id Attachment ID, or 0.
	 * @return string
	 */
	public function maybe_fallback_content_tag( $html, $context = '', $attachment_id = 0 ) {
		unset( $context );

		if ( ! is_string( $html ) || ! $this->enabled( 'image_alt_fallback' ) || (int) $attachment_id <= 0 ) {
			return $html;
		}

		// An alt attribute that exists (even empty) was a decision; only a missing one is filled.
		if ( 1 === preg_match( '/\salt\s*=/i', $html ) || '1' === (string) get_post_meta( (int) $attachment_id, self::META_DECORATIVE, true ) ) {
			return $html;
		}

		$alt = self::fallback_for( (int) $attachment_id );

		return '' === $alt ? $html : (string) preg_replace( '/<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $html, 1 );
	}

	/**
	 * The text used when an image has no alt: its title, or its parent
	 * post's title.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function fallback_for( int $attachment_id ): string {
		$title = self::humanize( (string) get_the_title( $attachment_id ) );
		if ( '' !== $title ) {
			return $title;
		}

		$parent = (int) wp_get_post_parent_id( $attachment_id );

		return $parent > 0 ? trim( wp_strip_all_tags( (string) get_the_title( $parent ) ) ) : '';
	}

	/**
	 * Turns a file name or title into readable words: extension and WordPress'
	 * "-scaled"/size suffixes removed, separators to spaces. A name that is
	 * only a camera counter ("IMG_1234", "DSC0042", "Screenshot 2026-10-09")
	 * says nothing, so it yields ''. Pure string logic.
	 *
	 * @param string $name File name or title.
	 */
	public static function humanize( string $name ): string {
		$name = (string) preg_replace( '/\.(jpe?g|png|gif|webp|avif|svg|bmp|tiff?)$/i', '', trim( $name ) );
		$name = (string) preg_replace( '/-(scaled|rotated|e\d{10,})$/i', '', $name );
		$name = (string) preg_replace( '/-\d{2,5}x\d{2,5}$/', '', $name );
		$name = trim( (string) preg_replace( '/[\s_\-.]+/', ' ', $name ) );

		if ( '' === $name || 1 === preg_match( '/^(?:img|dsc|dscn|dscf|pxl|mvimg|screenshot|screen shot|bild|foto|photo|image|picture|unbenannt|untitled|whatsapp image)?[\s\d\-at]*$/i', $name ) ) {
			return '';
		}

		return $name;
	}

	/**
	 * Builds the prompt asking an AI model for an alt text.
	 *
	 * @param string $title    Attachment title.
	 * @param string $caption  Attachment caption.
	 * @param string $context  Title of the post the image belongs to, or ''.
	 * @param string $language Language name or locale, e.g. "German (de_DE)".
	 */
	public static function build_prompt( string $title, string $caption, string $context, string $language ): string {
		$lines   = array();
		$lines[] = 'Write the alt text for the attached image, for a website.';
		$lines[] = 'Rules: describe what is actually visible, concretely and briefly (at most 125 characters); do not start with "Image of" or "Picture of"; no quotation marks; write it in ' . $language . '.';
		$lines[] = 'Reply with the alt text only.';

		if ( '' !== $title ) {
			$lines[] = 'File title: ' . $title;
		}
		if ( '' !== $caption ) {
			$lines[] = 'Caption: ' . $caption;
		}
		if ( '' !== $context ) {
			$lines[] = 'The image is used on a page titled: ' . $context;
		}

		return implode( "\n", $lines );
	}

	/**
	 * Cleans a model's alt-text reply: no quotes or markdown, one line,
	 * capped at 125 characters (cut at a word boundary).
	 *
	 * @param string $reply Raw reply.
	 */
	public static function clean_reply( string $reply ): string {
		$reply = trim( (string) preg_replace( '/\s+/', ' ', $reply ) );
		$reply = trim( $reply, " \t\n\r\0\x0B\"'`*_" );
		$reply = (string) preg_replace( '/^(?:alt(?:\s*text)?\s*:\s*)/i', '', $reply );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $reply ) > 125 ) {
			$cut   = mb_substr( $reply, 0, 125 );
			$space = mb_strrpos( $cut, ' ' );
			$reply = rtrim( false !== $space && $space > 60 ? mb_substr( $cut, 0, $space ) : $cut, ' ,;:-' );
		}

		return $reply;
	}

	/**
	 * Whether a boolean plugin setting is on (off when never saved).
	 *
	 * @param string $key Settings key.
	 */
	private function enabled( string $key ): bool {
		$settings = get_option( self::OPTION_KEY, array() );

		return is_array( $settings ) && ! empty( $settings[ $key ] );
	}
}

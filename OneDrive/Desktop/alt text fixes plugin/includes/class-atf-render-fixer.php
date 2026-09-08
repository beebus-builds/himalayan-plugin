<?php
/**
 * Front-end rendered HTML fixer.
 *
 * WordPress attachment metadata alone does not fix hard-coded <img> tags,
 * page-builder output, shortcode output, widgets, or theme-generated markup.
 * This class fixes the final HTML sent to visitors by adding alt attributes to
 * attachment images that are missing the attribute.
 *
 * Existing alt="" is intentionally preserved because it can be a valid
 * decorative-image decision.
 */
class ATF_Render_Fixer {

	public static function init() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
	}

	public static function start_buffer() {
		if ( headers_sent() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'fix_html' ) );
	}

	/**
	 * Add missing alt attributes to attachment images in rendered HTML.
	 *
	 * @param string $html Complete response body.
	 * @return string
	 */
	public static function fix_html( $html ) {
		if ( '' === $html || false === stripos( $html, '<img' ) ) {
			return $html;
		}

		$fixer = Alt_Text_Fixer::init();

		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $match ) use ( $fixer ) {
				$tag = $match[0];

				// Do not overwrite an existing alt, including alt="".
				if ( preg_match( '/\s+alt\s*=\s*(["\'])(.*?)\1/i', $tag ) ) {
					return $tag;
				}

				$src = self::get_image_src( $tag );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) ) {
					return $tag;
				}

				$attachment_id = attachment_url_to_postid( $src );
				if ( ! $attachment_id ) {
					$clean = preg_replace( '/[?#].*$/', '', $src );
					if ( $clean !== $src ) {
						$attachment_id = attachment_url_to_postid( $clean );
					}
				}

				if ( ! $attachment_id || 'image' !== substr( (string) get_post_mime_type( $attachment_id ), 0, 5 ) ) {
					return $tag;
				}

				$alt = trim( wp_strip_all_tags( (string) $fixer->generate_alt_text( $attachment_id ) ) );
				if ( '' === $alt || $fixer->is_generic( $alt ) ) {
					return $tag;
				}

				// Inject into the final markup so page builders/theme output is fixed.
				$tag = preg_replace( '/\s*\/?>$/', ' alt="' . esc_attr( $alt ) . '">', $tag );

				// Persist the fix so Media Library and future audits also become clean.
				if ( ! Alt_Text_Fixer::has_alt( $attachment_id ) ) {
					update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
				}

				return $tag;
			},
			$html
		);
	}

	/**
	 * Extract an image URL from src, falling back to the first srcset URL.
	 *
	 * @param string $tag Image tag.
	 * @return string
	 */
	private static function get_image_src( $tag ) {
		if ( preg_match( '/\s+src\s*=\s*(["\'])(.*?)\1/i', $tag, $match ) ) {
			return html_entity_decode( trim( $match[2] ), ENT_QUOTES, 'UTF-8' );
		}

		if ( preg_match( '/\s+srcset\s*=\s*(["\'])(.*?)\1/i', $tag, $match ) ) {
			$first = trim( preg_split( '/\s*,\s*/', $match[2] )[0] );
			$first = preg_split( '/\s+/', $first )[0];
			return html_entity_decode( trim( $first ), ENT_QUOTES, 'UTF-8' );
		}

		return '';
	}
}

ATF_Render_Fixer::init();

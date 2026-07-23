<?php
/**
 * Scans post/page content AND post meta for <img> tags / image URLs that are
 * NOT media-library attachments, and fixes missing alt text.
 *
 * Scopes handled:
 *  - content : the post_content body (raw HTML, page-builder shortcodes, etc.)
 *  - meta    : post meta values (Elementor/ACF/etc. often store image URLs or
 *              HTML fragments in meta). Both serialized and plain strings are
 *              scanned and rewritten in place.
 *  - css     : inline style="background-image:url(...)" and <style> blocks.
 *              CSS images cannot carry an alt attribute, so we make them
 *              decorative (role="presentation" aria-hidden="true" on the host
 *              element) which removes them from the a11y/SEO "missing alt"
 *              audit. A setting controls whether to instead attempt conversion
 *              to an <img>.
 *
 * The fix rewrites data in place, only touching images that lack alt. Images
 * that already have a non-empty alt are left untouched.
 */
class ATF_Content_Fixer {

	const BATCH_SIZE = 20;

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_atf_content_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'wp_ajax_atf_content_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_atf_meta_count', array( __CLASS__, 'ajax_meta_count' ) );
		add_action( 'wp_ajax_atf_meta_batch', array( __CLASS__, 'ajax_meta_batch' ) );
		add_action( 'wp_ajax_atf_css_count', array( __CLASS__, 'ajax_css_count' ) );
		add_action( 'wp_ajax_atf_css_batch', array( __CLASS__, 'ajax_css_batch' ) );
		add_action( 'wp_ajax_atf_global_count', array( __CLASS__, 'ajax_global_count' ) );
		add_action( 'wp_ajax_atf_global_batch', array( __CLASS__, 'ajax_global_batch' ) );

		// Background workers.
		add_action( 'atf_fix_post_content', array( __CLASS__, 'do_fix_post_content' ), 10, 1 );
		add_action( 'atf_fix_post_meta', array( __CLASS__, 'do_fix_post_meta' ), 10, 1 );
		add_action( 'atf_fix_post_css', array( __CLASS__, 'do_fix_post_css' ), 10, 1 );
		add_action( 'atf_fix_global_option', array( __CLASS__, 'do_fix_global_option' ), 10, 1 );
	}

	/**
	 * Workers for Action Scheduler.
	 */
	public static function do_fix_post_content( $post_id ) {
		self::fix_post_scope( $post_id, 'content', Alt_Text_Fixer::init() );
	}
	public static function do_fix_post_meta( $post_id ) {
		self::fix_post_scope( $post_id, 'meta', Alt_Text_Fixer::init() );
	}
	public static function do_fix_post_css( $post_id ) {
		self::fix_post_scope( $post_id, 'css', Alt_Text_Fixer::init() );
	}
	public static function do_fix_global_option( $option_name ) {
		self::fix_global( 'global', Alt_Text_Fixer::init(), array( $option_name ) );
	}

	/* ----------------------------------------------------------------------
	 * AJAX: content
	 * -------------------------------------------------------------------- */

	public static function ajax_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_scope( 'content' ) ) );
	}

	public static function ajax_batch() {
		self::run_scope( 'content' );
	}

	/* ----------------------------------------------------------------------
	 * AJAX: meta
	 * -------------------------------------------------------------------- */

	public static function ajax_meta_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_scope( 'meta' ) ) );
	}

	public static function ajax_meta_batch() {
		self::run_scope( 'meta' );
	}

	/* ----------------------------------------------------------------------
	 * AJAX: css
	 * -------------------------------------------------------------------- */

	public static function ajax_css_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_scope( 'css' ) ) );
	}

	public static function ajax_css_batch() {
		self::run_scope( 'css' );
	}

	/**
	 * Get the list of excluded IDs.
	 *
	 * @return int[]
	 */
	public static function get_excluded_ids() {
		$opts = get_option( 'atf_settings', array() );
		if ( empty( $opts['exclusions'] ) ) {
			return array();
		}
		$raw = explode( ',', $opts['exclusions'] );
		return array_map( 'intval', array_filter( array_map( 'trim', $raw ) ) );
	}

	/**
	 * Shared batch runner for a given scope.
	 *
	 * @param string $scope content | meta | css.
	 */
	public static function run_scope( $scope ) {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}

		$fixer = Alt_Text_Fixer::init();
		$excluded = self::get_excluded_ids();

		// Global scope (wp_options) is paged by option, not by post.
		if ( 'global' === $scope ) {
			$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
			$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : self::BATCH_SIZE;
			if ( $limit < 1 || $limit > 200 ) {
				$limit = self::BATCH_SIZE;
			}
			$names     = array_slice( self::global_option_names(), $offset, $limit );
			$queued = 0;
			foreach ( $names as $name ) {
				as_enqueue_async_action( 'atf_fix_global_option', array( $name ) );
				$queued++;
			}
			$remaining = self::count_scope( 'global' );
			$processed = $offset + count( $names );
			wp_send_json_success(
				array(
					'queued'    => $queued,
					'processed' => $processed,
					'remaining' => $remaining,
					'finished'  => $remaining <= 0,
				)
			);
			return;
		}

		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : self::BATCH_SIZE;
		if ( $limit < 1 || $limit > 200 ) {
			$limit = self::BATCH_SIZE;
		}

		$posts = self::get_posts( $offset, $limit );

		$queued = 0;
		foreach ( $posts as $post_id ) {
			if ( in_array( $post_id, $excluded, true ) ) {
				continue;
			}
			as_enqueue_async_action( 'atf_fix_post_' . $scope, array( $post_id ) );
			$queued++;
		}

		$remaining = self::count_scope( $scope );
		$processed = $offset + count( $posts );

		wp_send_json_success(
			array(
				'queued'    => $queued,
				'processed' => $processed,
				'remaining' => $remaining,
				'finished'  => $remaining <= 0,
			)
		);
	}

	/**
	 * Count how many items still need work for a scope.
	 *
	 * @param string $scope content | meta | css | global.
	 * @return int
	 */
	public static function count_scope( $scope ) {
		if ( 'global' === $scope ) {
			return self::count_global_options();
		}
		$excluded = self::get_excluded_ids();
		$posts = self::get_posts( 0, -1 );
		$count = 0;
		foreach ( $posts as $post_id ) {
			if ( in_array( $post_id, $excluded, true ) ) {
				continue;
			}
			if ( self::post_needs_scope( $post_id, $scope ) ) {
				$count ++;
			}
		}
		return $count;
	}

	/**
	 * Does a post still have work to do in the given scope?
	 *
	 * @param int    $post_id Post ID.
	 * @param string $scope   content | meta | css.
	 * @return bool
	 */
	public static function post_needs_scope( $post_id, $scope ) {
		if ( 'global' === $scope ) {
			return false; // handled separately in count_global_options().
		}
		if ( 'content' === $scope ) {
			$post = get_post( $post_id );
			return $post && self::html_has_missing_alt( $post->post_content, $post_id );
		}
		if ( 'meta' === $scope ) {
			return self::meta_has_missing_alt( $post_id );
		}
		if ( 'css' === $scope ) {
			return self::post_has_decorative_needed( $post_id );
		}
		return false;
	}

	/**
	 * Fix a single post in a single scope. Returns number of items fixed.
	 *
	 * @param int            $post_id Post ID.
	 * @param string         $scope   content | meta | css.
	 * @param Alt_Text_Fixer $fixer   Fixer instance.
	 * @return int
	 */
	public static function fix_post_scope( $post_id, $scope, $fixer ) {
		if ( 'content' === $scope ) {
			return self::fix_html_in_post_content( $post_id, $fixer );
		}
		if ( 'meta' === $scope ) {
			return self::fix_meta( $post_id, $fixer );
		}
		if ( 'css' === $scope ) {
			return self::fix_css( $post_id, $fixer );
		}
		return 0;
	}

	/* ----------------------------------------------------------------------
	 * HTML (content + meta HTML fragments)
	 * -------------------------------------------------------------------- */

	/**
	 * Fix <img> tags missing alt inside post_content.
	 *
	 * @param int            $post_id Post ID.
	 * @param Alt_Text_Fixer $fixer   Fixer instance.
	 * @return int
	 */
	public static function fix_html_in_post_content( $post_id, $fixer ) {
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, '<img' ) ) {
			return 0;
		}
		$new = self::fix_html_imgs( $post->post_content, $post_id, $fixer, $fixed );
		if ( $fixed > 0 ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $new,
				)
			);
		}
		return $fixed;
	}

	/**
	 * Rewrite <img> tags missing alt within an HTML string.
	 *
	 * @param string         $html    HTML source.
	 * @param int            $post_id Context post ID.
	 * @param Alt_Text_Fixer $fixer   Fixer instance.
	 * @param int            $fixed   Set by reference: count fixed.
	 * @return string
	 */
	public static function fix_html_imgs( $html, $post_id, $fixer, &$fixed = 0 ) {
		$fixed = 0;
		if ( false === strpos( $html, '<img' ) ) {
			return $html;
		}
		return preg_replace_callback(
			'/<img\b[^>]*?>/i',
			function ( $m ) use ( $post_id, $fixer, &$fixed ) {
				$tag = $m[0];
				if ( preg_match( '/\balt\s*=\s*("|\')(.*?)\1/i', $tag, $am ) && '' !== trim( $am[2] ) ) {
					return $tag;
				}
				$src = self::get_attr( $tag, 'src' );
				if ( ! $src ) {
					return $tag;
				}
				if ( ! self::is_attachment_img( $src, $post_id ) ) {
					return $tag;
				}
				$alt = self::alt_for_attachment( $src, $fixer );
				if ( '' === $alt ) {
					return $tag;
				}
				$tag       = preg_replace( '/\s+alt\s*=\s*("|\')\1/i', '', $tag );
				$selfclose = (bool) preg_match( '/\s*\/\s*>$/', $tag );
				$tag       = preg_replace( '/\s*\/?\s*>$/', '', $tag );
				$tag       = trim( $tag ) . ' alt="' . esc_attr( $alt ) . '"' . ( $selfclose ? ' />' : '>' );
				$fixed ++;
				return $tag;
			},
			$html
		);
	}

	/**
	 * Whether an HTML string has a non-attachment <img> missing alt.
	 *
	 * @param string $html    HTML source.
	 * @param int    $post_id Context post ID.
	 * @return bool
	 */
	public static function html_has_missing_alt( $html, $post_id ) {
		if ( false === strpos( $html, '<img' ) ) {
			return false;
		}
		$found = false;
		preg_replace_callback(
			'/<img\b[^>]*?>/i',
			function ( $m ) use ( $post_id, &$found ) {
				$tag = $m[0];
				if ( preg_match( '/\balt\s*=\s*("|\')(.*?)\1/i', $tag, $am ) && '' !== trim( $am[2] ) ) {
					return $tag;
				}
				$src = self::get_attr( $tag, 'src' );
				if ( $src && self::is_attachment_img( $src, $post_id ) ) {
					$found = true;
				}
				return $tag;
			},
			$html
		);
		return $found;
	}

	/* ----------------------------------------------------------------------
	 * Meta fields (page builders / ACF)
	 * -------------------------------------------------------------------- */

	/**
	 * Fix <img> tags (and bare image URLs) missing alt inside post meta.
	 *
	 * Scans every meta value for the post. HTML strings are rewritten via
	 * fix_html_imgs(); plain image URLs stored as meta are turned into an
	 * <img> with alt so they participate in a11y audits correctly.
	 *
	 * @param int            $post_id Post ID.
	 * @param Alt_Text_Fixer $fixer   Fixer instance.
	 * @return int
	 */
	public static function fix_meta( $post_id, $fixer ) {
		$meta = get_post_meta( $post_id );
		if ( empty( $meta ) ) {
			return 0;
		}
		$total_fixed = 0;

		foreach ( $meta as $key => $values ) {
			foreach ( $values as $value ) {
				// Serialized (Elementor/ACF often store arrays/objects).
				$decoded = self::maybe_unserialize_meta( $value );
				if ( false === $decoded ) {
					continue;
				}
				$replaced = self::walk_meta_value( $decoded, $post_id, $fixer, $fixed );
				$total_fixed += $fixed;
				if ( $replaced !== $decoded ) {
					update_post_meta( $post_id, $key, $replaced, $value );
				}
			}
		}
		return $total_fixed;
	}

	/**
	 * Recursively repair image alt inside a meta value (array/object/string).
	 *
	 * @param mixed          $value   Meta value.
	 * @param int            $post_id Context post ID.
	 * @param Alt_Text_Fixer $fixer   Fixer instance.
	 * @param int            $fixed   Set by reference.
	 * @return mixed
	 */
	public static function walk_meta_value( $value, $post_id, $fixer, &$fixed = 0 ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::walk_meta_value( $v, $post_id, $fixer, $fixed );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $k => $v ) {
				$value->$k = self::walk_meta_value( $v, $post_id, $fixer, $fixed );
			}
			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}

		// HTML fragment.
		if ( false !== strpos( $value, '<img' ) ) {
			return self::fix_html_imgs( $value, $post_id, $fixer, $fixed );
		}
		// Bare image URL stored as meta (e.g. _thumbnail_id-like custom fields
		// that hold a URL, or ACF image returning a URL).
		if ( preg_match( '#\.(?:jpe?g|png|gif|webp|svg|avif)(?:\?[^"\']*)?$#i', $value )
			&& filter_var( $value, FILTER_VALIDATE_URL ) ) {
			if ( self::is_attachment_img( $value, $post_id ) ) {
				return $value;
			}
			$alt = self::alt_from_src( $value, $fixer );
			if ( '' !== $alt ) {
				$fixed ++;
				return '<img src="' . esc_url( $value ) . '" alt="' . esc_attr( $alt ) . '">';
			}
		}
		return $value;
	}

	/**
	 * Whether any meta value for the post contains an img missing alt.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function meta_has_missing_alt( $post_id ) {
		$meta = get_post_meta( $post_id );
		if ( empty( $meta ) ) {
			return false;
		}
		foreach ( $meta as $values ) {
			foreach ( $values as $value ) {
				$decoded = self::maybe_unserialize_meta( $value );
				if ( false === $decoded ) {
					continue;
				}
				if ( self::meta_value_has_missing_alt( $decoded, $post_id ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Recursively check a meta value for missing alt.
	 *
	 * @param mixed $value   Meta value.
	 * @param int   $post_id Context post ID.
	 * @return bool
	 */
	public static function meta_value_has_missing_alt( $value, $post_id ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::meta_value_has_missing_alt( $v, $post_id ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_object( $value ) ) {
			foreach ( get_object_vars( $value ) as $v ) {
				if ( self::meta_value_has_missing_alt( $v, $post_id ) ) {
					return true;
				}
			}
			return false;
		}
		if ( ! is_string( $value ) ) {
			return false;
		}
		if ( false !== strpos( $value, '<img' ) ) {
			return self::html_has_missing_alt( $value, $post_id );
		}
		if ( preg_match( '#\.(?:jpe?g|png|gif|webp|svg|avif)(?:\?[^"\']*)?$#i', $value )
			&& filter_var( $value, FILTER_VALIDATE_URL )
			&& ! self::is_attachment_img( $value, $post_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Try to unserialize a meta string. Returns false if not serializable.
	 *
	 * @param string $value Raw meta value.
	 * @return mixed|false
	 */
	public static function maybe_unserialize_meta( $value ) {
		$trimmed = trim( $value );
		if ( ! is_string( $trimmed ) || ! in_array( $trimmed[0], array( 'a', 'O', 's', 'i', 'd', 'b', 'N' ), true ) ) {
			return $value; // Plain string (could be HTML or a URL).
		}
		$un = @unserialize( $trimmed );
		if ( false === $un && 'b:0;' !== $trimmed ) {
			return $value; // Not actually serialized.
		}
		return $un;
	}

	/* ----------------------------------------------------------------------
	 * CSS background images
	 * -------------------------------------------------------------------- */

	/**
	 * Fix CSS background-image usage so it is treated as decorative.
	 *
	 * CSS images can't hold alt text. We add role="presentation" and
	 * aria-hidden="true" to inline style host elements (the element carrying
	 * the background-image), and add aria-hidden="true" to elements that match
	 * selectors used inside <style> blocks. This removes them from "missing
	 * alt" a11y/SEO audits. Content is saved once at the end.
	 *
	 * @param int            $post_id Post ID.
	 * @param Alt_Text_Fixer $fixer   Fixer instance (unused but kept for API).
	 * @return int Number of elements marked decorative.
	 */
	public static function fix_css( $post_id, $fixer ) {
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, 'background' ) ) {
			return 0;
		}
		$html = $post->post_content;

		// Collect selectors (from <style> blocks) that use background-image on
		// non-attachment images, so we can flag matching elements once.
		$selectors = array();
		if ( false !== strpos( $html, '<style' ) ) {
			$html = preg_replace_callback(
				'/<style\b[^>]*>(.*?)<\/style>/is',
				function ( $m ) use ( &$selectors ) {
					$css = $m[1];
					preg_replace_callback(
						'/(?<!})\s*([^{}]+)\{([^}]*background-image[^}]*)\}/i',
						function ( $s ) use ( &$selectors ) {
							$sel  = trim( $s[1] );
							$body = $s[2];
							if ( preg_match( '/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $body, $um )
								&& ! self::is_attachment_img( $um[2], 0 ) ) {
								$selectors[] = $sel;
							}
							return $s[0];
						},
						$css
					);
					return $m[0];
				},
				$html
			);
		}

		$count = 0;

		// 1) Inline style="...background-image:url(...)" on host elements.
		if ( false !== strpos( $html, 'background' ) ) {
			$html = preg_replace_callback(
				'/<([a-zA-Z0-9]+)\b([^>]*?)\bstyle\s*=\s*("|\')([^>]*?background-image[^>]*?)\3([^>]*)>/i',
				function ( $m ) use ( &$count ) {
					$tag_open = $m[1];
					$before   = $m[2];
					$quote    = $m[3];
					$style    = $m[4];
					$after    = $m[5];
					if ( preg_match( '/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $style, $um )
						&& ! self::is_attachment_img( $um[2], 0 ) ) {
						$count ++;
						$before = self::add_decorative_attrs( $before );
					}
					return '<' . $tag_open . $before . 'style=' . $quote . $style . $quote . $after . '>';
				},
				$html
			);
		}

		// 2) Apply aria-hidden to elements matching collected selectors.
		foreach ( $selectors as $sel ) {
			$html = self::add_aria_hidden_to_selector( $html, $sel, $count );
		}

		if ( $count > 0 ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => $html,
				)
			);
		}

		return $count;
	}

	/**
	 * Add role="presentation" aria-hidden="true" to an opening-tag attribute
	 * fragment (the part before style=), avoiding duplicates.
	 *
	 * @param string $attrs Attribute fragment.
	 * @return string
	 */
	public static function add_decorative_attrs( $attrs ) {
		if ( false === strpos( $attrs, 'aria-hidden' ) ) {
			$attrs .= ' aria-hidden="true"';
		}
		if ( false === strpos( $attrs, 'role=' ) ) {
			$attrs .= ' role="presentation"';
		}
		return $attrs;
	}

	/**
	 * Add aria-hidden="true" to elements matching a CSS selector in the HTML.
	 * Supports simple selectors: tag, .class, #id, and combinations.
	 *
	 * @param string $html  HTML source.
	 * @param string $selector CSS selector.
	 * @param int    $count Set by reference.
	 * @return string
	 */
	public static function add_aria_hidden_to_selector( $html, $selector, &$count ) {
		$selector = trim( $selector );
		// Build a regex matching opening tags that satisfy the selector.
		$tag = 'div';
		$id  = '';
		$cls = array();
		if ( preg_match( '/^[a-zA-Z0-9]+/', $selector, $tm ) ) {
			$tag = $tm[0];
		}
		if ( preg_match( '/#([\w-]+)/', $selector, $im ) ) {
			$id = $im[1];
		}
		if ( preg_match_all( '/\.([\w-]+)/', $selector, $cm ) ) {
			$cls = $cm[1];
		}

		return preg_replace_callback(
			'/<' . preg_quote( $tag, '/' ) . '\b([^>]*)>/i',
			function ( $m ) use ( $id, $cls, &$count ) {
				$attrs = $m[1];
				if ( $id && false === strpos( $attrs, 'id="' . $id . '"' ) ) {
					return $m[0];
				}
				foreach ( $cls as $c ) {
					if ( false === strpos( $attrs, $c ) ) {
						return $m[0];
					}
				}
				if ( false !== strpos( $attrs, 'aria-hidden' ) ) {
					return $m[0];
				}
				$count ++;
				return '<' . $tag . $attrs . ' aria-hidden="true">';
			},
			$html
		);
	}

	/**
	 * Whether the post has CSS background images needing decoration.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_has_decorative_needed( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || false === strpos( $post->post_content, 'background' ) ) {
			return false;
		}
		$html = $post->post_content;
		// Inline style background-image without aria-hidden.
		if ( preg_match( '/<[a-zA-Z0-9]+\b[^>]*?\bstyle\s*=\s*("|\')[^>]*?background-image[^>]*?\1[^>]*?>/i', $html )
			&& false === strpos( $html, 'aria-hidden' ) ) {
			return true;
		}
		// <style> block with background-image (always counts; we mark selectors).
		if ( false !== strpos( $html, '<style' )
			&& preg_match( '/<style\b[^>]*>(.*?)<\/style>/is', $html, $sm )
			&& preg_match( '/background-image\s*:[^;]*url\(/i', $sm[1] ) ) {
			return true;
		}
		return false;
	}

	/* ----------------------------------------------------------------------
	 * Shared helpers
	 * -------------------------------------------------------------------- */

	/**
	 * Whether an <img> src belongs to a media-library attachment.
	 *
	 * @param string $src    Image URL.
	 * @param int    $post_id Context post ID.
	 * @return bool
	 */
	public static function is_attachment_img( $src, $post_id ) {
		if ( attachment_url_to_postid( $src ) ) {
			return true;
		}
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['baseurl'] ) && false !== strpos( $src, $uploads['baseurl'] ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Derive a humanized alt string from an image URL.
	 *
	 * @param string         $src   Image URL.
	 * @param Alt_Text_Fixer $fixer Fixer instance.
	 * @return string
	 */
	public static function alt_from_src( $src, $fixer ) {
		$path = parse_url( $src, PHP_URL_PATH );
		if ( ! $path ) {
			return '';
		}
		$base = basename( $path );
		$name = preg_replace( '/\.[^.]+$/', '', $base );
		if ( '' === trim( $name ) ) {
			return '';
		}
		return $fixer->humanize( $name );
	}

	/**
	 * Resolve the alt text for a media-library attachment image.
	 *
	 * Uses the attachment's existing alt meta when present; otherwise
	 * generates and stores one via the core fixer. Falls back to a
	 * filename-derived alt if the attachment cannot be resolved.
	 *
	 * @param string         $src   Image URL.
	 * @param Alt_Text_Fixer $fixer Fixer instance.
	 * @return string
	 */
	public static function alt_for_attachment( $src, $fixer ) {
		$id = attachment_url_to_postid( $src );
		if ( $id ) {
			$existing = get_post_meta( $id, '_wp_attachment_image_alt', true );
			if ( '' !== trim( (string) $existing ) ) {
				return $existing;
			}
			$generated = $fixer->generate_alt_text( $id );
			if ( '' !== trim( (string) $generated ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $generated );
				return $generated;
			}
		}
		return self::alt_from_src( $src, $fixer );
	}

	/**
	 * Get the value of a given attribute from an <img> tag.
	 *
	 * @param string $tag  The img tag.
	 * @param string $name Attribute name.
	 * @return string|false
	 */
	public static function get_attr( $tag, $name ) {
		if ( preg_match( '/' . preg_quote( $name, '/' ) . '\s*=\s*("|\')(.*?)\1/i', $tag, $m ) ) {
			return $m[2];
		}
		return false;
	}

	/**
	 * Get scannable posts in batches.
	 *
	 * Covers published posts, pages, all public custom post types, and the
	 * block-theme (FSE) template/post-type families where images hide.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit (-1 for all).
	 * @return int[]
	 */
	public static function get_posts( $offset = 0, $limit = 20 ) {
		$types = array( 'post', 'page' );

		// Public custom post types (e.g. WooCommerce product, portfolios).
		$cpts = get_post_types(
			array(
				'public'   => true,
				'_builtin' => false,
			),
			'names'
		);
		if ( $cpts ) {
			$types = array_merge( $types, array_values( $cpts ) );
		}

		// Full-site-editing template families (stored as CPTs).
		$fse = array( 'wp_template', 'wp_template_part', 'wp_navigation' );
		foreach ( $fse as $t ) {
			if ( post_type_exists( $t ) ) {
				$types[] = $t;
			}
		}

		$types = array_unique( $types );
		$place = implode( ',', array_fill( 0, count( $types ), '%s' ) );

		global $wpdb;
		$limit_sql = ( -1 === $limit ) ? '' : $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ( {$place} )
				AND post_status = 'publish'
				ORDER BY ID ASC{$limit_sql}",
				$types
			)
		);
		return array_map( 'intval', $ids );
	}

	/* ----------------------------------------------------------------------
	 * AJAX: global (wp_options: widgets, customizer, nav menus)
	 * -------------------------------------------------------------------- */

	public static function ajax_global_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_scope( 'global' ) ) );
	}

	public static function ajax_global_batch() {
		self::run_scope( 'global' );
	}

	/**
	 * Scan + fix <img> tags inside wp_options (widgets, customizer theme mods,
	 * nav menu item markup). These are not post content, so they're handled by
	 * direct option updates rather than post saves.
	 *
	 * @param string $scope Scope (only 'global' is handled here).
	 * @return int
	 */
	public static function fix_global( $scope, $fixer, $names = null ) {
		if ( 'global' !== $scope ) {
			return 0;
		}
		$fixed = 0;
		$option_names = ( is_array( $names ) && $names ) ? $names : self::global_option_names();

		foreach ( $option_names as $name ) {
			$raw  = get_option( $name );
			if ( null === $raw ) {
				continue;
			}
			// Serialized widget/theme-mod arrays.
			if ( is_string( $raw ) && is_serialized( $raw ) ) {
				$value = maybe_unserialize( $raw );
			} else {
				$value = $raw;
			}
			$updated = self::walk_meta_value( $value, 0, $fixer, $sub_fixed );
			$fixed  += $sub_fixed;
			if ( $updated !== $value ) {
				update_option( $name, $updated );
			}
		}
		return $fixed;
	}

	/**
	 * Option names that may contain image HTML / URLs.
	 *
	 * @return string[]
	 */
	public static function global_option_names() {
		global $wpdb;
		$names = array();

		// Widget options (widget_*).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$widget_opts = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE 'widget_%'"
		);
		if ( $widget_opts ) {
			$names = array_merge( $names, $widget_opts );
		}

		// Theme mods for the active + all themes (customizer images).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$theme_mods = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE 'theme_mods_%'"
		);
		if ( $theme_mods ) {
			$names = array_merge( $names, $theme_mods );
		}

		// Nav menu item meta is post meta (handled by meta scope); menu
		// locations/HTML in options are rare, skip.
		return array_unique( $names );
	}

	/**
	 * Whether any global option still contains an img missing alt.
	 *
	 * @return bool
	 */
	public static function global_has_missing_alt() {
		$fixer = Alt_Text_Fixer::init();
		foreach ( self::global_option_names() as $name ) {
			$raw = get_option( $name );
			if ( null === $raw ) {
				continue;
			}
			$value = is_string( $raw ) && is_serialized( $raw ) ? maybe_unserialize( $raw ) : $raw;
			if ( self::meta_value_has_missing_alt( $value, 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Count global options that still need work (each option = 1).
	 *
	 * @return int
	 */
	public static function count_global_options() {
		$count = 0;
		$fixer = Alt_Text_Fixer::init();
		foreach ( self::global_option_names() as $name ) {
			$raw = get_option( $name );
			if ( null === $raw ) {
				continue;
			}
			$value = is_string( $raw ) && is_serialized( $raw ) ? maybe_unserialize( $raw ) : $raw;
			if ( self::meta_value_has_missing_alt( $value, 0 ) ) {
				$count ++;
			}
		}
		return $count;
	}
}

ATF_Content_Fixer::init();

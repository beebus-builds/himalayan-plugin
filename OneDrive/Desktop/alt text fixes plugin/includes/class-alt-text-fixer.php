<?php
/**
 * Core plugin class: auto-fills alt text on upload and provides the shared
 * alt-text generation logic used by every other component.
 */
class Alt_Text_Fixer {

	/**
	 * Single instance.
	 *
	 * @var Alt_Text_Fixer|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return Alt_Text_Fixer
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Wires up the hooks.
	 */
	private function __construct() {
		add_action( 'add_attachment', array( $this, 'auto_set_alt_on_upload' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Activation: store default options.
	 */
	public static function activate() {
		add_option(
			'atf_settings',
			array(
				'auto_on_upload' => 'yes',
				'source'         => 'title', // title | filename
				'append_site'    => 'no',
				'auto_schedule'  => 'no',
			)
		);
		if ( class_exists( 'ATF_History' ) ) {
			ATF_History::ensure_table();
		}
	}

	/**
	 * Deactivation.
	 */
	public static function deactivate() {}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'alt-text-fixer' ) ) {
			return;
		}
		wp_enqueue_style(
			'atf-admin',
			ATF_URL . 'assets/admin.css',
			array(),
			ATF_VERSION
		);
	}

	/**
	 * When an attachment is uploaded, automatically set alt text if it is empty.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function auto_set_alt_on_upload( $attachment_id ) {
		$settings = get_option( 'atf_settings', array() );
		if ( empty( $settings['auto_on_upload'] ) || 'yes' !== $settings['auto_on_upload'] ) {
			return;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( false === strpos( $mime, 'image' ) ) {
			return;
		}

		$existing = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $existing ) ) {
			return;
		}

		$this->set_alt_text( $attachment_id );
	}

	/**
	 * Generate and store alt text for a single attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool True on success, false otherwise.
	 */
	public function set_alt_text( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$alt = $this->generate_alt_text( $attachment_id );
		if ( empty( $alt ) ) {
			return false;
		}
		$before = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( (string) $before === (string) $alt ) {
			return false;
		}
		$updated = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		if ( $updated && class_exists( 'ATF_History' ) ) {
			$post = get_post( $attachment_id );
			ATF_History::log(
				'library',
				$attachment_id,
				(string) $before,
				(string) $alt,
				array(
					'mime'       => $post ? $post->post_mime_type : '',
					'file'       => basename( (string) get_attached_file( $attachment_id ) ),
					'title'      => $post ? $post->post_title : '',
					'source'     => 'auto',
				)
			);
			// Per-attachment stack for fast "Revert" lookup in Media list.
			$stack = get_post_meta( $attachment_id, '_atf_alt_history', true );
			if ( ! is_array( $stack ) ) {
				$stack = array();
			}
			array_unshift(
				$stack,
				array(
					'time'   => time(),
					'before' => (string) $before,
					'after'  => (string) $alt,
				)
			);
			update_post_meta( $attachment_id, '_atf_alt_history', array_slice( $stack, 0, 20 ) );
		}
		return (bool) $updated;
	}

	/**
	 * Manually set alt text from the Review UI (gives user manual power).
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           New alt (empty string clears it).
	 * @return bool
	 */
	public function set_alt_text_manual( $attachment_id, $alt ) {
		$attachment_id = (int) $attachment_id;
		$alt = sanitize_text_field( (string) $alt );
		// Allow clearing: manual empty string is valid (unlike auto).
		$before = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( (string) $before === (string) $alt ) {
			return false;
		}
		if ( '' === $alt ) {
			delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
			$updated = true;
		} else {
			$updated = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
		if ( $updated && class_exists( 'ATF_History' ) ) {
			$post = get_post( $attachment_id );
			ATF_History::log(
				'library',
				$attachment_id,
				(string) $before,
				(string) $alt,
				array(
					'mime'   => $post ? $post->post_mime_type : '',
					'file'   => basename( (string) get_attached_file( $attachment_id ) ),
					'title'  => $post ? $post->post_title : '',
					'source' => 'manual',
				)
			);
		}
		return (bool) $updated;
	}

	/**
	 * Revert attachment alt to a previous value.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function revert_alt_text( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		$stack = get_post_meta( $attachment_id, '_atf_alt_history', true );
		if ( empty( $stack ) || ! is_array( $stack ) ) {
			return false;
		}
		$last = array_shift( $stack );
		$before = isset( $last['before'] ) ? (string) $last['before'] : '';
		if ( '' === $before ) {
			delete_post_meta( $attachment_id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $before );
		}
		update_post_meta( $attachment_id, '_atf_alt_history', array_values( $stack ) );
		return true;
	}

	/**
	 * Build the alt text string for an attachment based on settings.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function generate_alt_text( $attachment_id ) {
		$settings = get_option( 'atf_settings', array() );

		// 1. Try visual captioning first via filter. Returning a non-empty string makes AI the primary source.
		$alt = apply_filters( 'atf_generate_alt', '', $attachment_id, '' );

		// 2. Metadata fallback is disabled by default – we want visual captioning only.
		//    If you need a fallback, re-enable it below.
		if ( empty( $alt ) ) {
			// No metadata fallback. Keep alt empty so images remain flagged as missing
			// until a visual caption is provided.
			// Uncomment the block below to re-enable title/filename fallback.
			/*
			$source = isset( $settings['source'] ) ? $settings['source'] : 'title';
			$name   = '';
			if ( 'filename' === $source ) {
				$file = get_attached_file( $attachment_id );
				$base = basename( $file );
				$name = preg_replace( '/\.[^.]+$/', '', $base );
			} else {
				$post = get_post( $attachment_id );
				$name = $post ? $post->post_title : '';
			}
			$alt = $this->humanize( $name );
			if ( '' === $alt || $this->is_generic( $alt ) ) {
				$ctx = $this->context_label( $attachment_id );
				if ( $ctx ) { $alt = $ctx; }
			}
			$alt = apply_filters( 'atf_generate_alt', $alt, $attachment_id, $name );
			*/
		}

		if ( ! empty( $settings['append_site'] ) && 'yes' === $settings['append_site'] ) {
			$site = get_bloginfo( 'name' );
			if ( $site ) {
				$alt .= ' - ' . $this->humanize( $site );
			}
		}

		return $alt;
	}

	/**
	 * Whether an alt string is too generic to be useful (e.g. just "image",
	 * "img", "photo", "picture", or a single character).
	 *
	 * @param string $alt Humanized alt candidate.
	 * @return bool
	 */
	public function is_generic( $alt ) {
		$alt  = trim( (string) $alt );
		$generic = array( 'image', 'img', 'photo', 'picture', 'pic', 'graphic', 'figure', 'untitled' );
		if ( '' === $alt || mb_strlen( $alt ) <= 1 ) {
			return true;
		}
		return in_array( mb_strtolower( $alt ), $generic, true );
	}

	/**
	 * Derive a label from the post context of an attachment:
	 *  - the attachment's parent post title, else
	 *  - the title of a post that embeds this image in its content.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function context_label( $attachment_id ) {
		// 1) Parent post (uploaded to / attached to a post).
		$parent = wp_get_post_parent_id( $attachment_id );
		if ( $parent ) {
			$p = get_post( $parent );
			if ( $p && '' !== $p->post_title ) {
				return $this->humanize( $p->post_title );
			}
		}

		// 2) A post that uses this image in its content.
		$url = wp_get_attachment_url( $attachment_id );
		if ( $url ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_status = 'publish'
					AND post_type NOT IN ( 'attachment', 'revision', 'nav_menu_item' )
					AND post_content LIKE %s
					ORDER BY ID ASC LIMIT 1",
					'%' . $wpdb->esc_like( $url ) . '%'
				)
			);
			if ( $post_id ) {
				$p = get_post( $post_id );
				if ( $p && '' !== $p->post_title ) {
					return $this->humanize( $p->post_title );
				}
			}
		}
		return '';
	}

	/**
	 * Turn a slug/file name into a readable phrase.
	 *
	 * @param string $raw Raw string.
	 * @return string
	 */
	public function humanize( $raw ) {
		$raw  = preg_replace( '/[-_]+/', ' ', $raw );
		$raw  = preg_replace( '/\s+/', ' ', $raw );
		$raw  = trim( $raw );
		$raw  = preg_replace_callback( '/\b\w/u', function ( $m ) { return mb_strtoupper( $m[0] ); }, $raw );
		return $raw;
	}

	/**
	 * Whether an attachment already has alt text.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public static function has_alt( $attachment_id ) {
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return ! empty( $alt );
	}
}

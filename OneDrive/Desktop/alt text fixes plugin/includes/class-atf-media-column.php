<?php
/**
 * Adds a "Alt Text" column to the media library list view, flags images
 * missing alt text, and provides a per-row "Fix alt" action.
 */
class ATF_Media_Column {

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_filter( 'manage_media_columns', array( __CLASS__, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( __CLASS__, 'row_action' ), 10, 3 );
		add_action( 'admin_post_atf_revert_one', array( __CLASS__, 'handle_revert_one' ) );
		add_action( 'admin_post_atf_fix_one', array( __CLASS__, 'handle_fix_one' ) );
	}

	/**
	 * Register the custom column.
	 *
	 * @param array $columns Media columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['atf_alt'] = esc_html__( 'Alt Text', 'alt-text-fixer' );
		return $columns;
	}

	/**
	 * Render the column content.
	 *
	 * Shows current alt + TYPE (mime) + manual revert link when history exists.
	 *
	 * @param string $column_name Column key.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_column( $column_name, $attachment_id ) {
		if ( 'atf_alt' !== $column_name ) {
			return;
		}
		$post = get_post( $attachment_id );
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			echo '<span class="atf-ok">';
			echo esc_html( wp_trim_words( $alt, 12, '…' ) );
			echo '</span>';
		} else {
			echo '<span class="atf-missing">';
			echo esc_html__( 'Missing', 'alt-text-fixer' );
			echo '</span>';
		}
		// TYPE check: mime + file type for manual review.
		if ( $post ) {
			echo '<br><code style="font-size:11px">' . esc_html( $post->post_mime_type ) . '</code>';
		}
		$stack = get_post_meta( $attachment_id, '_atf_alt_history', true );
		if ( ! empty( $stack ) && is_array( $stack ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'atf_revert_one',
						'attachment_id' => $attachment_id,
					),
					admin_url( 'admin-post.php' )
				),
				'atf_revert_one'
			);
			echo '<br><a href="' . esc_url( $url ) . '">'
				. esc_html__( 'Revert', 'alt-text-fixer' )
				. '</a> <span class="description">(' . (int) count( $stack ) . ')</span>';
		}
	}

	/**
	 * Add a "Fix alt" link to the row actions for images missing alt text.
	 *
	 * @param array    $actions Row actions.
	 * @param WP_Post  $post Attachment post object.
	 * @param bool     $detached Whether detached.
	 * @return array
	 */
	public static function row_action( $actions, $post, $detached ) {
		if ( 'attachment' !== $post->post_type || false === strpos( $post->post_mime_type, 'image' ) ) {
			return $actions;
		}
		if ( ! Alt_Text_Fixer::has_alt( $post->ID ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'atf_fix_one',
						'attachment_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'atf_fix_one'
			);
			$actions['atf_fix'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Fix alt', 'alt-text-fixer' )
			);
			return $actions;
		}
		// Has alt + history → offer Revert for manual power.
		$stack = get_post_meta( $post->ID, '_atf_alt_history', true );
		if ( ! empty( $stack ) && is_array( $stack ) ) {
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'        => 'atf_revert_one',
						'attachment_id' => $post->ID,
					),
					admin_url( 'admin-post.php' )
				),
				'atf_revert_one'
			);
			$actions['atf_revert'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'Revert alt', 'alt-text-fixer' )
			);
		}
		return $actions;
	}

	public static function handle_fix_one() {
		check_admin_referer( 'atf_fix_one' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		$id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		if ( $id > 0 ) {
			Alt_Text_Fixer::init()->set_alt_text( $id );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}

	public static function handle_revert_one() {
		check_admin_referer( 'atf_revert_one' );
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		$id = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		if ( $id > 0 ) {
			Alt_Text_Fixer::init()->revert_alt_text( $id );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'upload.php' ) );
		exit;
	}
}

ATF_Media_Column::init();

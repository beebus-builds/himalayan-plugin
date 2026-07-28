<?php
/**
 * Bulk fixer: handles the admin-post action for a single attachment, plus an
 * AJAX batch processor that fixes images in small chunks so it never times out
 * even with an unlimited number of images.
 */
class ATF_Bulk_Fixer {

	const BATCH_SIZE = 50;

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'admin_post_atf_fix_one', array( __CLASS__, 'fix_one' ) );
		add_action( 'wp_ajax_atf_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_atf_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'atf_fix_attachment', array( __CLASS__, 'do_fix_attachment' ) );
	}

	/**
	 * Worker: Fix a single attachment via Action Scheduler.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function do_fix_attachment( $attachment_id ) {
		$fixer = Alt_Text_Fixer::init();
		if ( ! Alt_Text_Fixer::has_alt( $attachment_id ) ) {
			$fixer->set_alt_text( $attachment_id );
		}
	}

	/**
	 * Fix a single attachment from the media list row action.
	 */
	public static function fix_one() {
		if ( ! current_user_can( 'upload_files' ) || ! check_admin_referer( 'atf_fix_one' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}

		$id  = isset( $_GET['attachment_id'] ) ? (int) $_GET['attachment_id'] : 0;
		$ret = isset( $_GET['_wp_http_referer'] ) ? esc_url_raw( wp_unslash( $_GET['_wp_http_referer'] ) ) : admin_url( 'upload.php' );

		$fixed = 0;
		if ( $id && ! Alt_Text_Fixer::has_alt( $id ) ) {
			$fixer = Alt_Text_Fixer::init();
			if ( $fixer->set_alt_text( $id ) ) {
				$fixed = 1;
			}
		}

		wp_safe_redirect( add_query_arg( 'atf_fixed', $fixed, $ret ) );
		exit;
	}

	/**
	 * AJAX handler: return the number of images still missing alt text.
	 */
	public static function ajax_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_missing_alt() ) );
	}

	/**
	 * AJAX handler: process one batch of images missing alt text.
	 *
	 * Request params:
	 *  - offset : int, where to start in the queue.
	 *  - limit  : int, optional batch size override.
	 */
	public static function ajax_batch() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}

		try {
			$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
			$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : self::BATCH_SIZE;
			if ( $limit < 1 || $limit > 500 ) {
				$limit = self::BATCH_SIZE;
			}

			$ids = self::get_missing_alt_attachments( $offset, $limit );
			$queued = 0;
			$excluded = ATF_Content_Fixer::get_excluded_ids();

			foreach ( $ids as $id ) {
				if ( in_array( $id, $excluded, true ) ) {
					continue;
				}
				// Schedule the async action.
				if ( class_exists( 'ActionScheduler' ) && function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( 'atf_fix_attachment', array( $id ) );
				} else {
					self::do_fix_attachment( $id );
				}
				$queued++;
			}

			$remaining = self::count_missing_alt();
			$processed = $offset + count( $ids );

			wp_send_json_success(
				array(
					'queued'    => $queued,
					'processed' => $processed,
					'remaining' => $remaining,
					'finished'  => $remaining <= 0,
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}

	/**
	 * Count image attachments that have no alt text.
	 *
	 * @return int
	 */
	public static function count_missing_alt() {
		$excluded = ATF_Content_Fixer::get_excluded_ids();
		$excluded_sql = '';
		if ( ! empty( $excluded ) ) {
			$excluded_sql = " AND p.ID NOT IN (" . implode( ',', array_map( 'intval', $excluded ) ) . ")";
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type LIKE %s
				AND m.meta_value IS NULL{$excluded_sql}",
				'%image%'
			)
		);
	}

	/**
	 * Query a slice of image attachments that have no alt text.
	 *
	 * @param int $offset Start offset.
	 * @param int $limit  Number to return.
	 * @return int[]
	 */
	public static function get_missing_alt_attachments( $offset = 0, $limit = 50 ) {
		$offset = max( 0, (int) $offset );
		$limit  = max( 1, (int) $limit );
		$excluded = ATF_Content_Fixer::get_excluded_ids();
		$excluded_sql = '';
		if ( ! empty( $excluded ) ) {
			$excluded_sql = " AND p.ID NOT IN (" . implode( ',', array_map( 'intval', $excluded ) ) . ")";
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type LIKE %s
				AND m.meta_value IS NULL{$excluded_sql}
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				'%image%',
				$limit,
				$offset
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Show a success notice after a single fix action.
	 */
	public static function admin_notices() {
		if ( ! isset( $_GET['atf_fixed'] ) ) {
			return;
		}
		$count = (int) $_GET['atf_fixed'];
		$msg   = 1 === $count
			? esc_html__( 'Alt text added to 1 image.', 'alt-text-fixer' )
			: sprintf( esc_html__( 'Alt text added to %d images.', 'alt-text-fixer' ), $count );
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
	}
}

ATF_Bulk_Fixer::init();

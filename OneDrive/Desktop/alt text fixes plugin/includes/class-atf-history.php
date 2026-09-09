<?php
/**
 * Manual review + revert log for alt-text fixes.
 *
 * Stores one entry per change so users can check TYPE (library/content/meta/
 * css/global + mime type) and revert alt text and related HTML/option changes.
 */
class ATF_History {

	const OPTION = 'atf_change_log';
	const MAX    = 500;

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'atf_history';
	}

	public static function ensure_table() {
		global $wpdb;
		$table = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			time BIGINT(20) UNSIGNED NOT NULL,
			kind VARCHAR(32) NOT NULL,
			object_id VARCHAR(191) NOT NULL,
			meta_key VARCHAR(191) DEFAULT NULL,
			before LONGTEXT NOT NULL,
			after LONGTEXT NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(32) DEFAULT NULL,
			extra LONGTEXT DEFAULT NULL,
			reverted TINYINT(1) NOT NULL DEFAULT 0,
			reverted_time BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY kind_time (kind, time),
			KEY object_id (object_id)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		self::migrate_from_option();
	}

	public static function migrate_from_option() {
		global $wpdb;
		$table = self::table();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( ! $exists ) {
			return;
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count > 0 ) {
			return;
		}
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) || empty( $logs ) ) {
			return;
		}
		foreach ( $logs as $e ) {
			if ( empty( $e['id'] ) ) {
				continue;
			}
			$extra = isset( $e['extra'] ) && is_array( $e['extra'] ) ? $e['extra'] : array();
			$meta_key = '';
			if ( isset( $extra['meta_key'] ) ) {
				$meta_key = sanitize_text_field( (string) $extra['meta_key'] );
			}
			$source = isset( $extra['source'] ) ? sanitize_text_field( (string) $extra['source'] ) : null;
			$wpdb->insert(
				$table,
				array(
					'time'      => isset( $e['time'] ) ? (int) $e['time'] : time(),
					'kind'      => isset( $e['kind'] ) ? sanitize_key( $e['kind'] ) : '',
					'object_id' => isset( $e['object_id'] ) ? (string) $e['object_id'] : '',
					'meta_key'  => $meta_key ?: null,
					'before'    => isset( $e['before'] ) ? (string) $e['before'] : '',
					'after'     => isset( $e['after'] )  ? (string) $e['after']  : '',
					'user_id'   => isset( $e['user_id'] ) ? (int) $e['user_id'] : 0,
					'source'    => $source,
					'extra'     => wp_json_encode( $extra ),
					'reverted'  => ! empty( $e['reverted'] ) ? 1 : 0,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
			);
		}
		// Optionally clear option after migration
		// delete_option( self::OPTION );
	}

	/**
	 * Log a change.
	 *
	 * @param string $kind      library|content|meta|css|global
	 * @param mixed  $object_id Attachment/Post ID or option name.
	 * @param string $before    Old value (alt text, post_content, meta value, option value).
	 * @param string $after     New value.
	 * @param array  $extra     Extra context: mime, file, meta_key, post_title, etc.
	 * @return string Log entry ID.
	 */
	public static function log( $kind, $object_id, $before, $after, $extra = array() ) {
		$before = (string) $before;
		$after  = (string) $after;
		if ( $before === $after ) {
			return '';
		}
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$extra = is_array( $extra ) ? $extra : array();
		$meta_key = '';
		if ( isset( $extra['meta_key'] ) ) {
			$meta_key = sanitize_text_field( (string) $extra['meta_key'] );
		}
		$source = isset( $extra['source'] ) ? sanitize_text_field( (string) $extra['source'] ) : null;
		$wpdb->insert(
			$table,
			array(
				'time'      => time(),
				'kind'      => sanitize_key( $kind ),
				'object_id' => is_numeric( $object_id ) ? (string) (int) $object_id : sanitize_text_field( (string) $object_id ),
				'meta_key'  => $meta_key ?: null,
				'before'    => $before,
				'after'     => $after,
				'user_id'   => get_current_user_id(),
				'source'    => $source,
				'extra'     => wp_json_encode( $extra ),
				'reverted'  => 0,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);
		return $wpdb->insert_id ? (string) $wpdb->insert_id : '';
	}

	/**
	 * Get logs, optionally filtered by kind.
	 *
	 * @param string $kind Filter or 'all'.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function get_all( $kind = 'all', $limit = 200 ) {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$limit = max( 1, intval( $limit ) );
		$where = '';
		$params = array();
		if ( 'all' !== $kind ) {
			$where = 'WHERE kind = %s';
			$params[] = sanitize_key( $kind );
		}
		$sql = "SELECT * FROM $table $where ORDER BY time DESC LIMIT %d";
		$params[] = $limit;
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! $rows ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $r ) {
			$extra = array();
			if ( ! empty( $r['extra'] ) ) {
				$extra = json_decode( $r['extra'], true );
				if ( ! is_array( $extra ) ) {
					$extra = array();
				}
			}
			$out[] = array(
				'id'        => (string) $r['id'],
				'time'      => (int) $r['time'],
				'user_id'   => (int) $r['user_id'],
				'kind'      => $r['kind'],
				'object_id' => $r['object_id'],
				'before'    => $r['before'],
				'after'     => $r['after'],
				'extra'     => $extra,
				'reverted'  => (bool) $r['reverted'],
				'meta_key'  => $r['meta_key'],
				'source'    => $r['source'],
			);
		}
		return $out;
	}

	public static function get_one( $id ) {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", intval( $id ) ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$extra = array();
		if ( ! empty( $row['extra'] ) ) {
			$extra = json_decode( $row['extra'], true );
			if ( ! is_array( $extra ) ) {
				$extra = array();
			}
		}
		return array(
			'id'        => (string) $row['id'],
			'time'      => (int) $row['time'],
			'user_id'   => (int) $row['user_id'],
			'kind'      => $row['kind'],
			'object_id' => $row['object_id'],
			'before'    => $row['before'],
			'after'     => $row['after'],
			'extra'     => $extra,
			'reverted'  => (bool) $row['reverted'],
			'meta_key'  => $row['meta_key'],
			'source'    => $row['source'],
		);
	}

	/**
	 * Revert a single log entry. Returns true on success.
	 *
	 * @param string $id Log ID.
	 * @return bool|WP_Error
	 */
	public static function revert( $id ) {
		$entry = self::get_one( $id );
		if ( ! $entry ) {
			return new WP_Error( 'atf_not_found', __( 'Change not found.', 'alt-text-fixer' ) );
		}
		$kind   = $entry['kind'];
		$oid    = $entry['object_id'];
		$before = $entry['before'];
		$extra  = isset( $entry['extra'] ) && is_array( $entry['extra'] ) ? $entry['extra'] : array();
		$ok     = false;

		if ( 'library' === $kind ) {
			$att_id = (int) $oid;
			if ( $att_id > 0 && get_post( $att_id ) ) {
				if ( '' === $before ) {
					delete_post_meta( $att_id, '_wp_attachment_image_alt' );
				} else {
					update_post_meta( $att_id, '_wp_attachment_image_alt', $before );
				}
				$ok = true;
			}
		} elseif ( 'content' === $kind || 'css' === $kind ) {
			$pid = (int) $oid;
			$post = get_post( $pid );
			if ( $post ) {
				// Prevent infinite loop from save hooks.
				remove_action( 'save_post', array( 'ATF_Content_Fixer', 'maybe_fix_on_save' ), 10 );
				wp_update_post(
					array(
						'ID'           => $pid,
						'post_content' => $before,
					)
				);
				$ok = true;
			}
		} elseif ( 'meta' === $kind ) {
			$pid = (int) $oid;
			$key = isset( $extra['meta_key'] ) ? $extra['meta_key'] : '';
			if ( $pid > 0 && '' !== $key ) {
				$decoded = json_decode( $before, true );
				// Before was stored as JSON when complex, else raw string.
				if ( null !== $decoded && isset( $extra['is_json'] ) && $extra['is_json'] ) {
					update_post_meta( $pid, $key, $decoded );
				} else {
					update_post_meta( $pid, $key, $before );
				}
				$ok = true;
			}
		} elseif ( 'global' === $kind ) {
			$opt = (string) $oid;
			if ( '' !== $opt ) {
				$decoded = json_decode( $before, true );
				if ( null !== $decoded && isset( $extra['is_json'] ) && $extra['is_json'] ) {
					update_option( $opt, $decoded );
				} else {
					update_option( $opt, $before );
				}
				$ok = true;
			}
		}

		if ( $ok ) {
			self::mark_reverted( $id );
		}
		return $ok ? true : new WP_Error( 'atf_revert_failed', __( 'Revert failed.', 'alt-text-fixer' ) );
	}

	/**
	 * Mark entry as reverted.
	 *
	 * @param string $id Log ID.
	 */
	public static function mark_reverted( $id ) {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$wpdb->update(
			$table,
			array( 'reverted' => 1, 'reverted_time' => time() ),
			array( 'id' => intval( $id ) ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Delete one entry.
	 *
	 * @param string $id Log ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$wpdb->delete( $table, array( 'id' => intval( $id ) ), array( '%d' ) );
	}

	/**
	 * Clear all logs.
	 */
	public static function clear_all() {
		global $wpdb;
		self::ensure_table();
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE $table" );
	}
}

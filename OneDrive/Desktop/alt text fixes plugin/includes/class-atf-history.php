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
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}
		$id = md5( $kind . '|' . (string) $object_id . '|' . microtime( true ) . '|' . wp_rand() );
		array_unshift(
			$logs,
			array(
				'id'        => $id,
				'time'      => time(),
				'user_id'   => get_current_user_id(),
				'kind'      => sanitize_key( $kind ),
				'object_id' => is_numeric( $object_id ) ? (int) $object_id : sanitize_text_field( (string) $object_id ),
				'before'    => $before,
				'after'     => $after,
				'extra'     => is_array( $extra ) ? $extra : array(),
				'reverted'  => false,
			)
		);
		$logs = array_slice( $logs, 0, self::MAX );
		update_option( self::OPTION, $logs, false );
		return $id;
	}

	/**
	 * Get logs, optionally filtered by kind.
	 *
	 * @param string $kind Filter or 'all'.
	 * @param int    $limit Max rows.
	 * @return array
	 */
	public static function get_all( $kind = 'all', $limit = 200 ) {
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return array();
		}
		if ( 'all' !== $kind ) {
			$logs = array_filter(
				$logs,
				function ( $e ) use ( $kind ) {
					return isset( $e['kind'] ) && $e['kind'] === $kind;
				}
			);
		}
		return array_slice( array_values( $logs ), 0, (int) $limit );
	}

	public static function get_one( $id ) {
		foreach ( self::get_all( 'all', self::MAX ) as $e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				return $e;
			}
		}
		return null;
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
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return;
		}
		foreach ( $logs as &$e ) {
			if ( isset( $e['id'] ) && $e['id'] === $id ) {
				$e['reverted'] = true;
				break;
			}
		}
		unset( $e );
		update_option( self::OPTION, $logs, false );
	}

	/**
	 * Delete one entry.
	 *
	 * @param string $id Log ID.
	 */
	public static function delete( $id ) {
		$logs = get_option( self::OPTION, array() );
		if ( ! is_array( $logs ) ) {
			return;
		}
		$logs = array_values(
			array_filter(
				$logs,
				function ( $e ) use ( $id ) {
					return ! isset( $e['id'] ) || $e['id'] !== $id;
				}
			)
		);
		update_option( self::OPTION, $logs, false );
	}

	/**
	 * Clear all logs.
	 */
	public static function clear_all() {
		delete_option( self::OPTION );
	}
}

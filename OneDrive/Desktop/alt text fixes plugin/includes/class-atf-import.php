<?php
/**
 * Spreadsheet / CSV import.
 *
 * Applies client-provided overrides in bulk:
 *  - "alt" sheet : image URL -> alt text. Matches the media-library attachment
 *    by URL (or file basename) and sets _wp_attachment_image_alt.
 *  - "seo" sheet : post URL / ID / slug -> title, description, social image.
 *    Sets _atf_seo_title / _atf_seo_desc / _atf_seo_image post meta.
 *
 * Upload is handled via a standard admin-post request (file upload cannot go
 * through the JSON AJAX batch runner). Results are shown as an admin notice.
 */
class ATF_Import {

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'admin_post_atf_import', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_atf_template', array( __CLASS__, 'handle_template' ) );
	}

	/**
	 * Template definitions (header + example row) for each mode.
	 *
	 * @return array
	 */
	public static function templates() {
		return array(
			'alt' => array(
				'header' => array( 'image', 'alt' ),
				'example' => array( 'https://example.com/wp-content/uploads/foo.jpg', 'Red sports car on a track' ),
			),
			'seo' => array(
				'header' => array( 'post', 'title', 'description', 'image' ),
				'example' => array(
					'https://example.com/my-post/',
					'My Post Title - Example Site',
					'A short description of the post for search results.',
					'https://example.com/wp-content/uploads/og.jpg',
				),
			),
		);
	}

	/**
	 * Serve a CSV template for download.
	 */
	public static function handle_template() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'atf_template_nonce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		$mode = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'alt';
		$templates = self::templates();
		if ( ! isset( $templates[ $mode ] ) ) {
			$mode = 'alt';
		}
		$tpl = $templates[ $mode ];

		$lines = array(
			self::csv_line( $tpl['header'] ),
			self::csv_line( $tpl['example'] ),
		);
		$csv = implode( "\n", $lines ) . "\n";

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="atf-' . $mode . '-template.csv"' );
		header( 'Cache-Control: no-store, no-cache' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $csv;
		exit;
	}

	/**
	 * Build one CSV line (RFC 4180-style quoting).
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	public static function csv_line( $cells ) {
		$out = array();
		foreach ( $cells as $c ) {
			$c = str_replace( '"', '""', $c );
			$out[] = '"' . $c . '"';
		}
		return implode( ',', $out );
	}

	/**
	 * Handle the uploaded CSV.
	 */
	public static function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'atf_import_nonce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}

		$mode = isset( $_POST['atf_mode'] ) ? sanitize_key( $_POST['atf_mode'] ) : 'alt';
		if ( ! in_array( $mode, array( 'alt', 'seo' ), true ) ) {
			$mode = 'alt';
		}

		// Site base URL used to resolve bare filenames / relative paths.
		$base = isset( $_POST['atf_base'] ) ? esc_url_raw( wp_unslash( $_POST['atf_base'] ) ) : '';
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		$base = trailingslashit( $base );

		if ( empty( $_FILES['atf_csv']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'atf_import', 'noupload', admin_url( 'options-general.php?page=alt-text-fixer' ) ) );
			exit;
		}

		if ( $_FILES['atf_csv']['size'] > 10 * 1024 * 1024 ) {
			wp_safe_redirect( add_query_arg( 'atf_import', 'toolarge', admin_url( 'options-general.php?page=alt-text-fixer' ) ) );
			exit;
		}

		if ( ! in_array( mime_content_type( $_FILES['atf_csv']['tmp_name'] ), array( 'text/csv', 'text/plain', 'application/csv', 'text/x-csv' ), true ) ) {
			wp_safe_redirect( add_query_arg( 'atf_import', 'invalidtype', admin_url( 'options-general.php?page=alt-text-fixer' ) ) );
			exit;
		}

		$rows = self::parse_csv( $_FILES['atf_csv']['tmp_name'] );
		if ( false === $rows ) {
			wp_safe_redirect( add_query_arg( 'atf_import', 'parse', admin_url( 'options-general.php?page=alt-text-fixer' ) ) );
			exit;
		}

		$applied = ( 'alt' === $mode ) ? self::apply_alt( $rows, $base ) : self::apply_seo( $rows, $base );

		wp_safe_redirect(
			add_query_arg(
				array(
					'atf_import' => 'done',
					'applied'    => $applied,
					'total'      => count( $rows ),
				),
				admin_url( 'options-general.php?page=alt-text-fixer' )
			)
		);
		exit;
	}

	/**
	 * Parse a CSV file into an array of associative rows (header-based).
	 *
	 * @param string $path File path.
	 * @return array|false
	 */
	public static function parse_csv( $path ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
		if ( false === $handle ) {
			return false;
		}
		$header = fgetcsv( $handle );
		if ( false === $header ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return false;
		}
		$header  = array_map( 'strtolower', array_map( 'trim', $header ) );
		$rows    = array();
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( count( $data ) < count( $header ) ) {
				continue;
			}
			$row = array();
			foreach ( $header as $i => $key ) {
				$row[ $key ] = isset( $data[ $i ] ) ? $data[ $i ] : '';
			}
			$rows[] = $row;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return $rows;
	}

	/**
	 * Apply alt-text overrides from rows.
	 *
	 * Expected columns: image (url) + alt.
	 *
	 * @param array $rows Parsed rows.
	 * @return int Number applied.
	 */
	public static function apply_alt( $rows, $base = '' ) {
		$applied = 0;
		foreach ( $rows as $row ) {
			$url = trim( $row['image'] ?? $row['image_url'] ?? $row['url'] ?? '' );
			$alt = trim( $row['alt'] ?? $row['alt_text'] ?? '' );
			if ( ! $url || '' === $alt ) {
				continue;
			}
			$url = self::normalize_url( $url, $base );
			$id  = self::resolve_attachment( $url );
			if ( ! $id ) {
				continue;
			}
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
			$applied ++;
		}
		return $applied;
	}

	/**
	 * Apply SEO overrides from rows.
	 *
	 * Expected columns: post (url/id/slug) + title + description + image.
	 *
	 * @param array $rows Parsed rows.
	 * @return int Number applied.
	 */
	public static function apply_seo( $rows, $base = '' ) {
		$applied = 0;
		foreach ( $rows as $row ) {
			$ref     = trim( $row['post'] ?? $row['post_url'] ?? $row['url'] ?? $row['id'] ?? '' );
			$title   = trim( $row['title'] ?? '' );
			$desc    = trim( $row['description'] ?? $row['desc'] ?? '' );
			$image   = trim( $row['image'] ?? $row['image_url'] ?? '' );
			if ( ! $ref ) {
				continue;
			}
			$ref   = self::normalize_url( $ref, $base );
			$image = $image ? self::normalize_url( $image, $base ) : '';
			$post_id = self::resolve_post( $ref );
			if ( ! $post_id ) {
				continue;
			}
			if ( '' !== $title ) {
				update_post_meta( $post_id, ATF_SEO::TITLE_META, sanitize_text_field( $title ) );
			}
			if ( '' !== $desc ) {
				update_post_meta( $post_id, ATF_SEO::DESC_META, sanitize_text_field( $desc ) );
			}
			if ( '' !== $image ) {
				update_post_meta( $post_id, ATF_SEO::IMAGE_META, esc_url_raw( $image ) );
			}
			if ( '' !== $title || '' !== $desc || '' !== $image ) {
				$applied ++;
			}
		}
		return $applied;
	}

	/**
	 * Normalize a user-supplied value into an absolute URL using the site base.
	 *
	 * Handles: full URLs (unchanged), leading-slash relative paths
	 * (/wp-content/...), bare filenames (foo.jpg), and query/anchor suffixes.
	 *
	 * @param string $value Raw value from the spreadsheet.
	 * @param string $base  Site base URL (trailing slash).
	 * @return string
	 */
	public static function normalize_url( $value, $base ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return $value;
		}
		// Already an absolute URL (http/https or protocol-relative).
		if ( preg_match( '#^(https?:)?//#i', $value ) ) {
			return preg_replace( '#^//#', 'https://', $value );
		}
		// Strip any leading scheme-less path artifacts.
		$value = ltrim( $value, '/' );
		return $base . $value;
	}

	/**
	 * Resolve an attachment ID from an image URL (exact or basename match).
	 *
	 * @param string $url Image URL.
	 * @return int|false
	 */
	public static function resolve_attachment( $url ) {
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			return $id;
		}
		$base = basename( parse_url( $url, PHP_URL_PATH ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$like = '%/' . $wpdb->esc_like( $base );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s LIMIT 1",
				$like
			)
		);
		return $id ? (int) $id : false;
	}

	/**
	 * Resolve a post ID from a URL, numeric ID, or slug.
	 *
	 * @param string $ref Reference.
	 * @return int|false
	 */
	public static function resolve_post( $ref ) {
		if ( is_numeric( $ref ) ) {
			$id = (int) $ref;
			return get_post( $id ) ? $id : false;
		}
		$ref = trim( $ref, '/' );
		if ( false !== strpos( $ref, '/' ) || false !== strpos( $ref, '.' ) ) {
			// Looks like a URL.
			$id = url_to_postid( $ref );
			if ( $id ) {
				return $id;
			}
			$path = parse_url( $ref, PHP_URL_PATH );
			$ref  = trim( $path, '/' );
		}
		$query = new WP_Query(
			array(
				'name'             => $ref,
				'post_type'        => get_post_types( array( 'public' => true ) ),
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'ignore_sticky_posts' => true,
			)
		);
		return $query->have_posts() ? (int) $query->posts[0] : false;
	}
}

ATF_Import::init();

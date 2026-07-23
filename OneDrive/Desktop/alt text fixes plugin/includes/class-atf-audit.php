<?php
/**
 * Scan-only audit report.
 *
 * Flags image issues that live OUTSIDE the database and therefore cannot be
 * auto-fixed by the plugin: hard-coded <img> tags and CSS background images
 * inside the active theme's PHP template files and external/static stylesheet
 * files. These require a manual theme edit (or a child-theme override), so the
 * audit reports the file + line so the developer knows exactly what to change.
 *
 * This module only READS; it never writes to theme files.
 */
class ATF_Audit {

	/**
	 * Locations flagged by the audit, grouped by file.
	 *
	 * @var array
	 */
	protected static $findings = array();

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_atf_audit_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_atf_audit_csv', array( __CLASS__, 'ajax_export_csv' ) );
	}

	/**
	 * AJAX: run the theme-file audit and return a summary + findings.
	 */
	public static function ajax_run() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$findings = self::scan_theme();
		wp_send_json_success(
			array(
				'total'    => count( $findings ),
				'findings' => array_slice( $findings, 0, 200 ),
			)
		);
	}

	/**
	 * AJAX: export findings as CSV.
	 */
	public static function ajax_export_csv() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$findings = self::scan_theme();

		$csv = "File,Line,Type,Detail\n";
		foreach ( $findings as $f ) {
			$csv .= sprintf( '"%s",%d,"%s","%s"' . "\n", esc_html( str_replace( '"', '""', $f['file'] ) ), $f['line'], esc_html( $f['type'] ), esc_html( str_replace( '"', '""', $f['detail'] ) ) );
		}

		wp_send_json_success(
			array(
				'csv'  => $csv,
				'rows' => count( $findings ),
			)
		);
	}

	/**
	 * Scan all active-theme template files (+ parent) for image issues.
	 *
	 * @return array List of findings: file, line, type, detail.
	 */
	public static function scan_theme() {
		$findings = array();
		$theme    = wp_get_theme();
		$paths    = array( get_stylesheet_directory() );
		if ( is_child_theme() ) {
			$paths[] = get_template_directory();
		}

		foreach ( $paths as $base ) {
			foreach ( self::php_files( $base ) as $file ) {
				self::scan_file( $file, $findings );
			}
		}
		return $findings;
	}

	/**
	 * Recursively list .php files under a directory.
	 *
	 * @param string $dir Directory.
	 * @return array
	 */
	public static function php_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( 'php' === $f->getExtension() ) {
				$files[] = $f->getPathname();
			}
		}
		return $files;
	}

	/**
	 * Scan a single file for <img> missing alt and CSS background images.
	 *
	 * @param string $file     File path.
	 * @param array  $findings Findings array (by reference).
	 */
	public static function scan_file( $file, &$findings ) {
		$lines = @file( $file );
		if ( false === $lines ) {
			return;
		}
		$short = self::rel_path( $file );
		foreach ( $lines as $i => $line ) {
			// <img ...> without a non-empty alt=.
			if ( false !== strpos( $line, '<img' )
				&& ! preg_match( '/\balt\s*=\s*("|\')\s*(?!\1)[^"\']*\1/', $line ) ) {
				$findings[] = array(
					'file'   => $short,
					'line'   => $i + 1,
					'type'   => 'img',
					'detail' => esc_html__( 'Hard-coded <img> without alt attribute.', 'alt-text-fixer' ),
				);
			}
			// CSS background-image (inline or url()).
			if ( preg_match( '/background(-image)?\s*:\s*[^;]*url\(/i', $line )
				|| ( false !== strpos( $line, 'background' ) && preg_match( '/url\(\s*[\'"]?https?:\/\//i', $line ) ) ) {
				$findings[] = array(
					'file'   => $short,
					'line'   => $i + 1,
					'type'   => 'css',
					'detail' => esc_html__( 'CSS background-image (cannot carry alt; mark decorative or convert).', 'alt-text-fixer' ),
				);
			}
		}
	}

	/**
	 * Make a path relative to the theme root for display.
	 *
	 * @param string $file File path.
	 * @return string
	 */
	public static function rel_path( $file ) {
		$base = get_stylesheet_directory();
		if ( 0 === strpos( $file, $base ) ) {
			return ltrim( substr( $file, strlen( $base ) ), '/' );
		}
		$parent = get_template_directory();
		if ( 0 === strpos( $file, $parent ) ) {
			return 'parent/' . ltrim( substr( $file, strlen( $parent ) ), '/' );
		}
		return $file;
	}
}

ATF_Audit::init();

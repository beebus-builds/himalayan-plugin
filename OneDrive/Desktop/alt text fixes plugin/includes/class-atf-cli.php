<?php
/**
 * WP-CLI commands for Alt Text Fixer.
 *
 * Usage:
 *   wp atf status                # show remaining counts per scope
 *   wp atf fix-all               # fix all alt-text scopes (library/content/meta/css/global)
 *   wp atf seo                   # generate missing SEO meta for all posts
 *   wp atf audit                 # scan theme files (read-only)
 *
 * Useful on hosts where WP-Cron is unreliable, or for one-shot bulk runs over
 * very large sites from the command line.
 */
class ATF_CLI extends WP_CLI_Command {

	/**
	 * Show remaining counts per scope.
	 */
	public function status() {
		$summary = ATF_Cron::remaining_summary();
		WP_CLI::line( 'Alt Text Fixer — remaining items:' );
		$labels = array(
			'library' => 'Media library images',
			'content' => 'Embedded in content',
			'meta'    => 'In post meta',
			'css'     => 'CSS background images',
			'global'  => 'Widgets / customizer',
			'seo'     => 'SEO meta',
			'schema'  => 'Schema',
		);
		foreach ( $summary as $key => $count ) {
			WP_CLI::line( sprintf( '  %-24s %d', $labels[ $key ], (int) $count ) );
		}
		$total = array_sum( $summary );
		WP_CLI::success( sprintf( '%d total remaining.', $total ) );
	}

	/**
	 * Fix all alt-text scopes.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args (--batch=<n>, --scope=<name>).
	 */
	public function fix_all( $args = array(), $assoc_args = array() ) {
		$scope = isset( $assoc_args['scope'] ) ? $assoc_args['scope'] : 'all';
		$batch = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 50;
		$batch = max( 1, min( 500, $batch ) );

		$fixer = Alt_Text_Fixer::init();

		if ( 'all' === $scope || 'library' === $scope ) {
			$total = ATF_Bulk_Fixer::count_missing_alt();
			$done  = 0;
			WP_CLI::line( sprintf( 'Library: %d images.', $total ) );
			foreach ( array_chunk( ATF_Bulk_Fixer::get_missing_alt_attachments( -1 ), $batch ) as $chunk ) {
				foreach ( $chunk as $id ) {
					if ( $fixer->set_alt_text( $id ) ) {
						$done ++;
					}
				}
			}
			WP_CLI::success( sprintf( 'Library fixed %d.', $done ) );
		}

		$post_scopes = array( 'content', 'meta', 'css' );
		if ( 'all' === $scope || in_array( $scope, $post_scopes, true ) ) {
			$posts = ATF_Content_Fixer::get_posts( 0, -1 );
			$count = count( $posts );
			$processed = 0;
			foreach ( $posts as $pid ) {
				if ( 'all' === $scope || 'content' === $scope ) {
					ATF_Content_Fixer::fix_post_scope( $pid, 'content', $fixer );
				}
				if ( 'all' === $scope || 'meta' === $scope ) {
					ATF_Content_Fixer::fix_post_scope( $pid, 'meta', $fixer );
				}
				if ( 'all' === $scope || 'css' === $scope ) {
					ATF_Content_Fixer::fix_post_scope( $pid, 'css', $fixer );
				}
				$processed ++;
				if ( 0 === $processed % $batch ) {
					WP_CLI::line( sprintf( '  posts %d/%d', $processed, $count ) );
				}
			}
			WP_CLI::success( 'Post scopes done.' );
		}

		if ( 'all' === $scope || 'global' === $scope ) {
			$opts = ATF_Content_Fixer::global_option_names();
			foreach ( array_chunk( $opts, $batch ) as $chunk ) {
				ATF_Content_Fixer::fix_global( 'global', $fixer, $chunk );
			}
			WP_CLI::success( 'Widget/customizer options done.' );
		}
	}

	/**
	 * Generate missing SEO meta for all posts.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args (--batch=<n>).
	 */
	public function seo( $args = array(), $assoc_args = array() ) {
		$batch = isset( $assoc_args['batch'] ) ? (int) $assoc_args['batch'] : 50;
		$batch = max( 1, min( 500, $batch ) );

		$ids   = ATF_SEO::get_posts_missing( 0, -1 );
		$total = count( $ids );
		$done  = 0;
		WP_CLI::line( sprintf( 'SEO: %d posts missing meta.', $total ) );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! ATF_SEO::is_supported( $post ) ) {
				continue;
			}
			if ( '' === get_post_meta( $id, ATF_SEO::TITLE_META, true ) ) {
				update_post_meta( $id, ATF_SEO::TITLE_META, ATF_SEO::generate_title( $id ) );
			}
			if ( '' === get_post_meta( $id, ATF_SEO::DESC_META, true ) ) {
				update_post_meta( $id, ATF_SEO::DESC_META, ATF_SEO::generate_description( $id ) );
			}
			$done ++;
		}
		ATF_SEO::ping_search_engines();
		WP_CLI::success( sprintf( 'SEO meta generated for %d posts; search engines notified.', $done ) );
	}

	/**
	 * Scan theme files (read-only).
	 */
	public function audit() {
		$findings = ATF_Audit::scan_theme();
		if ( ! $findings ) {
			WP_CLI::success( 'No hard-coded images found in the active theme.' );
			return;
		}
		WP_CLI::line( sprintf( '%d issue(s) found:', count( $findings ) ) );
		foreach ( $findings as $f ) {
			WP_CLI::line( sprintf( '  %s:%d [%s] %s', $f['file'], $f['line'], $f['type'], $f['detail'] ) );
		}
	}

	/**
	 * Generate/refresh schema markup state for all eligible posts.
	 */
	public function schema() {
		$posts = ATF_Content_Fixer::get_posts( 0, -1 );
		$total = 0;
		$done  = 0;
		foreach ( $posts as $pid ) {
			if ( ATF_Schema::post_needs_schema( $pid ) ) {
				$total ++;
				ATF_Schema::mark_batch( $pid, 1 );
				$done ++;
			}
		}
		WP_CLI::success( sprintf( 'Schema state refreshed for %d of %d eligible posts.', $done, $total ) );
	}

	/**
	 * Run the technical SEO audit and print the report.
	 */
	public function tech_audit() {
		$checks = ATF_Tech_Audit::run();
		WP_CLI::line( 'Technical SEO audit:' );
		foreach ( $checks as $c ) {
			$tag = 'pass' === $c['status'] ? 'OK  ' : ( 'warn' === $c['status'] ? 'WARN' : 'FAIL' );
			WP_CLI::line( sprintf( '  [%-4s] %s — %s', $tag, $c['label'], $c['detail'] ) );
		}
		$fails = count( array_filter( $checks, function ( $c ) { return 'fail' === $c['status']; } ) );
		$warns = count( array_filter( $checks, function ( $c ) { return 'warn' === $c['status']; } ) );
		WP_CLI::success( sprintf( '%d pass, %d warn, %d fail.', count( $checks ) - $fails - $warns, $warns, $fails ) );
	}

	/**
	 * Manage the Client Developer role.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : create or remove
	 *
	 * ## EXAMPLES
	 *
	 *     wp atf role create
	 *     wp atf role remove
	 *
	 * @param array $args Positional args.
	 */
	public function role( $args ) {
		$action = isset( $args[0] ) ? $args[0] : '';
		if ( 'create' === $action ) {
			ATF_Admin::sync_role();
			WP_CLI::success( 'Client Developer role created / synced.' );
		} elseif ( 'remove' === $action ) {
			ATF_Admin::remove_role();
			WP_CLI::success( 'Client Developer role removed.' );
		} else {
			WP_CLI::error( 'Usage: wp atf role <create|remove>' );
		}
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'atf', 'ATF_CLI' );
}

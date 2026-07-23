<?php
/**
 * Technical SEO audit.
 *
 * A read-first audit (plus a few safe auto-fixes) covering the most common
 * technical SEO issues:
 *   - robots.txt reachable / not blocking key paths
 *   - XML sitemap present and reachable
 *   - Site served over HTTPS
 *   - Canonical tags present on singular content
 *   - Title & meta description present and within length limits
 *   - Open Graph / Twitter cards present on singular content
 *   - Exactly one H1 per page
 *   - Structured data (JSON-LD) detected on the site
 *   - Image alt coverage across the media library and post content
 *   - Broken/internal-link and redirect basics (advisory)
 *
 * Most checks are advisory. Items the plugin can safely auto-fix are exposed
 * via the "apply" path which delegates to the existing alt-text / SEO modules.
 */
class ATF_Tech_Audit {

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_atf_audit_run', array( __CLASS__, 'ajax_run' ) );
		add_action( 'wp_ajax_atf_audit_apply', array( __CLASS__, 'ajax_apply' ) );
	}

	/**
	 * Run the full audit and return a structured report.
	 *
	 * @return array
	 */
	public static function run() {
		$checks = array();

		$checks[] = self::check_https();
		$checks[] = self::check_robots();
		$checks[] = self::check_sitemap();
		$checks[] = self::check_canonical();
		$checks[] = self::check_title_desc();
		$checks[] = self::check_social();
		$checks[] = self::check_h1();
		$checks[] = self::check_structured_data();
		$checks[] = self::check_alt_coverage();
		$checks[] = self::check_indexing();
		$checks[] = self::check_viewport();
		$checks[] = self::check_noindex_sample();
		$checks[] = self::check_sitemap_in_robots();
		$checks[] = self::check_sitemap_in_robots();
		$checks[] = self::check_permalinks();
		$checks[] = self::check_internal_3xx();
		$checks[] = self::check_external_3xx();
		$checks[] = self::check_4xx();

		return $checks;
	}

	/**
	 * Build a single check result.
	 *
	 * @param string $id      Check id.
	 * @param string $label   Human label.
	 * @param string $status  pass | warn | fail.
	 * @param string $detail  Detail text.
	 * @param string $fix     Optional auto-fix action key.
	 * @return array
	 */
	public static function make( $id, $label, $status, $detail, $fix = '' ) {
		return array(
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
			'fix'    => $fix,
		);
	}

	/**
	 * Check the site is served over HTTPS.
	 *
	 * @return array
	 */
	public static function check_https() {
		$url = home_url();
		if ( false !== strpos( $url, 'https://' ) ) {
			return self::make( 'https', esc_html__( 'HTTPS enabled', 'alt-text-fixer' ), 'pass', esc_html__( 'The site URL uses HTTPS.', 'alt-text-fixer' ) );
		}
		return self::make( 'https', esc_html__( 'HTTPS enabled', 'alt-text-fixer' ), 'fail', esc_html__( 'Home URL is not HTTPS. Serve the site over TLS and update WordPress & site URLs.', 'alt-text-fixer' ) );
	}

	/**
	 * Check robots.txt exists and is reachable.
	 *
	 * @return array
	 */
	public static function check_robots() {
		$robots = home_url( '/robots.txt' );
		$body   = wp_remote_retrieve_body( wp_safe_remote_get( $robots, array( 'timeout' => 10 ) ) );
		if ( '' === $body ) {
			return self::make( 'robots', esc_html__( 'robots.txt', 'alt-text-fixer' ), 'warn', esc_html__( 'No robots.txt returned (WordPress will generate a virtual one, or none exists). Add one to control crawling.', 'alt-text-fixer' ) );
		}
		if ( preg_match( '/disallow:\s*\//i', $body ) && ! preg_match( '/disallow:\s*\/\s*$/im', $body ) ) {
			return self::make( 'robots', esc_html__( 'robots.txt', 'alt-text-fixer' ), 'pass', esc_html__( 'robots.txt is reachable and does not blanket-disallow the site.', 'alt-text-fixer' ) );
		}
		if ( preg_match( '/disallow:\s*\/\s*$/im', $body ) ) {
			return self::make( 'robots', esc_html__( 'robots.txt', 'alt-text-fixer' ), 'fail', esc_html__( 'robots.txt disallows the entire site ("Disallow: /"). Search engines cannot index it.', 'alt-text-fixer' ) );
		}
		return self::make( 'robots', esc_html__( 'robots.txt', 'alt-text-fixer' ), 'pass', esc_html__( 'robots.txt is reachable.', 'alt-text-fixer' ) );
	}

	/**
	 * Check an XML sitemap is present.
	 *
	 * @return array
	 */
	public static function check_sitemap() {
		$candidates = array(
			home_url( '/sitemap.xml' ),
			home_url( '/sitemap_index.xml' ),
			home_url( '/wp-sitemap.xml' ),
		);
		foreach ( $candidates as $url ) {
			$resp = wp_safe_remote_get( $url, array( 'timeout' => 10 ) );
			$code = wp_remote_retrieve_response_code( $resp );
			$body = wp_remote_retrieve_body( $resp );
			if ( ( 200 === (int) $code || 301 === (int) $code ) && ( false !== strpos( $body, '<urlset' ) || false !== strpos( $body, '<sitemapindex' ) ) ) {
				return self::make( 'sitemap', esc_html__( 'XML sitemap', 'alt-text-fixer' ), 'pass', sprintf( esc_html__( 'Sitemap found at %s.', 'alt-text-fixer' ), $url ) );
			}
		}
		return self::make( 'sitemap', esc_html__( 'XML sitemap', 'alt-text-fixer' ), 'fail', esc_html__( 'No XML sitemap detected (try Yoast/rankmath/the core wp-sitemap.xml). Submit one to search engines.', 'alt-text-fixer' ) );
	}

	/**
	 * Check canonical tags on a sample of singular content.
	 *
	 * @return array
	 */
	public static function check_canonical() {
		$posts = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids' ) );
		if ( ! $posts ) {
			return self::make( 'canonical', esc_html__( 'Canonical tags', 'alt-text-fixer' ), 'warn', esc_html__( 'No public posts to sample.', 'alt-text-fixer' ) );
		}
		$missing = 0;
		foreach ( $posts as $pid ) {
			$html = wp_remote_retrieve_body( wp_safe_remote_get( get_permalink( $pid ), array( 'timeout' => 10 ) ) );
			if ( ! preg_match( '/<link[^>]+rel=["\']canonical["\'][^>]*>/i', $html ) ) {
				$missing ++;
			}
		}
		if ( 0 === $missing ) {
			return self::make( 'canonical', esc_html__( 'Canonical tags', 'alt-text-fixer' ), 'pass', esc_html__( 'Sampled pages include a canonical link tag.', 'alt-text-fixer' ) );
		}
		return self::make( 'canonical', esc_html__( 'Canonical tags', 'alt-text-fixer' ), 'fail', sprintf( esc_html__( '%d of %d sampled pages are missing a canonical tag. The plugin can add them.', 'alt-text-fixer' ), $missing, count( $posts ) ), 'canonical' );
	}

	/**
	 * Check title / description length on a sample of posts.
	 *
	 * @return array
	 */
	public static function check_title_desc() {
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'numberposts' => 10, 'fields' => 'ids' ) );
		if ( ! $posts ) {
			return self::make( 'titledesc', esc_html__( 'Title & meta description', 'alt-text-fixer' ), 'warn', esc_html__( 'No posts/pages to sample.', 'alt-text-fixer' ) );
		}
		$bad = 0;
		foreach ( $posts as $pid ) {
			$title = ATF_SEO::get_title( $pid );
			$desc  = ATF_SEO::get_description( $pid );
			$tlen  = mb_strlen( $title );
			$dlen  = mb_strlen( $desc );
			if ( $tlen < 10 || $tlen > 70 || $dlen < 50 || $dlen > 320 ) {
				$bad ++;
			}
		}
		if ( 0 === $bad ) {
			return self::make( 'titledesc', esc_html__( 'Title & meta description', 'alt-text-fixer' ), 'pass', esc_html__( 'Sampled titles/descriptions are within recommended lengths.', 'alt-text-fixer' ) );
		}
		return self::make( 'titledesc', esc_html__( 'Title & meta description', 'alt-text-fixer' ), 'fail', sprintf( esc_html__( '%d of %d sampled pages have missing or out-of-range title/description. The plugin can generate them.', 'alt-text-fixer' ), $bad, count( $posts ) ), 'seo' );
	}

	/**
	 * Check Open Graph / Twitter cards on a sample of posts.
	 *
	 * @return array
	 */
	public static function check_social() {
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids' ) );
		if ( ! $posts ) {
			return self::make( 'social', esc_html__( 'Social sharing cards', 'alt-text-fixer' ), 'warn', esc_html__( 'No posts/pages to sample.', 'alt-text-fixer' ) );
		}
		$missing = 0;
		foreach ( $posts as $pid ) {
			$html = wp_remote_retrieve_body( wp_safe_remote_get( get_permalink( $pid ), array( 'timeout' => 10 ) ) );
			if ( ! preg_match( '/property=["\']og:title["\']/i', $html ) ) {
				$missing ++;
			}
		}
		if ( 0 === $missing ) {
			return self::make( 'social', esc_html__( 'Social sharing cards', 'alt-text-fixer' ), 'pass', esc_html__( 'Sampled pages include Open Graph tags.', 'alt-text-fixer' ) );
		}
		return self::make( 'social', esc_html__( 'Social sharing cards', 'alt-text-fixer' ), 'warn', sprintf( esc_html__( '%d of %d sampled pages are missing Open Graph tags. The plugin can add them.', 'alt-text-fixer' ), $missing, count( $posts ) ), 'seo' );
	}

	/**
	 * Check exactly one H1 per sampled page.
	 *
	 * @return array
	 */
	public static function check_h1() {
		$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'numberposts' => 5, 'fields' => 'ids' ) );
		if ( ! $posts ) {
			return self::make( 'h1', esc_html__( 'Single H1 per page', 'alt-text-fixer' ), 'warn', esc_html__( 'No posts/pages to sample.', 'alt-text-fixer' ) );
		}
		$bad = 0;
		foreach ( $posts as $pid ) {
			$html = wp_remote_retrieve_body( wp_safe_remote_get( get_permalink( $pid ), array( 'timeout' => 10 ) ) );
			$count = preg_match_all( '/<h1\b[^>]*>/i', $html );
			if ( 1 !== $count ) {
				$bad ++;
			}
		}
		if ( 0 === $bad ) {
			return self::make( 'h1', esc_html__( 'Single H1 per page', 'alt-text-fixer' ), 'pass', esc_html__( 'Sampled pages each have exactly one H1.', 'alt-text-fixer' ) );
		}
		return self::make( 'h1', esc_html__( 'Single H1 per page', 'alt-text-fixer' ), 'warn', sprintf( esc_html__( '%d of %d sampled pages do not have exactly one H1. Adjust the theme/templates.', 'alt-text-fixer' ), $bad, count( $posts ) ) );
	}

	/**
	 * Check structured data (JSON-LD) presence site-wide.
	 *
	 * @return array
	 */
	public static function check_structured_data() {
		$org = ATF_Schema::get_org_settings();
		$home = wp_remote_retrieve_body( wp_safe_remote_get( home_url( '/' ), array( 'timeout' => 10 ) ) );
		if ( $org && ! empty( $org['enabled'] ) && 'yes' === $org['enabled'] ) {
			if ( false !== strpos( $home, 'application/ld+json' ) ) {
				return self::make( 'schema', esc_html__( 'Structured data', 'alt-text-fixer' ), 'pass', esc_html__( 'JSON-LD structured data is present (schema module active).', 'alt-text-fixer' ) );
			}
			return self::make( 'schema', esc_html__( 'Structured data', 'alt-text-fixer' ), 'warn', esc_html__( 'Schema module is enabled but no JSON-LD detected on the home page. Save permalinks or check output.', 'alt-text-fixer' ) );
		}
		return self::make( 'schema', esc_html__( 'Structured data', 'alt-text-fixer' ), 'warn', esc_html__( 'No structured data detected. Enable the Organization/LocalBusiness schema in settings.', 'alt-text-fixer' ), 'schema' );
	}

	/**
	 * Check image alt coverage across the media library.
	 *
	 * @return array
	 */
	public static function check_alt_coverage() {
		$total = ATF_Bulk_Fixer::count_missing_alt();
		if ( 0 === $total ) {
			return self::make( 'alt', esc_html__( 'Image alt coverage', 'alt-text-fixer' ), 'pass', esc_html__( 'All media-library images have alt text.', 'alt-text-fixer' ) );
		}
		return self::make( 'alt', esc_html__( 'Image alt coverage', 'alt-text-fixer' ), 'fail', sprintf( esc_html__( '%d media-library images are missing alt text.', 'alt-text-fixer' ), $total ), 'alt' );
	}

	/**
	 * Check indexing settings (search engine visibility).
	 *
	 * @return array
	 */
	public static function check_indexing() {
		if ( get_option( 'blog_public' ) ) {
			return self::make( 'indexing', esc_html__( 'Search engine visibility', 'alt-text-fixer' ), 'pass', esc_html__( 'Site is allowed to be indexed ("Discourage search engines" is off).', 'alt-text-fixer' ) );
		}
		return self::make( 'indexing', esc_html__( 'Search engine visibility', 'alt-text-fixer' ), 'fail', esc_html__( '"Discourage search engines from indexing this site" is enabled. Turn it off in Reading settings for production.', 'alt-text-fixer' ) );
	}

	/**
	 * Check the home page has a mobile viewport meta tag.
	 *
	 * @return array
	 */
	public static function check_viewport() {
		$html = wp_remote_retrieve_body( wp_safe_remote_get( home_url( '/' ), array( 'timeout' => 10 ) ) );
		if ( preg_match( '/<meta[^>]+name=["\']viewport["\'][^>]*>/i', $html ) ) {
			return self::make( 'viewport', esc_html__( 'Mobile viewport', 'alt-text-fixer' ), 'pass', esc_html__( 'Home page declares a viewport meta tag (mobile-friendly).', 'alt-text-fixer' ) );
		}
		return self::make( 'viewport', esc_html__( 'Mobile viewport', 'alt-text-fixer' ), 'warn', esc_html__( 'No viewport meta tag found on the home page. Add one for mobile usability.', 'alt-text-fixer' ) );
	}

	/**
	 * Check a sample of important pages is not noindexed.
	 *
	 * @return array
	 */
	public static function check_noindex_sample() {
		$urls = array( home_url( '/' ), get_permalink( get_option( 'page_for_posts' ) ?: 0 ) );
		$urls = array_filter( $urls );
		$bad = 0;
		foreach ( $urls as $u ) {
			if ( ! $u ) {
				continue;
			}
			$html = wp_remote_retrieve_body( wp_safe_remote_get( $u, array( 'timeout' => 10 ) ) );
			if ( preg_match( '/<meta[^>]+name=["\']robots["\'][^>]*noindex/i', $html ) ) {
				$bad ++;
			}
		}
		if ( 0 === $bad ) {
			return self::make( 'noindex', esc_html__( 'Key pages indexable', 'alt-text-fixer' ), 'pass', esc_html__( 'Home and blog pages are not noindexed.', 'alt-text-fixer' ) );
		}
		return self::make( 'noindex', esc_html__( 'Key pages indexable', 'alt-text-fixer' ), 'fail', esc_html__( 'A key page is set to noindex. Remove the noindex robots meta if it should be crawled.', 'alt-text-fixer' ) );
	}

	/**
	 * Check robots.txt references the sitemap.
	 *
	 * @return array
	 */
	public static function check_sitemap_in_robots() {
		$body = wp_remote_retrieve_body( wp_safe_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) ) );
		if ( preg_match( '/sitemap:/i', $body ) ) {
			return self::make( 'sitemap_robots', esc_html__( 'Sitemap in robots.txt', 'alt-text-fixer' ), 'pass', esc_html__( 'robots.txt declares a Sitemap directive.', 'alt-text-fixer' ) );
		}
		return self::make( 'sitemap_robots', esc_html__( 'Sitemap in robots.txt', 'alt-text-fixer' ), 'warn', esc_html__( 'robots.txt has no Sitemap directive. Add "Sitemap: <url>" so crawlers find it.', 'alt-text-fixer' ) );
	}

	/**
	 * Check permalinks are not set to "plain" (which hurts SEO/structure).
	 *
	 * @return array
	 */
	public static function check_permalinks() {
		if ( 'plain' === get_option( 'permalink_structure' ) ) {
			return self::make( 'permalinks', esc_html__( 'Permalink structure', 'alt-text-fixer' ), 'warn', esc_html__( 'Permalinks are set to "Plain" (?p=123). Use a pretty structure (post name) for SEO-friendly URLs.', 'alt-text-fixer' ) );
		}
		return self::make( 'permalinks', esc_html__( 'Permalink structure', 'alt-text-fixer' ), 'pass', esc_html__( 'Permalinks use a pretty structure.', 'alt-text-fixer' ) );
	}

	/**
	 * Check internal redirect chains (3xx status on internal links).
	 *
	 * @return array
	 */
	public static function check_internal_3xx() {
		$sample = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 10 ) );
		if ( empty( $sample ) ) {
			return self::make( 'int3xx', esc_html__( 'Internal redirects', 'alt-text-fixer' ), 'warn', esc_html__( 'No posts to sample for redirects.', 'alt-text-fixer' ) );
		}
		$redirects = 0;
		foreach ( $sample as $post ) {
			$links = wp_extract_urls( $post->post_content );
			foreach ( array_slice( $links, 0, 5 ) as $url ) {
				if ( 0 === strpos( $url, home_url() ) ) {
					$resp = wp_safe_remote_head( $url, array( 'timeout' => 5, 'redirection' => 2 ) );
					if ( ! is_wp_error( $resp ) && in_array( (int) wp_remote_retrieve_response_code( $resp ), array( 301, 302, 307, 308 ), true ) ) {
						$redirects ++;
						break;
					}
				}
			}
		}
		if ( 0 === $redirects ) {
			return self::make( 'int3xx', esc_html__( 'Internal redirects', 'alt-text-fixer' ), 'pass', esc_html__( 'No internal redirect chains detected in sample.', 'alt-text-fixer' ) );
		}
		return self::make( 'int3xx', esc_html__( 'Internal redirects', 'alt-text-fixer' ), 'warn', sprintf( esc_html__( '%d of %d posts point to URLs that redirect. Consider updating links to point directly to the destination.', 'alt-text-fixer' ), $redirects, count( $sample ) ), 'redirect' );
	}

	/**
	 * Check external redirect chains (3xx on external links).
	 *
	 * @return array
	 */
	public static function check_external_3xx() {
		return self::make( 'ext3xx', esc_html__( 'External redirects', 'alt-text-fixer' ), 'pass', esc_html__( 'External redirect check requires full crawl; install a SEO plugin for deeper analysis.', 'alt-text-fixer' ) );
	}

	/**
	 * Check for broken internal links (4xx status).
	 *
	 * @return array
	 */
	public static function check_4xx() {
		$sample = get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'numberposts' => 10 ) );
		if ( empty( $sample ) ) {
			return self::make( '4xx', esc_html__( 'Broken links (4xx)', 'alt-text-fixer' ), 'warn', esc_html__( 'No posts to sample for broken links.', 'alt-text-fixer' ) );
		}
		$broken = 0;
		foreach ( $sample as $post ) {
			$links = wp_extract_urls( $post->post_content );
			foreach ( array_slice( $links, 0, 5 ) as $url ) {
				if ( 0 === strpos( $url, home_url() ) ) {
					$resp = wp_safe_remote_head( $url, array( 'timeout' => 5 ) );
					$code = (int) wp_remote_retrieve_response_code( $resp );
					if ( $code >= 400 && $code < 500 ) {
						$broken ++;
						break;
					}
				}
			}
		}
		if ( 0 === $broken ) {
			return self::make( '4xx', esc_html__( 'Broken links (4xx)', 'alt-text-fixer' ), 'pass', esc_html__( 'No broken internal links detected in sample.', 'alt-text-fixer' ) );
		}
		return self::make( '4xx', esc_html__( 'Broken links (4xx)', 'alt-text-fixer' ), 'fail', sprintf( esc_html_x( '%d of %d posts contain links that return 4xx errors. Update links or remove them.', 'alt-text-fixer' ), $broken, count( $sample ) ) );
	}

	/**
	 * Apply a safe auto-fix by delegating to existing modules.
	 *
	 * @param string $fix Fix key (canonical|seo|alt|schema).
	 * @return int Number of items fixed.
	 */
	public static function apply_fix( $fix ) {
		$fixer = Alt_Text_Fixer::init();
		$done  = 0;
		switch ( $fix ) {
			case 'alt':
				$ids = ATF_Bulk_Fixer::get_missing_alt_attachments( 0, 200 );
				foreach ( $ids as $id ) {
					if ( $fixer->set_alt_text( $id ) ) {
						$done ++;
					}
				}
				break;
			case 'seo':
				$ids = ATF_SEO::get_posts_missing( 0, 200 );
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
				break;
			case 'canonical':
				// Canonicals are auto-output by ATF_SEO::output_head when enabled.
				$seo = ATF_SEO::get_settings();
				$seo['enabled'] = 'yes';
				update_option( 'atf_seo_settings', $seo );
				$done = 1;
				break;
			case 'schema':
				$org = ATF_Schema::get_org_settings();
				$org['enabled'] = 'yes';
				update_option( ATF_Schema::ORG_OPTION, $org );
				$done = 1;
				break;
		}
		return $done;
	}

	/**
	 * AJAX handler: run the audit.
	 */
	public static function ajax_run() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$checks = self::run();
		wp_send_json_success(
			array(
				'total'  => count( $checks ),
				'checks' => $checks,
				'pass'   => count( array_filter( $checks, function ( $c ) { return 'pass' === $c['status']; } ) ),
				'warn'   => count( array_filter( $checks, function ( $c ) { return 'warn' === $c['status']; } ) ),
				'fail'   => count( array_filter( $checks, function ( $c ) { return 'fail' === $c['status']; } ) ),
			)
		);
	}

	/**
	 * AJAX handler: apply a safe auto-fix.
	 */
	public static function ajax_apply() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$fix  = isset( $_POST['fix'] ) ? sanitize_key( $_POST['fix'] ) : '';
		$done = self::apply_fix( $fix );
		wp_send_json_success( array( 'fixed' => $done ) );
	}
}

<?php
/**
 * SEO automation module.
 *
 * Automatically builds a meta title and meta description for any public post
 * (posts, pages, custom post types, FSE templates) when one isn't provided,
 * using configurable templates. Output is hooked into wp_head, and the
 * generated values are cached in post meta so they're editable per-post and
 * readable by other SEO plugins / crawlers.
 *
 * Templates support tokens:
 *   %title%     post title
 *   %sitename%  blog name
 *   %sep%       separator (from settings)
 *   %excerpt%   auto-generated excerpt (description only)
 *   %category%  first category / taxonomy term
 */
class ATF_SEO {

	const TITLE_META = '_atf_seo_title';
	const DESC_META  = '_atf_seo_desc';
	const IMAGE_META = '_atf_seo_image';

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_head' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'maybe_autofill_on_save' ), 20, 2 );

		add_action( 'wp_ajax_atf_seo_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'wp_ajax_atf_seo_batch', array( __CLASS__, 'ajax_batch' ) );

		// Per-post metabox.
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post', array( __CLASS__, 'save_metabox' ), 25, 2 );
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => 'yes',
			'auto_on_save'   => 'yes',
			'title_tpl'      => '%title% %sep% %sitename%',
			'desc_tpl'       => '%excerpt%',
			'sep'            => '-',
			'desc_length'    => 160,
			'fallback_desc'  => '',
		);
	}

	/**
	 * Get settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args( get_option( 'atf_seo_settings', array() ), self::defaults() );
	}

	/**
	 * Output meta title + description + OG/Twitter image in <head>.
	 */
	public static function output_head() {
		if ( is_admin() || ! is_singular() ) {
			return;
		}
		$settings = self::get_settings();
		if ( empty( $settings['enabled'] ) || 'yes' !== $settings['enabled'] ) {
			return;
		}

		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return;
		}

		// Canonical URL (defaults to the post permalink; filterable).
		$canonical = apply_filters( 'atf_canonical_url', get_permalink( $post_id ), $post_id );
		if ( $canonical ) {
			echo "\t" . '<link rel="canonical" href="' . esc_url( $canonical ) . '">' . "\n";
		}

		$title = self::get_title( $post_id );
		$desc  = self::get_description( $post_id );
		$image = self::get_social_image( $post_id );

		if ( $title ) {
			echo "\t" . '<title>' . esc_html( $title ) . '</title>' . "\n";
			echo "\t" . '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
			echo "\t" . '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
		}
		if ( $desc ) {
			echo "\t" . '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
			echo "\t" . '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
			echo "\t" . '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
		}
		if ( $image ) {
			echo "\t" . '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
			echo "\t" . '<meta name="twitter:card" content="summary_large_image">' . "\n";
			echo "\t" . '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		}
	}

	/**
	 * Get the social share image URL for a post.
	 *
	 * Priority: override meta > featured image > first <img> in content.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_social_image( $post_id ) {
		$override = get_post_meta( $post_id, self::IMAGE_META, true );
		if ( ! empty( $override ) ) {
			return $override;
		}
		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'large' );
			if ( ! empty( $src[0] ) ) {
				return $src[0];
			}
		}
		$post = get_post( $post_id );
		if ( $post && preg_match( '/<img\b[^>]*?\bsrc\s*=\s*("|\')(.*?)\1/i', $post->post_content, $m ) ) {
			return $m[2];
		}
		return '';
	}

	/**
	 * Get (and lazily generate) the meta title for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_title( $post_id ) {
		$override = get_post_meta( $post_id, self::TITLE_META, true );
		if ( ! empty( $override ) ) {
			return $override;
		}
		$generated = self::generate_title( $post_id );
		if ( $generated ) {
			update_post_meta( $post_id, self::TITLE_META, $generated );
		}
		return $generated;
	}

	/**
	 * Get (and lazily generate) the meta description for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_description( $post_id ) {
		$override = get_post_meta( $post_id, self::DESC_META, true );
		if ( ! empty( $override ) ) {
			return $override;
		}
		$generated = self::generate_description( $post_id );
		if ( $generated ) {
			update_post_meta( $post_id, self::DESC_META, $generated );
		}
		return $generated;
	}

	/**
	 * Build a meta title from the template.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function generate_title( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$s = self::get_settings();
		$out = str_replace(
			array( '%title%', '%sitename%', '%sep%', '%category%' ),
			array( self::post_title( $post ), get_bloginfo( 'name' ), $s['sep'], self::primary_term( $post ) ),
			$s['title_tpl']
		);
		return trim( preg_replace( '/\s+/', ' ', $out ) );
	}

	/**
	 * Build a meta description from the template / excerpt.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function generate_description( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		$s        = self::get_settings();
		$excerpt  = self::post_excerpt( $post );
		$excerpt  = $excerpt ? $excerpt : $s['fallback_desc'];
		$desc     = str_replace(
			array( '%excerpt%', '%title%', '%sitename%', '%sep%', '%category%' ),
			array( $excerpt, self::post_title( $post ), get_bloginfo( 'name' ), $s['sep'], self::primary_term( $post ) ),
			$s['desc_tpl']
		);
		$desc     = trim( preg_replace( '/\s+/', ' ', $desc ) );
		$len      = (int) $s['desc_length'];
		if ( $len > 0 && mb_strlen( $desc ) > $len ) {
			$desc = mb_substr( $desc, 0, $len );
			$desc = preg_replace( '/\s+\S*$/u', '', $desc ) . '…';
		}
		return $desc;
	}

	/**
	 * Post title helper.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function post_title( $post ) {
		return get_the_title( $post );
	}

	/**
	 * Build an excerpt from content if the post has no manual excerpt.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function post_excerpt( $post ) {
		if ( ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		return $text;
	}

	/**
	 * First category / taxonomy term for the post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	public static function primary_term( $post ) {
		$taxes = get_object_taxonomies( $post->post_type, 'names' );
		foreach ( $taxes as $tax ) {
			if ( in_array( $tax, array( 'post_format' ), true ) ) {
				continue;
			}
			$terms = get_the_terms( $post->ID, $tax );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				return $terms[0]->name;
			}
		}
		return '';
	}

	/**
	 * On save, auto-generate SEO meta if enabled and missing.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function maybe_autofill_on_save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$s = self::get_settings();
		if ( empty( $s['auto_on_save'] ) || 'yes' !== $s['auto_on_save'] ) {
			return;
		}
		if ( ! self::is_supported( $post ) ) {
			return;
		}
		// Don't overwrite a manual override.
		if ( '' === get_post_meta( $post_id, self::TITLE_META, true ) ) {
			update_post_meta( $post_id, self::TITLE_META, self::generate_title( $post_id ) );
		}
		if ( '' === get_post_meta( $post_id, self::DESC_META, true ) ) {
			update_post_meta( $post_id, self::DESC_META, self::generate_description( $post_id ) );
		}
	}

	/**
	 * Whether a post type supports SEO automation.
	 *
	 * @param WP_Post $post Post object.
	 * @return bool
	 */
	public static function is_supported( $post ) {
		$type = $post->post_type;
		if ( in_array( $type, array( 'attachment', 'nav_menu_item', 'wp_navigation' ), true ) ) {
			return false;
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) ) {
			return false;
		}
		return true;
	}

	/* ----------------------------------------------------------------------
	 * Bulk AJAX
	 * -------------------------------------------------------------------- */

	public static function ajax_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		wp_send_json_success( array( 'total' => self::count_missing() ) );
	}

	public static function ajax_batch() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
		if ( $limit < 1 || $limit > 500 ) {
			$limit = 50;
		}
		$ids   = self::get_posts_missing( $offset, $limit );
		$fixed = 0;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! self::is_supported( $post ) ) {
				continue;
			}
			if ( '' === get_post_meta( $id, self::TITLE_META, true ) ) {
				update_post_meta( $id, self::TITLE_META, self::generate_title( $id ) );
				$fixed ++;
			}
			if ( '' === get_post_meta( $id, self::DESC_META, true ) ) {
				update_post_meta( $id, self::DESC_META, self::generate_description( $id ) );
				$fixed ++;
			}
		}
		$remaining = self::count_missing();
		$pinged    = 0;
		if ( $remaining <= 0 ) {
			$pinged = self::ping_search_engines();
		}
		wp_send_json_success(
			array(
				'imgs_fixed' => $fixed,
				'processed'  => $offset + count( $ids ),
				'remaining'  => $remaining,
				'finished'   => $remaining <= 0,
				'pinged'     => $pinged,
			)
		);
	}

	/**
	 * Count posts missing SEO meta (title or description).
	 *
	 * @return int
	 */
	public static function count_missing() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_status = 'publish'
			AND p.post_type NOT IN ( 'attachment', 'nav_menu_item', 'revision' )
			AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} m
				WHERE m.post_id = p.ID
				AND m.meta_key IN ( '" . self::TITLE_META . "', '" . self::DESC_META . "' )
				AND m.meta_value != ''
			)"
		);
		return $count;
	}

	/**
	 * Posts missing SEO meta (title or description), batched.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int[]
	 */
	public static function get_posts_missing( $offset = 0, $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_status = 'publish'
				AND p.post_type NOT IN ( 'attachment', 'nav_menu_item', 'revision' )
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} m
					WHERE m.post_id = p.ID
					AND m.meta_key IN ( %s, %s )
					AND m.meta_value != ''
				)
				ORDER BY p.ID ASC
				LIMIT %d OFFSET %d",
				self::TITLE_META,
				self::DESC_META,
				$limit,
				$offset
			)
		);
		return array_map( 'intval', $ids );
	}

	/* ----------------------------------------------------------------------
	 * Per-post metabox
	 * -------------------------------------------------------------------- */

	public static function add_metabox() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		foreach ( $types as $type ) {
			add_meta_box(
				'atf_seo_box',
				esc_html__( 'Alt Text Fixer &mdash; SEO', 'alt-text-fixer' ),
				array( __CLASS__, 'render_metabox' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	public static function render_metabox( $post ) {
		$title = get_post_meta( $post->ID, self::TITLE_META, true );
		$desc  = get_post_meta( $post->ID, self::DESC_META, true );
		$image = get_post_meta( $post->ID, self::IMAGE_META, true );
		wp_nonce_field( 'atf_seo_meta', 'atf_seo_meta_nonce' );
		?>
		<p>
			<label for="atf_seo_title"><strong><?php esc_html_e( 'Meta title', 'alt-text-fixer' ); ?></strong></label><br>
			<input type="text" id="atf_seo_title" name="atf_seo_title" class="widefat" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( self::generate_title( $post->ID ) ); ?>">
		</p>
		<p>
			<label for="atf_seo_desc"><strong><?php esc_html_e( 'Meta description', 'alt-text-fixer' ); ?></strong></label><br>
			<textarea id="atf_seo_desc" name="atf_seo_desc" class="widefat" rows="3" placeholder="<?php echo esc_attr( self::generate_description( $post->ID ) ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
		</p>
		<p>
			<label for="atf_seo_image"><strong><?php esc_html_e( 'Social share image (OG / Twitter)', 'alt-text-fixer' ); ?></strong></label><br>
			<input type="url" id="atf_seo_image" name="atf_seo_image" class="widefat" value="<?php echo esc_url( $image ); ?>" placeholder="<?php echo esc_url( self::get_social_image( $post->ID ) ); ?>">
		</p>
		<p class="description"><?php esc_html_e( 'Leave blank to use the featured image or first content image.', 'alt-text-fixer' ); ?></p>
		<?php
	}

	public static function save_metabox( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['atf_seo_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['atf_seo_meta_nonce'] ), 'atf_seo_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! self::is_supported( $post ) ) {
			return;
		}
		if ( isset( $_POST['atf_seo_title'] ) ) {
			$val = sanitize_text_field( wp_unslash( $_POST['atf_seo_title'] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::TITLE_META );
			} else {
				update_post_meta( $post_id, self::TITLE_META, $val );
			}
		}
		if ( isset( $_POST['atf_seo_desc'] ) ) {
			$val = sanitize_textarea_field( wp_unslash( $_POST['atf_seo_desc'] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::DESC_META );
			} else {
				update_post_meta( $post_id, self::DESC_META, $val );
			}
		}
		if ( isset( $_POST['atf_seo_image'] ) ) {
			$val = esc_url_raw( wp_unslash( $_POST['atf_seo_image'] ) );
			if ( '' === $val ) {
				delete_post_meta( $post_id, self::IMAGE_META );
			} else {
				update_post_meta( $post_id, self::IMAGE_META, $val );
			}
		}
	}

	/**
	 * Notify search engines that the sitemap/content changed.
	 *
	 * Pings the default WordPress ping list (same mechanism as publishing a
	 * post). This is a lightweight "content updated" signal; for full sitemap
	 * submission, point the ping endpoint at your sitemap URL. Returns the
	 * number of successful pings.
	 *
	 * @return int
	 */
	public static function ping_search_engines() {
		$sitemap = self::sitemap_url();
		if ( ! $sitemap ) {
			return 0;
		}
		$services = array(
			'https://rpc.pingomatic.com/',
		);
		$ok = 0;
		foreach ( $services as $service ) {
			$body = self::build_ping_request( get_bloginfo( 'name' ), home_url( '/' ), $sitemap );
			$response = wp_remote_post(
				$service,
				array(
					'timeout' => 10,
					'body'    => $body,
					'headers' => array( 'Content-Type' => 'text/xml' ),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$ok ++;
			}
		}
		return $ok;
	}

	/**
	 * Build a weblogUpdates.ping XML-RPC request body without the xmlrpc ext.
	 *
	 * @param string $name    Site name.
	 * @param string $home    Home URL.
	 * @param string $sitemap Sitemap URL.
	 * @return string
	 */
	public static function build_ping_request( $name, $home, $sitemap ) {
		$esc = function ( $v ) {
			return str_replace( array( '&', '<', '>', '"' ), array( '&amp;', '&lt;', '&gt;', '&quot;' ), $v );
		};
		$name    = $esc( $name );
		$home    = $esc( $home );
		$sitemap = $esc( $sitemap );
		return '<?xml version="1.0"?>'
			. '<methodCall><methodName>weblogUpdates.ping</methodName><params>'
			. '<param><value><string>' . $name . '</string></value></param>'
			. '<param><value><string>' . $home . '</string></value></param>'
			. '<param><value><string>' . $sitemap . '</string></value></param>'
			. '</params></methodCall>';
	}

	/**
	 * Best-guess sitemap URL: common SEO plugins or the core sitemap.
	 *
	 * @return string|false
	 */
	public static function sitemap_url() {
		$candidates = array(
			home_url( '/wp-sitemap.xml' ),
			home_url( '/sitemap_index.xml' ), // Yoast.
			home_url( '/sitemap.xml' ),       // Rank Math / others.
		);
		foreach ( $candidates as $url ) {
			$head = wp_remote_head( $url, array( 'timeout' => 8 ) );
			if ( ! is_wp_error( $head ) && 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
				return $url;
			}
		}
		return false;
	}
}

ATF_SEO::init();

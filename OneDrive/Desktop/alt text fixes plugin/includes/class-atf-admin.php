<?php
/**
 * Admin settings page under "Settings > Alt Text Fixer".
 * Simplified for alt-text fixes only.
 */
class ATF_Admin {

	const CLIENT_ROLE = 'atf_client_developer';
	const CAP         = 'atf_access';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'import_notice' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 100 );
		add_action( 'admin_post_atf_install_model', array( __CLASS__, 'handle_model_install' ) );
		add_action( 'admin_post_atf_review_revert', array( __CLASS__, 'handle_review_revert' ) );
		add_action( 'admin_post_atf_review_save', array( __CLASS__, 'handle_review_save' ) );
		add_action( 'admin_post_atf_review_clear', array( __CLASS__, 'handle_review_clear' ) );
	}

	public static function dashboard_widget() {
		wp_add_dashboard_widget(
			'atf_status_widget',
			esc_html__( 'Alt Text Fixer status', 'alt-text-fixer' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	public static function render_dashboard_widget() {
		$summary = ATF_Cron::remaining_summary();
		$total   = array_sum( $summary );
		echo '<p>';
		if ( 0 === $total ) {
			echo '<span class="atf-ok">' . esc_html__( 'All caught up — no missing alt text.', 'alt-text-fixer' ) . '</span>';
		} else {
			echo esc_html__( 'Remaining items to fix:', 'alt-text-fixer' );
		}
		echo '</p>';
		if ( $total > 0 ) {
			echo '<ul style="margin:0;list-style:none">';
			$labels = array(
				'library' => esc_html__( 'Media library images', 'alt-text-fixer' ),
				'content' => esc_html__( 'Embedded in content', 'alt-text-fixer' ),
				'meta'    => esc_html__( 'In post meta', 'alt-text-fixer' ),
				'css'     => esc_html__( 'CSS background images', 'alt-text-fixer' ),
				'global'  => esc_html__( 'Widgets / customizer', 'alt-text-fixer' ),
			);
			foreach ( $summary as $key => $count ) {
				printf(
					'<li>%s: <strong>%d</strong></li>',
					esc_html( $labels[ $key ] ),
					(int) $count
				);
			}
			echo '</ul>';
		}
	}

	public static function admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$summary = ATF_Cron::remaining_summary();
		$total   = array_sum( $summary );
		$title   = esc_html__( 'Himalayan Auto-Fixer', 'alt-text-fixer' );
		if ( $total > 0 ) {
			$title .= ' <span class="ab-item-count">' . (int) $total . '</span>';
		}
		$wp_admin_bar->add_node( array(
			'id'    => 'atf-admin-bar',
			'title' => $title,
			'href'  => admin_url( 'admin.php?page=alt-text-fixer' ),
		) );
		$wp_admin_bar->add_node( array(
			'id'     => 'atf-dashboard',
			'parent' => 'atf-admin-bar',
			'title'  => esc_html__( 'Dashboard', 'alt-text-fixer' ),
			'href'   => admin_url( 'admin.php?page=alt-text-fixer' ),
		) );
		$wp_admin_bar->add_node( array(
			'id'     => 'atf-settings',
			'parent' => 'atf-admin-bar',
			'title'  => esc_html__( 'Settings', 'alt-text-fixer' ),
			'href'   => admin_url( 'admin.php?page=alt-text-fixer-settings' ),
		) );
		$wp_admin_bar->add_node( array(
			'id'     => 'atf-media',
			'parent' => 'atf-admin-bar',
			'title'  => esc_html__( 'Media Library', 'alt-text-fixer' ),
			'href'   => admin_url( 'upload.php' ),
		) );
	}

	public static function capability() {
		$opts = get_option( 'atf_settings', array() );
		$cap  = isset( $opts['access_cap'] ) ? $opts['access_cap'] : 'manage_options';
		return self::is_valid_cap( $cap ) ? $cap : 'manage_options';
	}

	public static function is_valid_cap( $cap ) {
		$valid = array(
			'manage_options', 'manage_categories', 'edit_others_posts',
			'edit_posts', 'publish_posts', 'upload_files', 'edit_pages',
			self::CAP,
		);
		return in_array( $cap, $valid, true );
	}

	public static function sync_role() {
		$role = get_role( self::CLIENT_ROLE );
		if ( $role ) {
			$role->add_cap( self::CAP );
			return;
		}
		add_role(
			self::CLIENT_ROLE,
			esc_html__( 'Client Developer', 'alt-text-fixer' ),
			array( 'read' => true, self::CAP => true )
		);
	}

	public static function remove_role() {
		remove_role( self::CLIENT_ROLE );
	}

	public static function register_page() {
		$cap = self::capability();
		add_menu_page(
			esc_html__( 'Himalayan Auto-Fixer', 'alt-text-fixer' ),
			esc_html__( 'Himalayan Auto-Fixer', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-admin-tools',
			30
		);
		add_submenu_page(
			'alt-text-fixer',
			esc_html__( 'Dashboard', 'alt-text-fixer' ),
			esc_html__( 'Dashboard', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer',
			array( __CLASS__, 'render_dashboard' )
		);
		add_submenu_page(
			'alt-text-fixer',
			esc_html__( 'Settings', 'alt-text-fixer' ),
			esc_html__( 'Settings', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer-settings',
			array( __CLASS__, 'render_page' )
		);
		add_submenu_page(
			'alt-text-fixer',
			esc_html__( 'Redirect CSV Import', 'alt-text-fixer' ),
			esc_html__( 'Redirect CSV Import', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer-redirects',
			array( __CLASS__, 'render_redirect_import' )
		);
		add_submenu_page(
			'alt-text-fixer',
			esc_html__( 'Model Installer', 'alt-text-fixer' ),
			esc_html__( 'Model Installer', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer-model',
			array( __CLASS__, 'render_model_installer' )
		);
		add_submenu_page(
			'alt-text-fixer',
			esc_html__( 'Review Changes', 'alt-text-fixer' ),
			esc_html__( 'Review Changes', 'alt-text-fixer' ),
			$cap,
			'alt-text-fixer-review',
			array( __CLASS__, 'render_review_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'atf_settings_group', 'atf_settings', array( __CLASS__, 'sanitize' ) );

		add_settings_section(
			'atf_main',
			esc_html__( 'Main Settings', 'alt-text-fixer' ),
			'__return_false',
			'alt-text-fixer'
		);

		add_settings_field(
			'auto_on_upload',
			esc_html__( 'Auto-fill on upload', 'alt-text-fixer' ),
			array( __CLASS__, 'field_auto_on_upload' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'source',
			esc_html__( 'Alt text source', 'alt-text-fixer' ),
			array( __CLASS__, 'field_source' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'append_site',
			esc_html__( 'Append site name', 'alt-text-fixer' ),
			array( __CLASS__, 'field_append_site' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'auto_schedule',
			esc_html__( 'Daily auto-fix schedule', 'alt-text-fixer' ),
			array( __CLASS__, 'field_auto_schedule' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'access_cap',
			esc_html__( 'Access capability', 'alt-text-fixer' ),
			array( __CLASS__, 'field_access_cap' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'exclusions',
			esc_html__( 'Exclusions (IDs)', 'alt-text-fixer' ),
			array( __CLASS__, 'field_exclusions' ),
			'alt-text-fixer',
			'atf_main'
		);
		add_settings_field(
			'dashboard_limit',
			esc_html__( 'Dashboard list limit', 'alt-text-fixer' ),
			array( __CLASS__, 'field_dashboard_limit' ),
			'alt-text-fixer',
			'atf_main'
		);
	}

	public static function field_auto_on_upload() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['auto_on_upload'] ) ? $opts['auto_on_upload'] : 'yes';
		printf(
			'<label><input type="checkbox" name="atf_settings[auto_on_upload]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Automatically add alt text when a new image is uploaded.', 'alt-text-fixer' )
		);
	}

	public static function field_source() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['source'] ) ? $opts['source'] : 'title';
		?>
		<label><input type="radio" name="atf_settings[source]" value="title" <?php checked( 'title', $val ); ?>>
			<?php esc_html_e( 'Use the attachment title', 'alt-text-fixer' ); ?></label><br>
		<label><input type="radio" name="atf_settings[source]" value="filename" <?php checked( 'filename', $val ); ?>>
			<?php esc_html_e( 'Use the file name', 'alt-text-fixer' ); ?></label>
		<?php
	}

	public static function field_append_site() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['append_site'] ) ? $opts['append_site'] : 'no';
		printf(
			'<label><input type="checkbox" name="atf_settings[append_site]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Append the site name to the alt text (e.g. "Mountain - My Site").', 'alt-text-fixer' )
		);
	}

	public static function field_auto_schedule() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['auto_schedule'] ) ? $opts['auto_schedule'] : 'no';
		printf(
			'<label><input type="checkbox" name="atf_settings[auto_schedule]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Automatically fix new missing alt text every day (WP-Cron).', 'alt-text-fixer' )
		);
	}

	public static function field_exclusions() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['exclusions'] ) ? $opts['exclusions'] : '';
		printf(
			'<textarea class="large-text" name="atf_settings[exclusions]" rows="2">%s</textarea><br><span class="description">%s</span>',
			esc_textarea( $val ),
			esc_html__( 'Comma-separated IDs of posts or attachments to skip, e.g. 12, 45, 102.', 'alt-text-fixer' )
		);
	}

	public static function field_dashboard_limit() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['dashboard_limit'] ) ? absint( $opts['dashboard_limit'] ) : 100;
		printf(
			'<input type="number" min="1" max="500" name="atf_settings[dashboard_limit]" value="%d" class="small-text"> <span class="description">%s</span>',
			$val,
			esc_html__( 'Number of missing media items to show on the dashboard. Max 500.', 'alt-text-fixer' )
		);
	}

	public static function field_access_cap() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['access_cap'] ) ? $opts['access_cap'] : 'manage_options';
		$caps = array(
			'manage_options'     => esc_html__( 'Administrator (manage_options)', 'alt-text-fixer' ),
			'manage_categories' => esc_html__( 'Editor (manage_categories)', 'alt-text-fixer' ),
			'edit_others_posts' => esc_html__( 'Editor-level (edit_others_posts)', 'alt-text-fixer' ),
			'publish_posts'     => esc_html__( 'Author (publish_posts)', 'alt-text-fixer' ),
			'edit_posts'        => esc_html__( 'Contributor (edit_posts)', 'alt-text-fixer' ),
			'upload_files'      => esc_html__( 'Uploader (upload_files)', 'alt-text-fixer' ),
			self::CAP           => esc_html__( 'Client Developer (custom role)', 'alt-text-fixer' ),
		);
		echo '<select name="atf_settings[access_cap]">';
		foreach ( $caps as $c => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $c ), selected( $c, $val, false ), esc_html( $label ) );
		}
		echo '</select><br><span class="description">' . esc_html__( 'Minimum capability needed to see the Himalayan Auto-Fixer menu. Choose "Client Developer" to use the auto-created role.', 'alt-text-fixer' ) . '</span>';
	}

	public static function render_dashboard() {
		$summary = ATF_Cron::remaining_summary();
		$total   = array_sum( $summary );

		wp_enqueue_script( 'atf-bulk', ATF_URL . 'assets/bulk.js', array( 'jquery' ), ATF_VERSION, true );
		wp_localize_script(
			'atf-bulk',
			'ATF',
			array(
				'nonce'    => wp_create_nonce( 'atf_batch_nonce' ),
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
			)
		);
		?>
		<div class="wrap atf-dashboard">
			<h1><?php esc_html_e( 'Himalayan Auto-Fixer', 'alt-text-fixer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Alt text automation for WordPress. This dashboard is for the site developer/administrator.', 'alt-text-fixer' ); ?></p>

			<div class="atf-dash-grid">
				<div class="atf-dash-card">
					<h2><?php esc_html_e( 'Alt text', 'alt-text-fixer' ); ?></h2>
					<div class="atf-dash-num"><?php echo (int) array_sum( array( $summary['library'], $summary['content'], $summary['meta'], $summary['css'], $summary['global'] ) ); ?></div>
					<p><?php esc_html_e( 'items still need fixing', 'alt-text-fixer' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Open fixers', 'alt-text-fixer' ); ?></a>
				</div>
			</div>

			<h2><?php esc_html_e( 'Quick actions', 'alt-text-fixer' ); ?></h2>
			<p>
				<button type="button" id="atf-run-all" class="button button-primary"><?php esc_html_e( 'Run all fixes', 'alt-text-fixer' ); ?></button>
				<span class="spinner atf-spinner" data-target="runall"></span>
				<span id="atf-run-all-status" class="description"></span>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'alt-text-fixer' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Media library', 'alt-text-fixer' ); ?></a>
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-review' ) ); ?>"><?php esc_html_e( 'Review Changes & Revert', 'alt-text-fixer' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Media Library images missing alt', 'alt-text-fixer' ); ?></h2>
			<?php
			$opts  = get_option( 'atf_settings', array() );
			$limit = isset( $opts['dashboard_limit'] ) ? absint( $opts['dashboard_limit'] ) : 100;
			$limit = max( 1, min( 500, $limit ) );
			$missing_ids = ATF_Bulk_Fixer::get_missing_alt_attachments( 0, $limit );
			if ( ! empty( $missing_ids ) ) :
			?>
			<table class="widefat striped">
				<thead><tr><th>ID</th><th>File</th><th>Title</th><th>Preview</th><th>Action</th></tr></thead>
				<tbody>
				<?php foreach ( $missing_ids as $id ) : ?>
					<tr>
						<td><?php echo (int) $id; ?></td>
						<td><?php echo esc_html( basename( get_attached_file( $id ) ) ); ?></td>
						<td><?php echo esc_html( get_the_title( $id ) ); ?></td>
						<td><?php echo wp_get_attachment_image( $id, array( 60, 60 ) ); ?></td>
						<td>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'atf_fix_one', 'attachment_id' => $id ), admin_url( 'admin-post.php' ) ), 'atf_fix_one' ) ); ?>"><?php esc_html_e( 'Fix alt', 'alt-text-fixer' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php printf( esc_html__( 'Showing %d of %d missing images. Use the Settings page for bulk fix.', 'alt-text-fixer' ), count( $missing_ids ), (int) $summary['library'] ); ?></p>
			<?php else : ?>
				<p><strong><?php esc_html_e( 'All media library images have alt text.', 'alt-text-fixer' ); ?></strong></p>
			<?php endif; ?>

			<div id="atf-tech-audit-result"></div>

			<h2><?php esc_html_e( 'Audit detail', 'alt-text-fixer' ); ?></h2>
			<button type="button" id="atf-export-audit" class="button button-secondary"><?php esc_html_e( 'Export Results', 'alt-text-fixer' ); ?></button>
			<table class="widefat" style="max-width:880px">
				<thead><tr><th><?php esc_html_e( 'Check', 'alt-text-fixer' ); ?></th><th><?php esc_html_e( 'Status', 'alt-text-fixer' ); ?></th><th><?php esc_html_e( 'Detail', 'alt-text-fixer' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $summary as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c ); ?></td>
						<td><span class="atf-ok">OK</span></td>
						<td></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public static function ajax_run_all() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_send_json_error( 'permission' );
		}

		$fixer = Alt_Text_Fixer::init();

		// Library.
		$lib = 0;
		foreach ( ATF_Bulk_Fixer::get_missing_alt_attachments( 0, 500 ) as $id ) {
			if ( $fixer->set_alt_text( $id ) ) {
				$lib ++;
			}
		}

		// Posts: content / meta / css.
		$content = $meta = $css = 0;
		$batchSize = 100;
		$offset = 0;
		while ( true ) {
			$posts = ATF_Content_Fixer::get_posts( $offset, $batchSize );
			if ( empty( $posts ) ) {
				break;
			}
			foreach ( $posts as $pid ) {
				$content += ATF_Content_Fixer::fix_post_scope( $pid, 'content', $fixer );
				$meta    += ATF_Content_Fixer::fix_post_scope( $pid, 'meta', $fixer );
				$css     += ATF_Content_Fixer::fix_post_scope( $pid, 'css', $fixer );
			}
			$offset += $batchSize;
			if ( count( $posts ) < $batchSize ) {
				break;
			}
		}

		// Widgets / customizer options.
		$opts = ATF_Content_Fixer::global_option_names();
		ATF_Content_Fixer::fix_global( 'global', $fixer, $opts );

		wp_send_json_success(
			array(
				'lib' => $lib,
				'content' => $content,
				'meta'    => $meta,
				'css'     => $css,
			)
		);
	}

	public static function render_page() {
		$lib_count     = ATF_Bulk_Fixer::count_missing_alt();
		$content_count = ATF_Content_Fixer::count_scope( 'content' );
		$meta_count    = ATF_Content_Fixer::count_scope( 'meta' );
		$css_count     = ATF_Content_Fixer::count_scope( 'css' );
		$global_count  = ATF_Content_Fixer::count_scope( 'global' );

		wp_enqueue_script( 'atf-bulk', ATF_URL . 'assets/bulk.js', array( 'jquery' ), ATF_VERSION, true );
		wp_localize_script(
			'atf-bulk',
			'ATF',
			array(
				'nonce'    => wp_create_nonce( 'atf_batch_nonce' ),
				'ajaxurl'  => admin_url( 'admin-ajax.php' ),
				'libBatch' => ATF_Bulk_Fixer::BATCH_SIZE,
				'conBatch' => ATF_Content_Fixer::BATCH_SIZE,
				'metaBatch'=> ATF_Content_Fixer::BATCH_SIZE,
				'cssBatch' => ATF_Content_Fixer::BATCH_SIZE,
				'globalBatch' => ATF_Content_Fixer::BATCH_SIZE,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Himalayan Auto-Fixer', 'alt-text-fixer' ); ?></h1>

			<?php
			self::render_card( 'lib', esc_html__( 'Media Library images', 'alt-text-fixer' ), $lib_count, 'atf-lib-count', esc_html__( 'There is %d media-library image missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d media-library images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix media library alt text', 'alt-text-fixer' ), esc_html__( 'All media-library images have alt text.' ) );

			self::render_card( 'content', esc_html__( 'Images embedded in content', 'alt-text-fixer' ), $content_count, 'atf-content-count', esc_html__( 'There is %d post/page with embedded images missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages with embedded images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix embedded image alt text', 'alt-text-fixer' ), esc_html__( 'All embedded images already have alt text.' ) );

			self::render_card( 'meta', esc_html__( 'Images in post meta (page builders / ACF)', 'alt-text-fixer' ), $meta_count, 'atf-meta-count', esc_html__( 'There is %d post/page with image URLs in meta missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages with image URLs in meta missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix meta image alt text', 'alt-text-fixer' ), esc_html__( 'All meta images already have alt text.' ) );

		self::render_card( 'css', esc_html__( 'CSS background images', 'alt-text-fixer' ), $css_count, 'atf-css-count', esc_html__( 'There is %d post/page using CSS background images that need marking decorative.', 'alt-text-fixer' ), esc_html__( 'There are %d post/page using CSS background images that need marking decorative.', 'alt-text-fixer' ), esc_html__( 'Mark background images decorative', 'alt-text-fixer' ), esc_html__( 'All CSS background images are already handled.' ) );

		self::render_card( 'global', esc_html__( 'Widgets, customizer & nav (options)', 'alt-text-fixer' ), $global_count, 'atf-global-count', esc_html__( 'There is %d widget/customizer option with images missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d widget/customizer options with images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix option images alt text', 'alt-text-fixer' ), esc_html__( 'All option images already have alt text.' ) );
		?>

		<form method="post" action="options.php">
			<?php
			settings_fields( 'atf_settings_group' );
			do_settings_sections( 'alt-text-fixer' );
			submit_button();
			?>
		</form>
		<?php
	}

	public static function render_card( $target, $title, $count, $count_id, $one, $many, $button, $done ) {
		?>
		<div class="atf-card">
			<h2><?php echo esc_html( $title ); ?></h2>
			<p id="<?php echo esc_attr( $count_id ); ?>">
				<?php echo esc_html( sprintf( _n( $one, $many, $count, 'alt-text-fixer' ), $count ) ); ?>
			</p>
			<?php if ( $count > 0 ) : ?>
				<p>
					<button type="button" class="atf-start button button-primary" data-target="<?php echo esc_attr( $target ); ?>">
						<?php echo esc_html( $button ); ?>
					</button>
					<button type="button" class="atf-stop button" data-target="<?php echo esc_attr( $target ); ?>" style="display:none">
						<?php esc_html_e( 'Stop', 'alt-text-fixer' ); ?>
					</button>
					<span class="spinner atf-spinner" data-target="<?php echo esc_attr( $target ); ?>"></span>
				</p>
				<div class="atf-progress" data-target="<?php echo esc_attr( $target ); ?>" style="display:none">
					<div class="atf-progress-bar"><div class="atf-progress-fill"></div></div>
					<p class="atf-progress-text"></p>
				</div>
			<?php else : ?>
				<p><strong><?php echo esc_html( $done ); ?></strong></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function sanitize( $input ) {
		$output = array();
		$defaults = array(
			'auto_on_upload' => 'yes',
			'source'         => 'title',
			'append_site'    => 'no',
			'auto_schedule'  => 'no',
			'access_cap'     => 'manage_options',
			'exclusions'     => '',
			'dashboard_limit'=> '100',
		);
		$output['auto_on_upload'] = ! empty( $input['auto_on_upload'] ) ? 'yes' : 'no';
		$output['source']         = isset( $input['source'] ) && in_array( $input['source'], array( 'title', 'filename' ), true ) ? $input['source'] : $defaults['source'];
		$output['append_site']    = ! empty( $input['append_site'] ) ? 'yes' : 'no';
		$output['auto_schedule']  = ! empty( $input['auto_schedule'] ) ? 'yes' : 'no';
		$output['access_cap']     = isset( $input['access_cap'] ) ? sanitize_text_field( $input['access_cap'] ) : $defaults['access_cap'];
		$output['exclusions']     = isset( $input['exclusions'] ) ? sanitize_text_field( $input['exclusions'] ) : '';
		$output['dashboard_limit']= isset( $input['dashboard_limit'] ) ? absint( $input['dashboard_limit'] ) : 100;
		if( $output['dashboard_limit'] < 1 ) $output['dashboard_limit'] = 1;
		if( $output['dashboard_limit'] > 500 ) $output['dashboard_limit'] = 500;
		return $output;
	}

	public static function handle_model_install() {
		if(!current_user_can(self::capability())) wp_die('Permission denied');
		check_admin_referer('atf_install_model');
		$model_dir = WP_CONTENT_DIR . '/uploads/atf-ai/models/florence2';
		if(!is_dir($model_dir)) wp_mkdir_p($model_dir);
		$cmd = 'huggingface-cli download onnx-community/Florence-2-base --local-dir ' . escapeshellarg($model_dir) . ' 2>&1';
		$out = shell_exec($cmd);
		// Log output for debugging
		if(function_exists('error_log')) error_log('Florence-2 install output: ' . $out);
		wp_redirect(add_query_arg('atf_model_installed',1, admin_url('admin.php?page=alt-text-fixer-model')));
		exit;
	}

	public static function render_model_installer() {
		if (isset($_GET['atf_model_installed'])) {
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__('Model installer triggered. Check server logs for progress.','alt-text-fixer'));
		}
		$model_dir = WP_CONTENT_DIR . '/uploads/atf-ai/models/florence2';
		$exists = is_dir($model_dir) && count(glob($model_dir.'/*'))>0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Florence-2 Model Installer','alt-text-fixer'); ?></h1>
			<p><?php esc_html_e('Download and install the Florence-2 ONNX model for offline server-side captioning.','alt-text-fixer'); ?></p>
			<p><strong>Status:</strong> <?php echo $exists ? esc_html__('Model files detected','alt-text-fixer') : esc_html__('Model not found','alt-text-fixer'); ?></p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="atf_install_model">
				<?php wp_nonce_field('atf_install_model'); ?>
				<p><button type="submit" class="button button-primary"><?php esc_html_e('Install / Update Model','alt-text-fixer'); ?></button></p>
				<p class="description"><?php esc_html_e('This will run huggingface-cli download onnx-community/Florence-2-base to wp-content/uploads/atf-ai/models/florence2. Requires shell_exec and huggingface-cli available on server.','alt-text-fixer'); ?></p>
			</form>
		</div>
		<?php
	}

	public static function render_redirect_import() {
		if ( isset( $_GET['atf_redirect_imported'] ) ) {
			$count = intval( $_GET['atf_redirect_imported'] );
			$dry = !empty($_GET['atf_dry_run']);
			printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html(sprintf('Redirect CSV processed. %d replacements made. Dry run: %s', $count, $dry ? 'yes' : 'no')));
		}
		$logs = get_option('atf_redirect_log', []);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Redirect CSV Import','alt-text-fixer'); ?></h1>
			<p><?php esc_html_e('Upload a Screaming Frog export with columns Address/Source, Redirect/Destination and Status Code. Internal 301/302 will be replaced in posts, meta, widgets and theme mods. 404s will be reported.', 'alt-text-fixer'); ?></p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="atf_import_redirects">
				<?php wp_nonce_field('atf_redirect_import'); ?>
				<table class="form-table">
					<tr>
						<th><label for="atf_redirect_csv"><?php esc_html_e('CSV File','alt-text-fixer'); ?></label></th>
						<td><input type="file" name="atf_redirect_csv" id="atf_redirect_csv" accept=".csv" required></td>
					</tr>
					<tr>
						<th><?php esc_html_e('Dry run','alt-text-fixer'); ?></th>
						<td><label><input type="checkbox" name="atf_dry_run" value="1"> <?php esc_html_e('Preview only, do not write to DB','alt-text-fixer'); ?></label></td>
					</tr>
				</table>
				<?php submit_button(__('Import Redirects','alt-text-fixer')); ?>
			</form>

			<h2><?php esc_html_e('Import History','alt-text-fixer'); ?></h2>
			<?php if($logs): ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e('Time','alt-text-fixer'); ?></th><th><?php esc_html_e('File','alt-text-fixer'); ?></th><th><?php esc_html_e('Rows','alt-text-fixer'); ?></th><th><?php esc_html_e('Replacements','alt-text-fixer'); ?></th><th><?php esc_html_e('Dry run','alt-text-fixer'); ?></th></tr></thead>
				<tbody>
				<?php foreach($logs as $log): ?>
				<tr>
					<td><?php echo esc_html($log['time']); ?></td>
					<td><?php echo esc_html($log['file']); ?></td>
					<td><?php echo intval($log['rows']); ?></td>
					<td><?php echo intval($log['replacements']); ?></td>
					<td><?php echo !empty($log['dry_run']) ? esc_html__('Yes','alt-text-fixer') : esc_html__('No','alt-text-fixer'); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else: ?>
				<p><?php esc_html_e('No imports yet.','alt-text-fixer'); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function handle_review_revert() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		check_admin_referer( 'atf_review' );
		$id = isset( $_GET['log_id'] ) ? sanitize_text_field( wp_unslash( $_GET['log_id'] ) ) : '';
		if ( $id && class_exists( 'ATF_History' ) ) {
			ATF_History::revert( $id );
		}
		$redirect = add_query_arg( 'atf_reverted', 1, admin_url( 'admin.php?page=alt-text-fixer-review' ) );
		if ( ! empty( $_GET['filter_kind'] ) ) {
			$redirect = add_query_arg( 'filter_kind', sanitize_key( wp_unslash( $_GET['filter_kind'] ) ), $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_review_save() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		check_admin_referer( 'atf_review' );
		$att_id = isset( $_POST['attachment_id'] ) ? (int) $_POST['attachment_id'] : 0;
		$alt    = isset( $_POST['alt_text'] ) ? sanitize_text_field( wp_unslash( $_POST['alt_text'] ) ) : '';
		if ( $att_id > 0 ) {
			Alt_Text_Fixer::init()->set_alt_text_manual( $att_id, $alt );
		}
		wp_safe_redirect( add_query_arg( 'atf_saved', 1, admin_url( 'admin.php?page=alt-text-fixer-review' ) ) );
		exit;
	}

	public static function handle_review_clear() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		check_admin_referer( 'atf_review' );
		if ( class_exists( 'ATF_History' ) ) {
			ATF_History::clear_all();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=alt-text-fixer-review' ) );
		exit;
	}

	/**
	 * Manual power UI: check TYPE + revert alt text and related changes.
	 * Type = kind (library/content/meta/css/global) + mime/file for library.
	 */
	public static function render_review_page() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission denied.', 'alt-text-fixer' ) );
		}
		$filter = isset( $_GET['filter_kind'] ) ? sanitize_key( wp_unslash( $_GET['filter_kind'] ) ) : 'all';
		$allowed = array( 'all', 'library', 'content', 'meta', 'css', 'global' );
		if ( ! in_array( $filter, $allowed, true ) ) {
			$filter = 'all';
		}
		$logs = class_exists( 'ATF_History' ) ? ATF_History::get_all( $filter, 200 ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Review Changes — manual check & revert', 'alt-text-fixer' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Check the TYPE of every change (library/content/meta/css/global + file mime), manually edit alt text, or revert to the previous value.', 'alt-text-fixer' ); ?></p>
			<?php if ( isset( $_GET['atf_reverted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Change reverted.', 'alt-text-fixer' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['atf_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Alt text saved manually.', 'alt-text-fixer' ); ?></p></div>
			<?php endif; ?>
			<form method="get" style="margin:12px 0">
				<input type="hidden" name="page" value="alt-text-fixer-review">
				<label><?php esc_html_e( 'Type:', 'alt-text-fixer' ); ?>
					<select name="filter_kind">
						<?php foreach ( $allowed as $k ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, $filter ); ?>><?php echo esc_html( $k ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'alt-text-fixer' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-review' ) ); ?>"><?php esc_html_e( 'Reset', 'alt-text-fixer' ); ?></a>
			</form>
			<?php if ( empty( $logs ) ) : ?>
				<p><strong><?php esc_html_e( 'No changes logged yet. Run a fixer first.', 'alt-text-fixer' ); ?></strong></p>
			<?php else : ?>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Time', 'alt-text-fixer' ); ?></th>
					<th><?php esc_html_e( 'Type', 'alt-text-fixer' ); ?></th>
					<th><?php esc_html_e( 'Item', 'alt-text-fixer' ); ?></th>
					<th><?php esc_html_e( 'Before', 'alt-text-fixer' ); ?></th>
					<th><?php esc_html_e( 'After / Current (editable for library)', 'alt-text-fixer' ); ?></th>
					<th><?php esc_html_e( 'Action', 'alt-text-fixer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $logs as $e ) :
					$kind = isset( $e['kind'] ) ? $e['kind'] : '';
					$oid  = isset( $e['object_id'] ) ? $e['object_id'] : '';
					$extra = isset( $e['extra'] ) && is_array( $e['extra'] ) ? $e['extra'] : array();
					$type_label = $kind;
					if ( ! empty( $extra['mime'] ) ) {
						$type_label .= ' · ' . $extra['mime'];
					}
					if ( ! empty( $extra['meta_key'] ) ) {
						$type_label .= ' · ' . $extra['meta_key'];
					}
					$item_label = (string) $oid;
					$preview = '';
					if ( 'library' === $kind && is_numeric( $oid ) ) {
						$item_label = '#' . (int) $oid . ' ' . ( isset( $extra['file'] ) ? $extra['file'] : get_the_title( (int) $oid ) );
						$preview = wp_get_attachment_image( (int) $oid, array( 60, 60 ) );
					} elseif ( in_array( $kind, array( 'content', 'meta', 'css' ), true ) ) {
						$item_label = '#' . (int) $oid . ' ' . get_the_title( (int) $oid );
					}
					$before = isset( $e['before'] ) ? $e['before'] : '';
					$after  = isset( $e['after'] ) ? $e['after'] : '';
					// Long HTML blobs: trim for table.
					$before_short = mb_strlen( $before ) > 180 ? mb_substr( $before, 0, 180 ) . '…' : $before;
					$after_short  = mb_strlen( $after ) > 180 ? mb_substr( $after, 0, 180 ) . '…' : $after;
					$revert_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'      => 'atf_review_revert',
								'log_id'      => $e['id'],
								'filter_kind' => $filter,
							),
							admin_url( 'admin-post.php' )
						),
						'atf_review'
					);
				?>
					<tr>
						<td><?php echo esc_html( gmdate( 'Y-m-d H:i', isset( $e['time'] ) ? (int) $e['time'] : time() ) ); ?><?php echo ! empty( $e['reverted'] ) ? '<br><span class="description">' . esc_html__( 'reverted', 'alt-text-fixer' ) . '</span>' : ''; ?></td>
						<td><code><?php echo esc_html( $type_label ); ?></code></td>
						<td><?php echo wp_kses_post( $preview ); ?><br><?php echo esc_html( $item_label ); ?></td>
						<td><span class="description"><?php echo esc_html( $before_short ); ?></span></td>
						<td>
							<?php if ( 'library' === $kind && is_numeric( $oid ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="atf_review_save">
									<?php wp_nonce_field( 'atf_review' ); ?>
									<input type="hidden" name="attachment_id" value="<?php echo (int) $oid; ?>">
									<input type="text" name="alt_text" value="<?php echo esc_attr( get_post_meta( (int) $oid, '_wp_attachment_image_alt', true ) ); ?>" class="regular-text">
									<button class="button button-small" type="submit"><?php esc_html_e( 'Save', 'alt-text-fixer' ); ?></button>
								</form>
							<?php else : ?>
								<?php echo esc_html( $after_short ); ?>
							<?php endif; ?>
						</td>
						<td><a class="button button-small" href="<?php echo esc_url( $revert_url ); ?>"><?php esc_html_e( 'Revert', 'alt-text-fixer' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="atf_review_clear">
				<?php wp_nonce_field( 'atf_review' ); ?>
				<button class="button button-link-delete" type="submit" onclick="return confirm('Clear all history?')"><?php esc_html_e( 'Clear history', 'alt-text-fixer' ); ?></button>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function import_notice() {
		return;
	}
}

ATF_Admin::init();
<?php
/**
 * Admin settings page under "Settings > Alt Text Fixer".
 */
class ATF_Admin {

	const CLIENT_ROLE = 'atf_client_developer';
	const CAP         = 'atf_access';

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'import_notice' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'dashboard_widget' ) );
		add_action( 'wp_ajax_atf_run_all', array( __CLASS__, 'ajax_run_all' ) );
	}

	/**
	 * Register the at-a-glance dashboard widget.
	 */
	public static function dashboard_widget() {
		wp_add_dashboard_widget(
			'atf_status_widget',
			esc_html__( 'Alt Text Fixer status', 'alt-text-fixer' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Render the dashboard widget with remaining counts.
	 */
	public static function render_dashboard_widget() {
		$summary = ATF_Cron::remaining_summary();
		$total   = array_sum( $summary );
		echo '<p>';
		if ( 0 === $total ) {
			echo '<span class="atf-ok">' . esc_html__( 'All caught up — no missing alt text or SEO meta.', 'alt-text-fixer' ) . '</span>';
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
				'seo'     => esc_html__( 'SEO meta', 'alt-text-fixer' ),
				'schema'  => esc_html__( 'Schema', 'alt-text-fixer' ),
			);
			foreach ( $summary as $key => $count ) {
				printf(
					'<li>%s: <strong>%d</strong></li>',
					esc_html( $labels[ $key ] ),
					(int) $count
				);
			}
			echo '</ul>';
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=alt-text-fixer' ) ),
				esc_html__( 'Open Alt Text Fixer', 'alt-text-fixer' )
			);
		}
	}

	/**
	 * Capability required to access the plugin dashboard.
	 *
	 * Defaults to 'manage_options' (Administrators). Can be lowered via the
	 * "Access capability" setting so a custom "Client Developer" role can be
	 * granted access without full Admin rights.
	 *
	 * @return string
	 */
	public static function capability() {
		$opts = get_option( 'atf_settings', array() );
		$cap  = isset( $opts['access_cap'] ) ? $opts['access_cap'] : 'manage_options';
		return self::is_valid_cap( $cap ) ? $cap : 'manage_options';
	}

	/**
	 * Whether a capability string is known to WordPress.
	 *
	 * @param string $cap Capability.
	 * @return bool
	 */
	public static function is_valid_cap( $cap ) {
		$valid = array(
			'manage_options', 'manage_categories', 'edit_others_posts',
			'edit_posts', 'publish_posts', 'upload_files', 'edit_pages',
			'manage_woocommerce', 'install_plugins', 'activate_plugins',
			self::CAP,
		);
		return in_array( $cap, $valid, true );
	}

	/**
	 * Create the Client Developer role with the dedicated capability.
	 *
	 * Called on plugin activation and when the access setting is saved.
	 * The role always gets the plugin's custom capability (atf_access);
	 * the admin chooses the minimum requirement in the settings dropdown.
	 */
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

	/**
	 * Remove the Client Developer role (on deactivation).
	 */
	public static function remove_role() {
		remove_role( self::CLIENT_ROLE );
	}

	/**
	 * Register the top-level plugin menu with a dedicated dashboard.
	 */
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
	}

	/**
	 * Register the plugin settings.
	 */
	public static function register_settings() {
		register_setting( 'atf_settings_group', 'atf_settings', array( __CLASS__, 'sanitize' ) );

		add_settings_section(
			'atf_main_actions',
			esc_html__( 'Bulk Actions', 'alt-text-fixer' ),
			'__return_false',
			'alt-text-fixer'
		);

		add_settings_field(
			'dry_run',
			esc_html__( 'Dry Run Mode', 'alt-text-fixer' ),
			array( __CLASS__, 'field_dry_run' ),
			'atf_main_actions',
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

		// SEO settings.
		register_setting( 'atf_settings_group', 'atf_seo_settings', array( __CLASS__, 'sanitize_seo' ) );

		add_settings_section(
			'atf_seo',
			esc_html__( 'SEO Automation', 'alt-text-fixer' ),
			'__return_false',
			'alt-text-fixer'
		);

		add_settings_field( 'seo_enabled', esc_html__( 'Enable SEO automation', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_enabled' ), 'alt-text-fixer', 'atf_seo' );
		add_settings_field( 'seo_auto_on_save', esc_html__( 'Auto-generate on save', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_auto_on_save' ), 'alt-text-fixer', 'atf_seo' );
		add_settings_field( 'seo_title_tpl', esc_html__( 'Title template', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_title_tpl' ), 'alt-text-fixer', 'atf_seo' );
		add_settings_field( 'seo_desc_tpl', esc_html__( 'Description template', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_desc_tpl' ), 'alt-text-fixer', 'atf_seo' );
		add_settings_field( 'seo_sep', esc_html__( 'Separator', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_sep' ), 'alt-text-fixer', 'atf_seo' );
		add_settings_field( 'seo_desc_length', esc_html__( 'Description length', 'alt-text-fixer' ), array( __CLASS__, 'field_seo_desc_length' ), 'alt-text-fixer', 'atf_seo' );

		// Schema / structured data settings.
		register_setting( 'atf_settings_group', 'atf_schema_org', array( __CLASS__, 'sanitize_schema' ) );

		add_settings_section(
			'atf_schema',
			esc_html__( 'Schema / Structured data', 'alt-text-fixer' ),
			'__return_false',
			'alt-text-fixer'
		);

		add_settings_field( 'schema_enabled', esc_html__( 'Enable site schema', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_enabled' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_type', esc_html__( 'Business / organization type', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_type' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_name', esc_html__( 'Name', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_name' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_logo', esc_html__( 'Logo URL', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_logo' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_phone', esc_html__( 'Phone', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_phone' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_email', esc_html__( 'Email', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_email' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_address', esc_html__( 'Street address', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_address' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_city', esc_html__( 'City', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_city' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_region', esc_html__( 'State / Region', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_region' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_postal', esc_html__( 'Postal code', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_postal' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_country', esc_html__( 'Country (ISO)', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_country' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_geo', esc_html__( 'Geo coordinates', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_geo' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_hours', esc_html__( 'Opening hours', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_hours' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_sameas', esc_html__( 'SameAs profiles', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_sameas' ), 'alt-text-fixer', 'atf_schema' );
		add_settings_field( 'schema_medical', esc_html__( 'Medical specialties', 'alt-text-fixer' ), array( __CLASS__, 'field_schema_medical' ), 'alt-text-fixer', 'atf_schema' );
	}

	/**
	 * Sanitize schema org settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize_schema( $input ) {
		$d = ATF_Schema::org_defaults();
		$medical = array();
		if ( ! empty( $input['medical'] ) && is_array( $input['medical'] ) ) {
			$medical = array_map( 'sanitize_text_field', $input['medical'] );
		}
		return array(
			'type'        => sanitize_text_field( $input['type'] ?? $d['type'] ),
			'name'        => sanitize_text_field( $input['name'] ?? $d['name'] ),
			'enabled'     => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'description' => sanitize_text_field( $input['description'] ?? '' ),
			'url'         => esc_url_raw( $input['url'] ?? $d['url'] ),
			'logo'        => esc_url_raw( $input['logo'] ?? '' ),
			'image'       => esc_url_raw( $input['image'] ?? '' ),
			'phone'       => sanitize_text_field( $input['phone'] ?? '' ),
			'email'       => sanitize_email( $input['email'] ?? '' ),
			'address'     => sanitize_text_field( $input['address'] ?? '' ),
			'city'        => sanitize_text_field( $input['city'] ?? '' ),
			'region'      => sanitize_text_field( $input['region'] ?? '' ),
			'postal'      => sanitize_text_field( $input['postal'] ?? '' ),
			'country'     => sanitize_text_field( $input['country'] ?? '' ),
			'priceRange'  => sanitize_text_field( $input['priceRange'] ?? '' ),
			'geo'         => sanitize_text_field( $input['geo'] ?? '' ),
			'hours'       => sanitize_textarea_field( $input['hours'] ?? '' ),
			'sameAs'      => sanitize_textarea_field( $input['sameAs'] ?? '' ),
			'medical'     => $medical,
		);
	}

	public static function field_schema_enabled() {
		$s = ATF_Schema::get_org_settings();
		printf( '<label><input type="checkbox" name="atf_schema_org[enabled]" value="yes" %s> %s</label>', checked( 'yes', $s['enabled'], false ), esc_html__( 'Output Organization / LocalBusiness / MedicalBusiness JSON-LD site-wide.', 'alt-text-fixer' ) );
	}

	public static function field_schema_type() {
		$s = ATF_Schema::get_org_settings();
		$types = array( 'Organization', 'LocalBusiness', 'MedicalBusiness', 'ProfessionalService', 'Store', 'Restaurant' );
		echo '<select name="atf_schema_org[type]">';
		foreach ( $types as $t ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $t ), selected( $t, $s['type'], false ), esc_html( $t ) );
		}
		echo '</select> <span class="description">' . esc_html__( 'Choose MedicalBusiness for clinics/hospitals; LocalBusiness for local shops.', 'alt-text-fixer' ) . '</span>';
	}

	public static function field_schema_name() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" class="regular-text" name="atf_schema_org[name]" value="%s">', esc_attr( $s['name'] ) );
	}

	public static function field_schema_logo() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="url" class="regular-text" name="atf_schema_org[logo]" value="%s">', esc_attr( $s['logo'] ) );
	}

	public static function field_schema_phone() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" class="regular-text" name="atf_schema_org[phone]" value="%s">', esc_attr( $s['phone'] ) );
	}

	public static function field_schema_email() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="email" class="regular-text" name="atf_schema_org[email]" value="%s">', esc_attr( $s['email'] ) );
	}

	public static function field_schema_address() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" class="regular-text" name="atf_schema_org[address]" value="%s">', esc_attr( $s['address'] ) );
	}

	public static function field_schema_city() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" name="atf_schema_org[city]" value="%s">', esc_attr( $s['city'] ) );
	}

	public static function field_schema_region() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" name="atf_schema_org[region]" value="%s">', esc_attr( $s['region'] ) );
	}

	public static function field_schema_postal() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" name="atf_schema_org[postal]" value="%s">', esc_attr( $s['postal'] ) );
	}

	public static function field_schema_country() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" name="atf_schema_org[country]" value="%s" placeholder="US">', esc_attr( $s['country'] ) );
	}

	public static function field_schema_geo() {
		$s = ATF_Schema::get_org_settings();
		printf( '<input type="text" class="regular-text" name="atf_schema_org[geo]" value="%s"><br><span class="description">%s</span>', esc_attr( $s['geo'] ), esc_html__( 'Latitude,longitude (e.g. 27.7172,85.3240).', 'alt-text-fixer' ) );
	}

	public static function field_schema_hours() {
		$s = ATF_Schema::get_org_settings();
		printf( '<textarea class="large-text" name="atf_schema_org[hours]" rows="2">%s</textarea><br><span class="description">%s</span>', esc_textarea( $s['hours'] ), esc_html__( 'One per line, e.g. "Mon-Fri 09:00-17:00".', 'alt-text-fixer' ) );
	}

	public static function field_schema_sameas() {
		$s = ATF_Schema::get_org_settings();
		printf( '<textarea class="large-text" name="atf_schema_org[sameAs]" rows="2">%s</textarea><br><span class="description">%s</span>', esc_textarea( $s['sameAs'] ), esc_html__( 'Social/profile URLs, one per line.', 'alt-text-fixer' ) );
	}

	public static function field_schema_medical() {
		$s = ATF_Schema::get_org_settings();
		$current = ! empty( $s['medical'] ) && is_array( $s['medical'] ) ? $s['medical'] : array();
		$options = array( 'Cardiology', 'Dentistry', 'Dermatology', 'Gynecology', 'Pediatrics', 'Psychiatry', 'Surgery', 'Ophthalmology', 'Orthopedic', 'Oncology' );
		echo '<select name="atf_schema_org[medical][]" multiple size="6">';
		foreach ( $options as $o ) {
			$sel = in_array( $o, $current, true ) ? 'selected' : '';
			printf( '<option value="%s" %s>%s</option>', esc_attr( $o ), $sel, esc_html( $o ) );
		}
		echo '</select> <span class="description">' . esc_html__( 'Only used when type is MedicalBusiness.', 'alt-text-fixer' ) . '</span>';
	}

	/**
	 * Sanitize SEO settings.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize_seo( $input ) {
		$d = ATF_SEO::defaults();
		return array(
			'enabled'       => ! empty( $input['enabled'] ) ? 'yes' : 'no',
			'auto_on_save'  => ! empty( $input['auto_on_save'] ) ? 'yes' : 'no',
			'title_tpl'     => sanitize_text_field( $input['title_tpl'] ?? $d['title_tpl'] ),
			'desc_tpl'      => sanitize_text_field( $input['desc_tpl'] ?? $d['desc_tpl'] ),
			'sep'           => sanitize_text_field( $input['sep'] ?? $d['sep'] ),
			'desc_length'   => max( 0, (int) ( $input['desc_length'] ?? $d['desc_length'] ) ),
			'fallback_desc' => sanitize_text_field( $input['fallback_desc'] ?? '' ),
		);
	}

	public static function field_seo_enabled() {
		$s = ATF_SEO::get_settings();
		printf( '<label><input type="checkbox" name="atf_seo_settings[enabled]" value="yes" %s> %s</label>', checked( 'yes', $s['enabled'], false ), esc_html__( 'Output generated meta title & description in the page head.', 'alt-text-fixer' ) );
	}

	public static function field_seo_auto_on_save() {
		$s = ATF_SEO::get_settings();
		printf( '<label><input type="checkbox" name="atf_seo_settings[auto_on_save]" value="yes" %s> %s</label>', checked( 'yes', $s['auto_on_save'], false ), esc_html__( 'Generate SEO meta automatically when a post is saved.', 'alt-text-fixer' ) );
	}

	public static function field_seo_title_tpl() {
		$s = ATF_SEO::get_settings();
		printf( '<input type="text" class="regular-text" name="atf_seo_settings[title_tpl]" value="%s"><br><span class="description">%s</span>', esc_attr( $s['title_tpl'] ), esc_html__( 'Tokens: %title% %sitename% %sep% %category%', 'alt-text-fixer' ) );
	}

	public static function field_seo_desc_tpl() {
		$s = ATF_SEO::get_settings();
		printf( '<input type="text" class="regular-text" name="atf_seo_settings[desc_tpl]" value="%s"><br><span class="description">%s</span>', esc_attr( $s['desc_tpl'] ), esc_html__( 'Tokens: %excerpt% %title% %sitename% %sep% %category%', 'alt-text-fixer' ) );
	}

	public static function field_seo_sep() {
		$s = ATF_SEO::get_settings();
		printf( '<input type="text" name="atf_seo_settings[sep]" value="%s" class="small-text">', esc_attr( $s['sep'] ) );
	}

	public static function field_seo_desc_length() {
		$s = ATF_SEO::get_settings();
		printf( '<input type="number" name="atf_seo_settings[desc_length]" value="%d" min="0" max="320" class="small-text"> <span class="description">%s</span>', (int) $s['desc_length'], esc_html__( 'Max characters (0 = no limit).', 'alt-text-fixer' ) );
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$auto_schedule = ! empty( $input['auto_schedule'] ) ? 'yes' : 'no';
		// Keep the cron schedule in sync with the setting.
		ATF_Cron::set_schedule( 'yes' === $auto_schedule );
		$access_cap = isset( $input['access_cap'] ) ? sanitize_key( $input['access_cap'] ) : 'manage_options';
		if ( ! self::is_valid_cap( $access_cap ) ) {
			$access_cap = 'manage_options';
		}
		// Keep the custom role in sync with the chosen capability.
		self::sync_role();
		$exclusions = isset( $input['exclusions'] ) ? sanitize_text_field( $input['exclusions'] ) : '';
		return array(
			'auto_on_upload' => ! empty( $input['auto_on_upload'] ) ? 'yes' : 'no',
			'source'         => in_array( $input['source'], array( 'title', 'filename' ), true ) ? $input['source'] : 'title',
			'append_site'    => ! empty( $input['append_site'] ) ? 'yes' : 'no',
			'auto_schedule'  => $auto_schedule,
			'access_cap'     => $access_cap,
			'exclusions'     => $exclusions,
		);
	}

	/**
	 * Field: auto on upload.
	 */
	public static function field_auto_on_upload() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['auto_on_upload'] ) ? $opts['auto_on_upload'] : 'yes';
		printf(
			'<label><input type="checkbox" name="atf_settings[auto_on_upload]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Automatically add alt text when a new image is uploaded.', 'alt-text-fixer' )
		);
	}

	/**
	 * Field: source.
	 */
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

	/**
	 * Field: append site name.
	 */
	public static function field_append_site() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['append_site'] ) ? $opts['append_site'] : 'no';
		printf(
			'<label><input type="checkbox" name="atf_settings[append_site]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Append the site name to the alt text (e.g. "Mountain - My Site").', 'alt-text-fixer' )
		);
	}

	/**
	 * Field: daily auto-fix schedule.
	 */
	public static function field_auto_schedule() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['auto_schedule'] ) ? $opts['auto_schedule'] : 'no';
		printf(
			'<label><input type="checkbox" name="atf_settings[auto_schedule]" value="yes" %s> %s</label>',
			checked( 'yes', $val, false ),
			esc_html__( 'Automatically fix new missing alt text and generate SEO meta every day (WP-Cron).', 'alt-text-fixer' )
		);
	}

	/**
	 * Field: exclusions.
	 */
	public static function field_exclusions() {
		$opts = get_option( 'atf_settings', array() );
		$val  = isset( $opts['exclusions'] ) ? $opts['exclusions'] : '';
		printf(
			'<textarea class="large-text" name="atf_settings[exclusions]" rows="2">%s</textarea><br><span class="description">%s</span>',
			esc_textarea( $val ),
			esc_html__( 'Comma-separated IDs of posts or attachments to skip, e.g. 12, 45, 102.', 'alt-text-fixer' )
		);
	}

	/**
	 * Field: access capability.
	 */
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
			'manage_woocommerce'=> esc_html__( 'Shop Manager (manage_woocommerce)', 'alt-text-fixer' ),
			self::CAP           => esc_html__( 'Client Developer (custom role)', 'alt-text-fixer' ),
		);
		echo '<select name="atf_settings[access_cap]">';
		foreach ( $caps as $c => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $c ), selected( $c, $val, false ), esc_html( $label ) );
		}
		echo '</select><br><span class="description">' . esc_html__( 'Minimum capability needed to see the Himalayan Auto-Fixer menu. Choose "Client Developer" to use the auto-created role. Lower the cap for an existing role (e.g. Editor) to let your client\'s developer use the dashboard without full Admin access. The role will be created on plugin activation.', 'alt-text-fixer' ) . '</span>';
	}

	/**
	 * Render the dedicated plugin dashboard (top-level landing page).
	 */
	public static function render_dashboard() {
		$summary = ATF_Cron::remaining_summary();
		$audit   = ATF_Tech_Audit::run();
		$pass    = count( array_filter( $audit, function ( $c ) { return 'pass' === $c['status']; } ) );
		$warn    = count( array_filter( $audit, function ( $c ) { return 'warn' === $c['status']; } ) );
		$fail    = count( array_filter( $audit, function ( $c ) { return 'fail' === $c['status']; } ) );

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
			<p class="description"><?php esc_html_e( 'All-in-one accessibility & technical SEO automation. This dashboard is for the site developer/administrator.', 'alt-text-fixer' ); ?></p>

			<div class="atf-dash-grid">
				<div class="atf-dash-card">
					<h2><?php esc_html_e( 'Alt text', 'alt-text-fixer' ); ?></h2>
					<div class="atf-dash-num"><?php echo (int) array_sum( array( $summary['library'], $summary['content'], $summary['meta'], $summary['css'], $summary['global'] ) ); ?></div>
					<p><?php esc_html_e( 'items still need fixing', 'alt-text-fixer' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Open fixers', 'alt-text-fixer' ); ?></a>
				</div>
				<div class="atf-dash-card">
					<h2><?php esc_html_e( 'SEO meta', 'alt-text-fixer' ); ?></h2>
					<div class="atf-dash-num"><?php echo (int) $summary['seo']; ?></div>
					<p><?php esc_html_e( 'posts missing meta', 'alt-text-fixer' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Generate', 'alt-text-fixer' ); ?></a>
				</div>
				<div class="atf-dash-card">
					<h2><?php esc_html_e( 'Schema', 'alt-text-fixer' ); ?></h2>
					<div class="atf-dash-num"><?php echo (int) $summary['schema']; ?></div>
					<p><?php esc_html_e( 'posts eligible for schema', 'alt-text-fixer' ); ?></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Configure', 'alt-text-fixer' ); ?></a>
				</div>
				<div class="atf-dash-card">
					<h2><?php esc_html_e( 'Technical audit', 'alt-text-fixer' ); ?></h2>
					<div class="atf-dash-num atf-ok"><?php echo (int) $pass; ?></div>
					<p>
						<span class="atf-ok"><?php echo (int) $pass; ?> OK</span> /
						<span class="atf-warn"><?php echo (int) $warn; ?> warn</span> /
						<span class="atf-missing"><?php echo (int) $fail; ?> fail</span>
					</p>
					<button type="button" id="atf-tech-audit" class="button button-secondary"><?php esc_html_e( 'Run audit', 'alt-text-fixer' ); ?></button>
					<span class="spinner atf-spinner" data-target="tech"></span>
				</div>
			</div>

			<h2><?php esc_html_e( 'Quick actions', 'alt-text-fixer' ); ?></h2>
			<p>
				<button type="button" id="atf-run-all" class="button button-primary"><?php esc_html_e( 'Run all fixes', 'alt-text-fixer' ); ?></button>
				<span class="spinner atf-spinner" data-target="runall"></span>
				<span id="atf-run-all-status" class="description"></span>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=alt-text-fixer-settings' ) ); ?>"><?php esc_html_e( 'Settings & fixers', 'alt-text-fixer' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><?php esc_html_e( 'Media library', 'alt-text-fixer' ); ?></a>
			</p>

			<div id="atf-tech-audit-result"></div>

			<h2><?php esc_html_e( 'Audit detail', 'alt-text-fixer' ); ?></h2>
			<button type="button" id="atf-export-audit" class="button button-secondary"><?php esc_html_e( 'Export Results', 'alt-text-fixer' ); ?></button>
			<table class="widefat" style="max-width:880px">
				<thead><tr><th><?php esc_html_e( 'Check', 'alt-text-fixer' ); ?></th><th><?php esc_html_e( 'Status', 'alt-text-fixer' ); ?></th><th><?php esc_html_e( 'Detail', 'alt-text-fixer' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $audit as $c ) : ?>
					<tr>
						<td><?php echo esc_html( $c['label'] ); ?></td>
						<td><?php echo 'pass' === $c['status'] ? '<span class="atf-ok">OK</span>' : ( 'warn' === $c['status'] ? '<span class="atf-warn">WARN</span>' : '<span class="atf-missing">FAIL</span>' ); ?></td>
						<td><?php echo esc_html( $c['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * AJAX: run every fixer once (alt, content, meta, css, global, seo, schema).
	 */
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

		// Posts: content / meta / css / schema.
		$posts = ATF_Content_Fixer::get_posts( 0, -1 );
		$content = $meta = $css = $schema = 0;
		foreach ( $posts as $pid ) {
			$content += ATF_Content_Fixer::fix_post_scope( $pid, 'content', $fixer );
			$meta    += ATF_Content_Fixer::fix_post_scope( $pid, 'meta', $fixer );
			$css     += ATF_Content_Fixer::fix_post_scope( $pid, 'css', $fixer );
			if ( ATF_Schema::post_needs_schema( $pid ) ) {
				ATF_Schema::mark_batch( $pid, 1 );
				$schema ++;
			}
		}

		// Widgets / customizer options.
		$opts = ATF_Content_Fixer::global_option_names();
		ATF_Content_Fixer::fix_global( 'global', $fixer, $opts );

		// SEO meta.
		$seo = 0;
		foreach ( ATF_SEO::get_posts_missing( 0, 500 ) as $id ) {
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
			$seo ++;
		}

		ATF_SEO::ping_search_engines();

		wp_send_json_success(
			array(
				'lib'     => $lib,
				'content' => $content,
				'meta'    => $meta,
				'css'     => $css,
				'schema'  => $schema,
				'seo'     => $seo,
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		$lib_count     = ATF_Bulk_Fixer::count_missing_alt();
		$content_count = ATF_Content_Fixer::count_scope( 'content' );
		$meta_count    = ATF_Content_Fixer::count_scope( 'meta' );
		$css_count     = ATF_Content_Fixer::count_scope( 'css' );
		$global_count  = ATF_Content_Fixer::count_scope( 'global' );
		$seo_count     = ATF_SEO::count_missing();
		$schema_count  = ATF_Schema::count_posts_with_schema();

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
				'seoBatch' => 50,
				'schemaBatch' => 50,
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Himalayan Auto-Fixer', 'alt-text-fixer' ); ?></h1>

			<?php
			self::render_card( 'lib', esc_html__( 'Media Library images', 'alt-text-fixer' ), $lib_count, 'atf-lib-count', esc_html__( 'There is %d media-library image missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d media-library images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix media library alt text', 'alt-text-fixer' ), esc_html__( 'All media-library images have alt text.', 'alt-text-fixer' ) );

			self::render_card( 'content', esc_html__( 'Images embedded in content', 'alt-text-fixer' ), $content_count, 'atf-content-count', esc_html__( 'There is %d post/page with embedded images missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages with embedded images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix embedded image alt text', 'alt-text-fixer' ), esc_html__( 'All embedded images already have alt text.', 'alt-text-fixer' ) );

			self::render_card( 'meta', esc_html__( 'Images in post meta (page builders / ACF)', 'alt-text-fixer' ), $meta_count, 'atf-meta-count', esc_html__( 'There is %d post/page with image URLs in meta missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages with image URLs in meta missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix meta image alt text', 'alt-text-fixer' ), esc_html__( 'All meta images already have alt text.', 'alt-text-fixer' ) );

		self::render_card( 'css', esc_html__( 'CSS background images', 'alt-text-fixer' ), $css_count, 'atf-css-count', esc_html__( 'There is %d post/page using CSS background images that need marking decorative.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages using CSS background images that need marking decorative.', 'alt-text-fixer' ), esc_html__( 'Mark background images decorative', 'alt-text-fixer' ), esc_html__( 'All CSS background images are already handled.', 'alt-text-fixer' ) );

		self::render_card( 'global', esc_html__( 'Widgets, customizer & nav (options)', 'alt-text-fixer' ), $global_count, 'atf-global-count', esc_html__( 'There is %d widget/customizer option with images missing alt text.', 'alt-text-fixer' ), esc_html__( 'There are %d widget/customizer options with images missing alt text.', 'alt-text-fixer' ), esc_html__( 'Fix option images alt text', 'alt-text-fixer' ), esc_html__( 'All option images already have alt text.', 'alt-text-fixer' ) );
		?>

		<h2><?php esc_html_e( 'SEO Automation', 'alt-text-fixer' ); ?></h2>
		<?php
		self::render_card( 'seo', esc_html__( 'Meta title & description', 'alt-text-fixer' ), $seo_count, 'atf-seo-count', esc_html__( 'There is %d post/page missing SEO meta title or description.', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages missing SEO meta title or description.', 'alt-text-fixer' ), esc_html__( 'Generate SEO meta', 'alt-text-fixer' ), esc_html__( 'All posts/pages have SEO meta.', 'alt-text-fixer' ) );
		?>

		<h2><?php esc_html_e( 'Schema / Structured data', 'alt-text-fixer' ); ?></h2>
		<?php
		self::render_card( 'schema', esc_html__( 'Posts eligible for schema', 'alt-text-fixer' ), $schema_count, 'atf-schema-count', esc_html__( 'There is %d post/page that can emit schema (article/product/FAQ).', 'alt-text-fixer' ), esc_html__( 'There are %d posts/pages that can emit schema (article/product/FAQ).', 'alt-text-fixer' ), esc_html__( 'Mark schema-eligible posts', 'alt-text-fixer' ), esc_html__( 'All schema-eligible posts are handled.', 'alt-text-fixer' ) );
		?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'atf_settings_group' );
				do_settings_sections( 'alt-text-fixer' );
				submit_button();
				?>
			</form>

			<?php self::render_import_section(); ?>
			<?php self::render_audit_section(); ?>
		</div>
		<?php
	}

	/**
	 * Show a notice after a CSV import.
	 */
	public static function import_notice() {
		if ( empty( $_GET['atf_import'] ) || empty( $_GET['page'] ) || ! in_array( $_GET['page'], array( 'alt-text-fixer', 'alt-text-fixer-settings' ), true ) ) {
			return;
		}
		$status = sanitize_key( $_GET['atf_import'] );
		if ( 'done' === $status ) {
			$applied = isset( $_GET['applied'] ) ? (int) $_GET['applied'] : 0;
			$total   = isset( $_GET['total'] ) ? (int) $_GET['total'] : 0;
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf( esc_html__( 'Import complete: applied %1$d of %2$d rows.', 'alt-text-fixer' ), $applied, $total )
			);
		} elseif ( 'noupload' === $status ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'No CSV file was uploaded.', 'alt-text-fixer' ) );
		} elseif ( 'parse' === $status ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html__( 'Could not parse the CSV file.', 'alt-text-fixer' ) );
		}
	}

	/**
	 * Render the CSV/spreadsheet import section.
	 */
	public static function render_import_section() {
		?>
		<h2><?php esc_html_e( 'Apply client spreadsheet (CSV import)', 'alt-text-fixer' ); ?></h2>
		<div class="atf-card">
			<p><?php esc_html_e( 'Upload a CSV from your client with custom overrides. Two modes:', 'alt-text-fixer' ); ?></p>
			<ul style="margin:0 0 12px 18px;list-style:disc">
				<li><strong><?php esc_html_e( 'Alt text', 'alt-text-fixer' ); ?></strong> &mdash; columns: <code>image</code>, <code>alt</code></li>
				<li><strong><?php esc_html_e( 'SEO', 'alt-text-fixer' ); ?></strong> &mdash; columns: <code>post</code> (URL, ID or slug), <code>title</code>, <code>description</code>, <code>image</code> (optional)</li>
			</ul>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=atf_template&mode=alt' ), 'atf_template_nonce' ) ); ?>"><?php esc_html_e( 'Download alt-text template', 'alt-text-fixer' ); ?></a>
				<a class="button" style="margin-left:8px" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=atf_template&mode=seo' ), 'atf_template_nonce' ) ); ?>"><?php esc_html_e( 'Download SEO template', 'alt-text-fixer' ); ?></a>
				<span class="description"><?php esc_html_e( 'Give these to the client dev so the spreadsheet matches the expected columns.', 'alt-text-fixer' ); ?></span>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<?php wp_nonce_field( 'atf_import_nonce' ); ?>
				<input type="hidden" name="action" value="atf_import">
				<p>
					<label><input type="radio" name="atf_mode" value="alt" checked> <?php esc_html_e( 'Alt text', 'alt-text-fixer' ); ?></label>
					<label style="margin-left:12px"><input type="radio" name="atf_mode" value="seo"> <?php esc_html_e( 'SEO', 'alt-text-fixer' ); ?></label>
				</p>
			<p>
				<label for="atf_base"><strong><?php esc_html_e( 'Site base URL', 'alt-text-fixer' ); ?></strong></label><br>
				<input type="url" id="atf_base" name="atf_base" class="regular-text" placeholder="<?php echo esc_url( home_url( '/' ) ); ?>" value="<?php echo esc_url( home_url( '/' ) ); ?>">
				<span class="description"><?php esc_html_e( 'Used to resolve bare filenames or relative paths in the spreadsheet (e.g. /wp-content/uploads/foo.jpg). Defaults to this site.', 'alt-text-fixer' ); ?></span>
			</p>
			<p><input type="file" name="atf_csv" accept=".csv,text/csv" required></p>
			<?php submit_button( esc_html__( 'Import CSV', 'alt-text-fixer' ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the technical SEO audit section (theme files + site-wide checks).
	 */
	public static function render_audit_section() {
		?>
		<h2><?php esc_html_e( 'Technical SEO audit', 'alt-text-fixer' ); ?></h2>
		<div class="atf-card">
			<p><?php esc_html_e( 'Runs site-wide technical SEO checks (HTTPS, robots.txt, sitemap, canonicals, meta, social cards, H1, structured data, alt coverage, indexing). Items the plugin can safely auto-fix show an "Apply" button.', 'alt-text-fixer' ); ?></p>
			<p>
				<button type="button" id="atf-tech-audit" class="button button-secondary"><?php esc_html_e( 'Run technical audit', 'alt-text-fixer' ); ?></button>
				<span class="spinner atf-spinner" data-target="tech"></span>
			</p>
			<div id="atf-tech-audit-result"></div>
		</div>

		<h2><?php esc_html_e( 'Theme file audit (read-only)', 'alt-text-fixer' ); ?></h2>
		<div class="atf-card">
			<p><?php esc_html_e( 'Finds hard-coded <img> and CSS background-images inside the active theme that the plugin cannot auto-fix (they live in PHP/CSS, not the database). Use this report to make manual theme edits.', 'alt-text-fixer' ); ?></p>
			<p>
				<button type="button" id="atf-audit" class="button button-secondary"><?php esc_html_e( 'Run theme audit', 'alt-text-fixer' ); ?></button>
				<span class="spinner atf-spinner" data-target="audit"></span>
			</p>
			<div id="atf-audit-result"></div>
		</div>
		<?php
	}

	/**
	 * Render a single fixer card (used for lib/content/meta/css scopes).
	 *
	 * @param string $target   Data attribute target key.
	 * @param string $title    Card heading.
	 * @param int    $count    Number of items needing work.
	 * @param string $count_id Element ID for the live count.
	 * @param string $one      Singular message (with %d).
	 * @param string $many     Plural message (with %d).
	 * @param string $button   Button label.
	 * @param string $done     "All done" message.
	 */
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
}

ATF_Admin::init();

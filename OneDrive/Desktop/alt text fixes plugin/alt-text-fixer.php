<?php
/**
 * Plugin Name: Himalayan Auto-Fixer — Alt Text & SEO Automation
 * Plugin URI:  https://github.com/beebus-builds/himalayan-plugin
 * Description: Multi-purpose automation for WordPress, including persistent image alt-text fixes, rendered front-end fixes, and free browser-local Florence-2 AI Vision fallback.
 * Version:     2.2.0
 * Author:      Himalayan Auto-Fixer
 * Author URI:  https://github.com/beebus-builds/himalayan-plugin
 * License:     GPL-2.0-or-later
 * Text Domain: alt-text-fixer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ATF_LOADED' ) ) {
	define( 'ATF_LOADED', true );

	if ( ! defined( 'ATF_VERSION' ) ) {
		define( 'ATF_VERSION', '2.2.0' );
		define( 'ATF_FILE', __FILE__ );
		define( 'ATF_PATH', plugin_dir_path( __FILE__ ) );
		define( 'ATF_URL', plugin_dir_url( __FILE__ ) );
	}

	if ( file_exists( ATF_PATH . 'vendor/autoload.php' ) ) {
		require_once ATF_PATH . 'vendor/autoload.php';
	}

	$atf_files = array(
		'class-alt-text-fixer.php',
		'class-atf-bulk-fixer.php',
		'class-atf-content-fixer.php',
		'class-atf-render-fixer.php',
		'class-atf-ai-vision.php',
		'class-atf-seo.php',
		'class-atf-audit.php',
		'class-atf-import.php',
		'class-atf-cron.php',
		'class-atf-media-column.php',
		'class-atf-schema.php',
		'class-atf-tech-audit.php',
		'class-atf-admin.php',
	);
	foreach ( $atf_files as $atf_file ) {
		$atf_path = ATF_PATH . 'includes/' . $atf_file;
		if ( file_exists( $atf_path ) ) {
			require_once $atf_path;
		}
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		$cli_path = ATF_PATH . 'includes/class-atf-cli.php';
		if ( file_exists( $cli_path ) ) {
			require_once $cli_path;
		}
	}

	function atf_activate() {
		Alt_Text_Fixer::activate();
		ATF_Cron::activate();
		ATF_Admin::sync_role();
	}

	function atf_deactivate() {
		Alt_Text_Fixer::deactivate();
		ATF_Cron::deactivate();
		ATF_Admin::remove_role();
	}

	register_activation_hook( __FILE__, 'atf_activate' );
	register_deactivation_hook( __FILE__, 'atf_deactivate' );

	add_action( 'plugins_loaded', array( 'Alt_Text_Fixer', 'init' ) );
}

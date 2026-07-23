<?php
/**
 * Plugin Name: Himalayan Auto-Fixer — Alt Text & SEO Automation
 * Plugin URI:  https://github.com/example/himalayan-auto-fixer
 * Description: Multi-purpose automation for WordPress, straight from the roof of the world. Auto-fills missing image alt text everywhere (library, content, meta, CSS, widgets, customizer), auto-generates SEO meta titles/descriptions + social cards, imports client spreadsheets, audits theme files, and runs all fixes on a daily schedule.
 * Version:     1.3.0
 * Author:      Himalayan Auto-Fixer
 * Author URI:  https://github.com/example/himalayan-auto-fixer
 * License:     GPL-2.0-or-later
 * Text Domain: alt-text-fixer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ATF_LOADED' ) ) {
	define( 'ATF_LOADED', true );

	if ( ! defined( 'ATF_VERSION' ) ) {
		define( 'ATF_VERSION', '1.3.0' );
		define( 'ATF_FILE', __FILE__ );
		define( 'ATF_PATH', plugin_dir_path( __FILE__ ) );
		define( 'ATF_URL', plugin_dir_url( __FILE__ ) );
	}

	if ( file_exists( ATF_PATH . 'vendor/autoload.php' ) ) {
		require_once ATF_PATH . 'vendor/autoload.php';
	}

	require_once ATF_PATH . 'includes/class-alt-text-fixer.php';
	require_once ATF_PATH . 'includes/class-atf-bulk-fixer.php';
	require_once ATF_PATH . 'includes/class-atf-content-fixer.php';
	require_once ATF_PATH . 'includes/class-atf-seo.php';
	require_once ATF_PATH . 'includes/class-atf-audit.php';
	require_once ATF_PATH . 'includes/class-atf-import.php';
	require_once ATF_PATH . 'includes/class-atf-cron.php';
	require_once ATF_PATH . 'includes/class-atf-media-column.php';
	require_once ATF_PATH . 'includes/class-atf-schema.php';
	require_once ATF_PATH . 'includes/class-atf-tech-audit.php';
	require_once ATF_PATH . 'includes/class-atf-admin.php';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once ATF_PATH . 'includes/class-atf-cli.php';
	}

	register_activation_hook( __FILE__, array( 'ATF_Cron', 'activate' ) );
	register_activation_hook( __FILE__, array( 'ATF_Admin', 'sync_role' ) );
	register_deactivation_hook( __FILE__, array( 'ATF_Cron', 'deactivate' ) );
	register_deactivation_hook( __FILE__, array( 'ATF_Admin', 'remove_role' ) );

	add_action( 'plugins_loaded', array( 'Alt_Text_Fixer', 'init' ) );
}

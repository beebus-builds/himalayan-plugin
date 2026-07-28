<?php
/**
 * Uninstall handler for Himalayan Auto-Fixer.
 *
 * Removes the plugin's options, post meta, and scheduled cron event. It does
 * NOT touch core WordPress data such as _wp_attachment_image_alt, since that
 * is site content the administrator may wish to keep.
 *
 * This file is run only when the plugin is deleted (not deactivated).
 */

// If not triggered by WordPress uninstall, bail.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove options.
delete_option( 'atf_settings' );
delete_option( 'atf_seo_settings' );
delete_option( 'atf_schema_org' );

// Remove scheduled cron event.
$timestamp = wp_next_scheduled( 'atf_daily_autofix' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'atf_daily_autofix' );
}

// Remove plugin-specific post meta in bulk.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s, %s, %s, %s, %s, %s, %s )",
		'_atf_seo_title',
		'_atf_seo_desc',
		'_atf_seo_image',
		'_atf_faq',
		'_atf_schema_custom',
		'_atf_schema_type',
		'_atf_schema_fields',
		'_atf_schema_done',
		'_atf_seo_title_trashed'
	)
);

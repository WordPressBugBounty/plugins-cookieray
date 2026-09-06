<?php
/**
 * CookieRay uninstall handler.
 *
 * Drops custom tables and deletes plugin options.
 *
 * @package CookieRay
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Run the uninstall routine.
 *
 * Wrapped in a function so the locally-used variables don't pollute the
 * global scope (Plugin Check: WordPress.NamingConventions.PrefixAllGlobals).
 */
function cookieray_run_uninstall() {
	global $wpdb;

	$cookieray_tables = array(
		$wpdb->prefix . 'cookieray_cookies',
		$wpdb->prefix . 'cookieray_consent_logs',
		$wpdb->prefix . 'cookieray_scans',
		$wpdb->prefix . 'cookieray_third_party_scripts',
	);

	foreach ( $cookieray_tables as $cookieray_table ) {
		// Uninstall requires dropping plugin tables — direct schema change is intentional.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $cookieray_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	$cookieray_options = array(
		'cookieray_settings',
		'cookieray_banner_settings',
		'cookieray_db_version',
	);

	foreach ( $cookieray_options as $cookieray_option ) {
		delete_option( $cookieray_option );
	}

	wp_clear_scheduled_hook( 'cookieray_scheduled_scan' );
	wp_clear_scheduled_hook( 'cookieray_purge_old_consent_logs' );
}

cookieray_run_uninstall();

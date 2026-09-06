<?php
/**
 * Plugin deactivation handler.
 *
 * @package CookieRay
 */

namespace CookieRay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	public static function deactivate() {
		wp_clear_scheduled_hook( 'cookieray_scheduled_scan' );
		wp_clear_scheduled_hook( 'cookieray_purge_old_consent_logs' );
		delete_transient( 'cookieray_banner_public_config' );
		delete_transient( 'cookieray_dashboard_stats' );
		flush_rewrite_rules();
	}
}

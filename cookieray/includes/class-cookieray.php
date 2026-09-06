<?php
/**
 * Main plugin bootstrap.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\DB\DB_Queries;
use CookieRay\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	use Singleton;

	const PURGE_HOOK = 'cookieray_purge_old_consent_logs';

	private function __construct() {
		$this->init();
	}

	private function init() {
		I18n::instance();
		Scanner::instance();
		add_action( 'cookieray_run_deep_scan', array( 'CookieRay\\Deep_Scanner', 'run' ) );
		add_action( 'cookieray_run_deep_scan_embedded', array( 'CookieRay\\Deep_Scanner', 'run_embedded' ) );

		// Daily purge of expired consent log records.
		add_action( 'init', array( $this, 'maybe_schedule_purge' ) );
		add_action( self::PURGE_HOOK, array( $this, 'purge_old_consent_logs' ) );

		if ( is_admin() ) {
			Admin::instance();
			add_action( 'admin_init', array( '\\CookieRay\\DB\\DB_Manager', 'maybe_upgrade' ) );
			add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		} else {
			Frontend::instance();
		}

		add_action( 'rest_api_init', array( '\\CookieRay\\DB\\DB_Manager', 'maybe_upgrade' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	public function maybe_schedule_purge() {
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	public function purge_old_consent_logs() {
		$settings = get_option( 'cookieray_settings', array() );
		$days     = isset( $settings['consent_log_retention_days'] )
			? (int) $settings['consent_log_retention_days']
			: 365;
		DB_Queries::purge_consent_logs_older_than( $days );
	}

	public function maybe_redirect_after_activation() {
		if ( ! get_transient( 'cookieray_activation_redirect' ) ) {
			return;
		}
		delete_transient( 'cookieray_activation_redirect' );
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=cookieray' ) );
		exit;
	}

	public function register_rest_routes() {
		$controllers = array(
			new REST\REST_Cookies(),
			new REST\REST_Banner(),
			new REST\REST_Dashboard(),
			new REST\REST_Settings(),
			new REST\REST_Consent(),
			new REST\REST_Scanner(),
			new REST\REST_Deep_Scanner(),
			new REST\REST_Notifications(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}

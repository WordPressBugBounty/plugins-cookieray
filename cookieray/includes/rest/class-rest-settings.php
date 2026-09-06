<?php
/**
 * Plugin settings REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\Script_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Settings extends REST_Base {

	protected $rest_base = 'settings';

	const OPTION_KEY = 'cookieray_settings';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);
	}

	public function get_settings() {
		$stored = (array) get_option( self::OPTION_KEY, array() );
		// Guarantee known values for fields added after initial release.
		$stored['banner_enabled']         = ! empty( $stored['banner_enabled'] );
		$stored['google_consent_mode']    = isset( $stored['google_consent_mode'] ) ? (bool) $stored['google_consent_mode'] : true;
		$stored['uncategorized_handling'] = in_array( $stored['uncategorized_handling'] ?? '', array( 'block_always', 'marketing' ), true )
			? $stored['uncategorized_handling']
			: 'block_always';
		// Default ON for installs that pre-date this setting.
		$stored['remember_manual_categories'] = ! isset( $stored['remember_manual_categories'] )
			? true
			: (bool) $stored['remember_manual_categories'];
		return $this->success( $stored );
	}

	public function update_settings( $request ) {
		$incoming = $request->get_json_params();
		if ( empty( $incoming ) || ! is_array( $incoming ) ) {
			return $this->error( 'cookieray_invalid_payload', __( 'Invalid payload.', 'cookieray' ), 400 );
		}

		$current = get_option( self::OPTION_KEY, array() );
		$merged  = array_merge( (array) $current, $this->sanitize( $incoming ) );

		update_option( self::OPTION_KEY, $merged );
		Script_Gate::clear_cache();
		// Settings changes can flip uncategorized handling, consent mode, or
		// memory behavior — all of which the page-cache copy of the
		// rendered banner config will not reflect until purged. Arm the
		// per-user admin notice (see REST_Cookies::after_mutation()).
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id ) {
			set_transient( REST_Cookies::CACHE_PURGE_TRANSIENT_PREFIX . $user_id, 1, HOUR_IN_SECONDS );
		}
		return $this->success( $merged );
	}

	private function sanitize( $data ) {
		$out = array();

		if ( isset( $data['banner_enabled'] ) ) {
			$out['banner_enabled'] = (bool) $data['banner_enabled'];
		}
		if ( isset( $data['consent_mode'] ) ) {
			$out['consent_mode'] = in_array( $data['consent_mode'], array( 'block_until_consent', 'log_only' ), true )
				? $data['consent_mode']
				: 'log_only';
		}
		if ( isset( $data['cookie_expiry'] ) ) {
			$out['cookie_expiry'] = max( 1, min( 3650, (int) $data['cookie_expiry'] ) );
		}
		if ( isset( $data['consent_log_retention_days'] ) ) {
			$out['consent_log_retention_days'] = max( 30, min( 3650, (int) $data['consent_log_retention_days'] ) );
		}
		if ( isset( $data['trust_badge'] ) ) {
			$out['trust_badge'] = (bool) $data['trust_badge'];
		}
		if ( isset( $data['show_manage_pill'] ) ) {
			$out['show_manage_pill'] = (bool) $data['show_manage_pill'];
		}
		if ( isset( $data['auto_scan_enabled'] ) ) {
			$out['auto_scan_enabled'] = (bool) $data['auto_scan_enabled'];
		}
		if ( isset( $data['auto_scan_freq'] ) ) {
			$out['auto_scan_freq'] = in_array( $data['auto_scan_freq'], array( 'daily', 'weekly', 'monthly' ), true )
				? $data['auto_scan_freq']
				: 'weekly';
		}
		if ( isset( $data['google_consent_mode'] ) ) {
			$out['google_consent_mode'] = (bool) $data['google_consent_mode'];
		}
		if ( isset( $data['uncategorized_handling'] ) ) {
			$out['uncategorized_handling'] = in_array( $data['uncategorized_handling'], array( 'block_always', 'marketing' ), true )
				? $data['uncategorized_handling']
				: 'block_always';
		}
		if ( isset( $data['remember_manual_categories'] ) ) {
			$out['remember_manual_categories'] = (bool) $data['remember_manual_categories'];
		}

		return $out;
	}
}

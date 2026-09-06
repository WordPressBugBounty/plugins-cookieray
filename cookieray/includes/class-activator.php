<?php
/**
 * Plugin activation handler.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\DB\DB_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		DB_Manager::install();
		self::seed_defaults();
		update_option( 'cookieray_db_version', COOKIERAY_DB_VERSION );
		flush_rewrite_rules();

		// Redirect to the plugin Dashboard on the next admin page load, except
		// when activating across a multisite network or in bulk.
		$is_bulk = isset( $_REQUEST['activate-multi'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_network_admin() && ! $is_bulk ) {
			set_transient( 'cookieray_activation_redirect', true, 30 );
		}
	}

	private static function seed_defaults() {
		if ( false === get_option( 'cookieray_settings' ) ) {
			add_option( 'cookieray_settings', self::default_settings() );
		}

		if ( false === get_option( 'cookieray_banner_settings' ) ) {
			add_option( 'cookieray_banner_settings', self::default_banner_settings() );
		}
	}

	private static function default_settings() {
		return array(
			'banner_enabled'         => false, // explicit opt-in; prevents surprise popup on activation.
			'consent_mode'           => 'log_only', // 'block_until_consent' | 'log_only'
			'cookie_expiry'          => 180, // days
			'trust_badge'            => false,
			'show_manage_pill'       => false,
			'auto_scan_enabled'      => false,
			'auto_scan_freq'         => 'weekly',
			'google_consent_mode'    => true,
			'uncategorized_handling' => 'block_always', // 'block_always' | 'marketing'
			// Remember manually-assigned cookie categories so future scans
			// re-apply them instead of resetting back to scanner defaults.
			'remember_manual_categories' => true,
		);
	}

	private static function default_banner_settings() {
		return array(
			'headline'         => __( 'We value your privacy', 'cookieray' ),
			'description'      => __( 'We use cookies to enhance your browsing experience, serve personalized content, and analyze our traffic.', 'cookieray' ),
			'position'         => 'bottom-right',
			'layout'           => 'card',
			'overlay'          => false,
			'border_radius'    => 12,
			'bg_color'         => '#0F172A',
			'text_color'       => '#181C1E',
			'accent_color'     => '#0284C7',
			'accept_text'      => __( 'Accept All', 'cookieray' ),
			'decline_text'     => __( 'Decline', 'cookieray' ),
			'settings_text'    => __( 'Preferences', 'cookieray' ),
			'show_decline'     => true,
			'show_settings'    => true,
		);
	}
}

<?php
/**
 * Consent logger — writes visitor consent records to the database.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\DB\DB_Queries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Consent_Logger {

	/**
	 * Persist a consent event.
	 *
	 * @param array $data {
	 *     @type string $consent_id UUID from the visitor cookie.
	 *     @type string $status     'accepted_all' | 'declined' | 'custom'.
	 *     @type array  $categories Map of category => bool.
	 *     @type string $page_url   URL where consent was given.
	 *     @type string $ip         Raw client IP (used for hashing).
	 *     @type string $user_agent Raw UA string (hashed before storage).
	 * }
	 * @return bool
	 */
	public static function log( $data ) {
		$ip   = (string) ( $data['ip'] ?? '0.0.0.0' );
		$ua   = (string) ( $data['user_agent'] ?? '' );
		$hash = hash( 'sha256', $ip . '|' . $ua );

		// Store a truncated SHA-256 of the User-Agent so admins can dedupe per-device
		// without retaining a fingerprintable string. Raw UA never touches the DB.
		$ua_hash = '' !== $ua ? substr( hash( 'sha256', $ua ), 0, 16 ) : '';

		$payload = array(
			'visitor_hash'   => $hash,
			'consent_id'     => $data['consent_id'] ?: wp_generate_uuid4(),
			'status'         => $data['status'],
			'categories'     => $data['categories'] ?? array(),
			'ip_country'     => self::detect_country(),
			'user_agent'     => $ua_hash,
			'page_url'       => $data['page_url'] ?? '',
			'banner_version' => (string) ( $data['banner_version'] ?? '' ),
		);

		$result = DB_Queries::insert_consent_log( $payload );

		delete_transient( 'cookieray_dashboard_stats' );

		/**
		 * Fires after a consent record is persisted.
		 */
		do_action( 'cookieray_consent_logged', $payload );

		return (bool) $result;
	}

	/**
	 * Best-effort country detection — CDN headers → PHP GeoIP extension.
	 * Falls back to empty string when nothing is available.
	 */
	public static function detect_country() {
		$candidates = array(
			'HTTP_CF_IPCOUNTRY',              // Cloudflare
			'HTTP_CLOUDFRONT_VIEWER_COUNTRY', // AWS CloudFront
			'HTTP_X_COUNTRY_CODE',            // Generic proxy / load balancer
			'HTTP_X_GEO_COUNTRY',             // nginx / HAProxy
			'GEOIP_COUNTRY_CODE',             // Apache mod_geoip (MaxMind v1)
			'MM_COUNTRY_CODE',                // Apache mod_maxminddb (MaxMind v2)
		);
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$code = strtoupper( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
				if ( preg_match( '/^[A-Z]{2}$/', $code ) ) {
					return $code;
				}
			}
		}

		// PHP GeoIP extension fallback — local lookup, no external request.
		if ( function_exists( 'geoip_country_code_by_name' ) ) {
			$ip = self::get_client_ip();
			if ( $ip ) {
				$code = @geoip_country_code_by_name( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $code && preg_match( '/^[A-Z]{2}$/', $code ) ) {
					return $code;
				}
			}
		}

		return '';
	}

	/**
	 * Return the most likely real client IP, respecting common proxy headers.
	 */
	private static function get_client_ip() {
		$keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare real IP
			'HTTP_X_FORWARDED_FOR',  // Standard proxy chain (first entry is client)
			'HTTP_X_REAL_IP',        // nginx proxy_set_header X-Real-IP
			'REMOTE_ADDR',
		);
		foreach ( $keys as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$ip  = trim( explode( ',', $val )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $ip;
			}
		}
		return '';
	}
}

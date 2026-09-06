<?php
/**
 * Public consent logging REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\Consent_Logger;
use CookieRay\DB\DB_Queries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Consent extends REST_Base {

	protected $rest_base = 'consent';

	const RATE_LIMIT_WINDOW = 60; // seconds
	const RATE_LIMIT_MAX    = 5;

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_consent' ),
				'permission_callback' => array( $this, 'public_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/consent-logs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_logs' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/consent-logs/export',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_logs' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	public function list_logs( $request ) {
		$args = array(
			'page'     => (int) $request->get_param( 'page' ) ?: 1,
			'per_page' => (int) $request->get_param( 'per_page' ) ?: 20,
			'search'   => (string) $request->get_param( 'search' ),
			'status'   => (string) $request->get_param( 'status' ),
			'from'     => (string) $request->get_param( 'from' ),
			'to'       => (string) $request->get_param( 'to' ),
		);
		return $this->success( DB_Queries::get_consent_logs( $args ) );
	}

	public function export_logs( $request ) {
		$args = array(
			'page'     => 1,
			'per_page' => 500,
			'search'   => (string) $request->get_param( 'search' ),
			'status'   => (string) $request->get_param( 'status' ),
			'from'     => (string) $request->get_param( 'from' ),
			'to'       => (string) $request->get_param( 'to' ),
		);
		$data = DB_Queries::get_consent_logs( $args );

		$rows   = array();
		$rows[] = array( 'Date/Time (UTC)', 'User ID', 'Consent ID', 'Status', 'Categories', 'Country', 'Page URL' );
		foreach ( $data['items'] as $item ) {
			// Format categories as "necessary:1|analytics:0|marketing:1|preferences:1"
			$cat_parts = array();
			foreach ( (array) $item['categories'] as $name => $val ) {
				$cat_parts[] = $name . ':' . ( $val ? '1' : '0' );
			}
			$cat_str = implode( '|', $cat_parts );

			$rows[] = array(
				$item['created_at'],
				$item['user_id'],
				$item['consent_id'],
				$item['status'],
				$cat_str,
				strtoupper( (string) ( $item['ip_country'] ?? '' ) ) ?: '—',
				$item['page_url'],
			);
		}

		$csv = '';
		foreach ( $rows as $r ) {
			$escaped = array_map(
				function ( $v ) {
					$v = (string) $v;
					// Neutralize CSV formula injection: a cell starting with one of these
					// characters is interpreted as a formula by Excel / Numbers / Calc.
					// Prefixing with an apostrophe forces the cell to render as text.
					if ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) {
						$v = "'" . $v;
					}
					if ( false !== strpos( $v, ',' ) || false !== strpos( $v, '"' ) || false !== strpos( $v, "\n" ) ) {
						$v = '"' . str_replace( '"', '""', $v ) . '"';
					}
					return $v;
				},
				$r
			);
			$csv .= implode( ',', $escaped ) . "\r\n";
		}

		return $this->success(
			array(
				'filename' => 'cookieray-consent-logs-' . gmdate( 'Ymd-His' ) . '.csv',
				'csv'      => $csv,
			)
		);
	}

	public function log_consent( $request ) {
		// Origin guard: request must come from this site. Stops trivial off-site
		// scripted abuse. Cache-friendly — no per-session token required.
		if ( ! $this->verify_same_origin( $request ) ) {
			return $this->error( 'cookieray_bad_origin', __( 'Invalid origin.', 'cookieray' ), 403 );
		}

		$ip = $this->get_client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		if ( ! $this->check_rate_limit( $ip, $ua ) ) {
			return $this->error( 'cookieray_rate_limited', __( 'Too many requests.', 'cookieray' ), 429 );
		}

		$status = $request->get_param( 'status' );
		if ( ! in_array( $status, array( 'accepted_all', 'declined', 'custom' ), true ) ) {
			return $this->error( 'cookieray_invalid_status', __( 'Invalid consent status.', 'cookieray' ), 400 );
		}

		$categories = $request->get_param( 'categories' );
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$consent_id     = $request->get_param( 'consent_id' ) ?: wp_generate_uuid4();
		$banner_version = substr( (string) $request->get_param( 'banner_version' ), 0, 32 );

		Consent_Logger::log(
			array(
				'consent_id'     => $consent_id,
				'status'         => $status,
				'categories'     => $categories,
				'page_url'       => esc_url_raw( (string) $request->get_param( 'page_url' ) ),
				'ip'             => $ip,
				'user_agent'     => $ua,
				'banner_version' => $banner_version,
			)
		);

		return $this->success(
			array(
				'logged'     => true,
				'consent_id' => $consent_id,
			),
			201
		);
	}

	private function check_rate_limit( $ip, $ua = '' ) {
		// Combined IP + UA hash key — single botnet IP with rotating UAs
		// or single UA across many IPs both rate-limited per combination.
		$key   = 'cookieray_rl_' . md5( $ip . '|' . $ua );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}
		set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		return true;
	}

	/**
	 * Verify request comes from the same site (Origin or Referer header).
	 *
	 * Cache-safe: no per-session token. Stops casual off-site scripted abuse.
	 * Determined attackers can spoof these headers, but tightened rate limit
	 * + payload validation provide defense in depth for low-stakes consent log.
	 */
	private function verify_same_origin( $request ) {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $site_host ) {
			return true; // Site URL malformed — skip check rather than blocking everyone.
		}

		$candidates = array();
		$origin     = $request->get_header( 'origin' );
		$referer    = $request->get_header( 'referer' );
		if ( $origin ) {
			$candidates[] = wp_parse_url( $origin, PHP_URL_HOST );
		}
		if ( $referer ) {
			$candidates[] = wp_parse_url( $referer, PHP_URL_HOST );
		}

		foreach ( $candidates as $host ) {
			if ( $host && strcasecmp( $host, $site_host ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	private function get_client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $candidates as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}

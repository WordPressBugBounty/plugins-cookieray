<?php
/**
 * Dashboard stats REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\DB\DB_Queries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Dashboard extends REST_Base {

	protected $rest_base = 'dashboard';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_stats' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	public function get_stats() {
		$cookies       = DB_Queries::count_cookies_by_category();
		$consents_30d  = DB_Queries::count_consents( 30 );
		$consents_7d   = DB_Queries::count_consents( 7 );
		$breakdown     = DB_Queries::consent_status_breakdown( 30 );
		$daily         = DB_Queries::consents_daily( 12 );
		$recent        = DB_Queries::recent_consents( 10 );
		$latest_scan   = DB_Queries::latest_scan();
		$settings      = get_option( 'cookieray_settings', array() );
		$banner        = get_option( 'cookieray_banner_settings', array() );

		$checklist = $this->compliance_checklist( $cookies, $latest_scan, $settings, $banner );

		return $this->success(
			array(
				'compliance_score'     => $checklist['score'],
				'compliance_checklist' => $checklist['items'],
				'cookies'              => $cookies,
				'consents'             => array(
					'last_7_days'  => $consents_7d,
					'last_30_days' => $consents_30d,
					'breakdown'    => $breakdown,
					'daily'        => $daily,
				),
				'recent_activity'  => $recent,
				'latest_scan'      => $latest_scan,
			)
		);
	}

	/**
	 * Compliance checklist — each item maps to a real regulatory requirement.
	 * The score is simply (passed / total) × 100, no weighting tricks.
	 *
	 * @return array{ score: int, items: array[] }
	 */
	private function compliance_checklist( $cookies, $latest_scan, $settings, $banner ) {
		$items = array();

		// 1. Consent banner is enabled (the front door — nothing else matters until this is on).
		$items[] = array(
			'key'   => 'banner_enabled',
			'label' => __( 'Consent banner is enabled', 'cookieray' ),
			'pass'  => ! empty( $settings['banner_enabled'] ),
		);

		// 2. Consent mode set to Block Until Consent (Strict).
		$items[] = array(
			'key'   => 'consent_mode',
			'label' => __( 'Consent mode set to Strict', 'cookieray' ),
			'pass'  => ( $settings['consent_mode'] ?? 'log_only' ) === 'block_until_consent',
		);

		// 2. All cookies categorized.
		$total        = (int) ( $cookies['total'] ?? 0 );
		$uncategorized = (int) ( $cookies['uncategorized'] ?? 0 );
		$items[] = array(
			'key'   => 'categorized',
			'label' => __( 'All cookies categorized', 'cookieray' ),
			'pass'  => $total > 0 && $uncategorized === 0,
		);

		// 3. Privacy policy link configured in the banner.
		$items[] = array(
			'key'   => 'privacy_url',
			'label' => __( 'Privacy policy link configured', 'cookieray' ),
			'pass'  => ! empty( $banner['show_privacy_link'] ) && ! empty( $banner['privacy_link_url'] ),
		);

		// 4. Scan run within the last 30 days.
		$scan_age_ok = false;
		if ( ! empty( $latest_scan['completed_at'] ) ) {
			$scan_time = strtotime( $latest_scan['completed_at'] );
			$scan_age_ok = $scan_time && ( time() - $scan_time ) <= ( 30 * DAY_IN_SECONDS );
		}
		$items[] = array(
			'key'   => 'recent_scan',
			'label' => __( 'Scan run within the last 30 days', 'cookieray' ),
			'pass'  => $scan_age_ok,
		);

		// 5. Consent expiry ≤ 13 months (CNIL ceiling = 395 days).
		$items[] = array(
			'key'   => 'retention',
			'label' => __( 'Consent expiry ≤ 13 months (CNIL)', 'cookieray' ),
			'pass'  => ( (int) ( $settings['cookie_expiry'] ?? 365 ) ) <= 395,
		);

		// 6. Google Consent Mode v2 enabled.
		$items[] = array(
			'key'   => 'google_consent_mode',
			'label' => __( 'Google Consent Mode v2 enabled', 'cookieray' ),
			'pass'  => ! empty( $settings['google_consent_mode'] ),
		);

		$passed = 0;
		foreach ( $items as $item ) {
			if ( $item['pass'] ) {
				$passed++;
			}
		}

		$score = count( $items ) > 0
			? (int) round( ( $passed / count( $items ) ) * 100 )
			: 0;

		return array(
			'score' => $score,
			'items' => $items,
		);
	}
}

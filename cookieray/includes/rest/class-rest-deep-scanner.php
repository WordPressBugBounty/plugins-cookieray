<?php
/**
 * REST controller for Deep Scan.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\DB\DB_Queries;
use CookieRay\Deep_Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Deep_Scanner {

	public function register_routes(): void {
		register_rest_route(
			COOKIERAY_REST_NAMESPACE,
			'/deep-scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_deep_scan' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			COOKIERAY_REST_NAMESPACE,
			'/deep-scan/results',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_results' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'scan_id' => array(
						'required'          => true,
						'validate_callback' => function ( $v ) {
							return is_numeric( $v ) && absint( $v ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function start_deep_scan( \WP_REST_Request $request ): \WP_REST_Response {
		$scan_id = DB_Queries::insert_scan(
			array(
				'status'  => 'pending',
				'trigger' => 'manual',
			)
		);

		if ( ! $scan_id ) {
			return new \WP_REST_Response(
				array(
					'success' => false,
					'message' => 'Failed to create scan record.',
				),
				500
			);
		}

		DB_Queries::update_scan( $scan_id, array( 'scan_type' => 'deep' ) );

		wp_schedule_single_event( time(), 'cookieray_run_deep_scan', array( $scan_id ) );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'scan_id' => $scan_id,
				'message' => 'Deep scan queued.',
			),
			200
		);
	}

	public function get_results( \WP_REST_Request $request ): \WP_REST_Response {
		$scan_id = absint( $request->get_param( 'scan_id' ) );

		if ( ! $scan_id ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Missing scan_id.' ), 400 );
		}

		$scan = DB_Queries::get_scan( $scan_id );
		if ( ! $scan ) {
			return new \WP_REST_Response( array( 'success' => false, 'message' => 'Scan not found.' ), 404 );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'cookieray_third_party_scripts';
		$rows  = $wpdb->get_results( // phpcs:ignore
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE scan_id = %d ORDER BY risk_level ASC, id ASC", // phpcs:ignore
				$scan_id
			),
			ARRAY_A
		);

		$summary = array(
			'total'       => count( $rows ?: array() ),
			'high'        => 0,
			'medium'      => 0,
			'low'         => 0,
			'by_category' => array(),
		);

		$formatted = array_map(
			function ( $row ) use ( &$summary ) {
				$risk = $row['risk_level'] ?? 'medium';
				if ( isset( $summary[ $risk ] ) ) {
					$summary[ $risk ]++;
				}
				$cat = $row['category'] ?? 'uncategorized';
				if ( ! isset( $summary['by_category'][ $cat ] ) ) {
					$summary['by_category'][ $cat ] = 0;
				}
				$summary['by_category'][ $cat ]++;

				return array(
					'id'          => (int) $row['id'],
					'page_url'    => $row['page_url'],
					'script_url'  => $row['script_url'],
					'provider'    => $row['provider'],
					'category'    => $row['category'],
					'type'        => $row['type'],
					'risk_level'  => $risk,
					'detected_at' => $row['detected_at'],
				);
			},
			$rows ?: array()
		);

		return new \WP_REST_Response(
			array(
				'success' => true,
				'scan'    => DB_Queries::format_scan( $scan ),
				'summary' => $summary,
				'items'   => $formatted,
			),
			200
		);
	}
}

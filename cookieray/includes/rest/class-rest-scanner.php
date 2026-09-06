<?php
/**
 * Scanner REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\DB\DB_Queries;
use CookieRay\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Scanner extends REST_Base {

	protected $rest_base = 'scanner';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'id' => array( 'default' => 0 ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/report',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest_report' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	public function run() {
		$id = DB_Queries::insert_scan(
			array(
				'status'  => 'pending',
				'trigger' => 'manual',
			)
		);

		do_action( 'cookieray_scan_queued', $id );

		// Schedule async so the REST response returns within the web server's
		// proxy timeout (typically 30-60s). Crawling 50+ URLs at 4s each can
		// blow past that and surface as 504 Gateway Timeout. Cron worker runs
		// the scan in a separate request; the UI polls /scanner/status.
		wp_schedule_single_event( time(), Scanner::ACTION_RUN, array( $id ) );

		// Force WP-Cron dispatch in a non-blocking sub-request. WordPress's
		// default cron only fires when a real visitor hits the site; on quiet
		// admin-only environments the scan would never start.
		spawn_cron();

		$scan = DB_Queries::get_scan( $id );
		return $this->success( $scan ?: array( 'id' => $id, 'status' => 'pending' ) );
	}

	public function status( $request ) {
		$id   = (int) $request->get_param( 'id' );
		$scan = $id ? DB_Queries::get_scan( $id ) : DB_Queries::latest_scan();

		if ( ! $scan ) {
			return $this->error( 'cookieray_no_scan', __( 'No scan found.', 'cookieray' ), 404 );
		}

		// Auto-fail stuck scans (pending/running for > 10 min) so the UI
		// doesn't spin forever. Large WooCommerce/CPT sites crawling 100+
		// URLs at the URL_TIMEOUT cap legitimately need several minutes.
		if ( in_array( $scan['status'], array( 'pending', 'running' ), true ) ) {
			$reference = strtotime( $scan['started_at'] ?? '' );
			if ( ! $reference ) {
				$reference = strtotime( $scan['created_at'] ?? '' );
			}
			if ( ! $reference || ( time() - $reference ) > 600 ) {
				DB_Queries::update_scan(
					(int) $scan['id'],
					array(
						'status'       => 'failed',
						'completed_at' => current_time( 'mysql' ),
						'error_log'    => 'Scan did not complete within 10 minutes (stuck state cleaned up).',
					)
				);
				$scan = DB_Queries::get_scan( (int) $scan['id'] );
			}
		}

		return $this->success( $scan );
	}

	public function history( $request ) {
		$args = array(
			'page'     => (int) $request->get_param( 'page' ) ?: 1,
			'per_page' => (int) $request->get_param( 'per_page' ) ?: 20,
			'status'   => (string) $request->get_param( 'status' ),
		);
		return $this->success( DB_Queries::get_scans( $args ) );
	}

	public function ingest_report( $request ) {
		$payload = $request->get_json_params();
		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return $this->error( 'cookieray_invalid_payload', __( 'Invalid payload.', 'cookieray' ), 400 );
		}

		$summary = Scanner::instance()->ingest_client_report( $payload );
		return $this->success( $summary );
	}
}

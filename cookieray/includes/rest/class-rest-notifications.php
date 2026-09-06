<?php
/**
 * Notifications REST controller — surfaces the plugin changelog and tracks
 * the last version the admin has acknowledged.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Notifications extends REST_Base {

	protected $rest_base = 'notifications';

	const SEEN_OPTION = 'cookieray_seen_version';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/changelog',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_changelog' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/ack',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ack' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
			)
		);
	}

	/**
	 * Load the static changelog file (cached per-request).
	 *
	 * @return array<string, array{date:string, items:string[]}>
	 */
	private function load_changelog() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$file = COOKIERAY_PLUGIN_DIR . 'includes/data/changelog.php';
		$cache = file_exists( $file ) ? (array) require $file : array();
		return $cache;
	}

	/**
	 * GET /notifications/changelog
	 */
	public function get_changelog() {
		$current = defined( 'COOKIERAY_VERSION' ) ? COOKIERAY_VERSION : '0.0.0';
		$seen    = get_option( self::SEEN_OPTION, '' );

		// First-time install: silently mark the current version as seen so the
		// dot doesn't appear on day 1. We only want it to appear on a real
		// upgrade.
		if ( '' === $seen ) {
			update_option( self::SEEN_OPTION, $current );
			$seen = $current;
		}

		$changelog = $this->load_changelog();
		$entries   = array();
		foreach ( $changelog as $version => $entry ) {
			if ( version_compare( $version, $seen, '>' ) && version_compare( $version, $current, '<=' ) ) {
				$entries[] = array(
					'version' => $version,
					'date'    => $entry['date'] ?? '',
					'items'   => array_values( (array) ( $entry['items'] ?? array() ) ),
				);
			}
		}

		$has_unseen = ! empty( $entries );

		// If nothing unseen, still return the latest entry so the popover has
		// something to show when clicked.
		if ( ! $has_unseen && isset( $changelog[ $current ] ) ) {
			$entries[] = array(
				'version' => $current,
				'date'    => $changelog[ $current ]['date'] ?? '',
				'items'   => array_values( (array) ( $changelog[ $current ]['items'] ?? array() ) ),
			);
		}

		return $this->success(
			array(
				'current_version' => $current,
				'seen_version'    => $seen,
				'has_unseen'      => $has_unseen,
				'entries'         => $entries,
			)
		);
	}

	/**
	 * POST /notifications/ack
	 *
	 * Body: { "version": "1.1.0" }
	 */
	public function ack( $request ) {
		$version = (string) $request->get_param( 'version' );
		if ( ! $version ) {
			return $this->error( 'cookieray_missing_version', __( 'Missing version.', 'cookieray' ), 400 );
		}

		$current = defined( 'COOKIERAY_VERSION' ) ? COOKIERAY_VERSION : '0.0.0';
		if ( version_compare( $version, $current, '>' ) ) {
			return $this->error( 'cookieray_invalid_version', __( 'Cannot acknowledge a future version.', 'cookieray' ), 400 );
		}

		$changelog = $this->load_changelog();
		if ( ! isset( $changelog[ $version ] ) ) {
			return $this->error( 'cookieray_unknown_version', __( 'Unknown changelog version.', 'cookieray' ), 400 );
		}

		update_option( self::SEEN_OPTION, $version );

		return $this->success(
			array(
				'current_version' => $current,
				'seen_version'    => $version,
				'has_unseen'      => false,
			)
		);
	}
}

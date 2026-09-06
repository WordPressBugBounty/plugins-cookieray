<?php
/**
 * Abstract base REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class REST_Base {

	protected $namespace = COOKIERAY_REST_NAMESPACE;

	protected $rest_base = '';

	abstract public function register_routes();

	public function admin_permission_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'cookieray_forbidden',
				__( 'You do not have permission to access this resource.', 'cookieray' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	public function public_permission_check() {
		return true;
	}

	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	protected function success( $data, $status = 200 ) {
		return new \WP_REST_Response( $data, $status );
	}
}

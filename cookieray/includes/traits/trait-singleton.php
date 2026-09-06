<?php
/**
 * Singleton trait.
 *
 * @package CookieRay
 */

namespace CookieRay\Traits;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Singleton {

	private static $instance = null;

	public static function instance() {
		if ( null === static::$instance ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	private function __clone() {}

	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}
}

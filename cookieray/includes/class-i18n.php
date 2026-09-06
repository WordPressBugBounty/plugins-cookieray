<?php
/**
 * Internationalization loader.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Internationalization bootstrap.
 *
 * WordPress 4.6+ auto-loads translations for plugins hosted on WordPress.org
 * using the plugin slug. Calling load_plugin_textdomain() manually is
 * discouraged by Plugin Check, so this class is intentionally a no-op kept
 * for backward compatibility with code that instantiates it.
 */
class I18n {

	use Singleton;

	private function __construct() {
		// Intentionally empty. WP loads translations automatically.
	}
}

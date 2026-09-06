<?php
/**
 * Plugin Name:       CookieRay
 * Plugin URI:        https://devitems.com/
 * Description:       GDPR/CCPA-compliant cookie consent management with a premium admin UI and lightweight visitor banner.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            DevItems
 * Author URI:        https://devitems.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cookieray
 * Domain Path:       /languages
 *
 * @package CookieRay
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COOKIERAY_VERSION', '1.0.2' );
define( 'COOKIERAY_DB_VERSION', '1.0.0' );
define( 'COOKIERAY_PLUGIN_FILE', __FILE__ );
define( 'COOKIERAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'COOKIERAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'COOKIERAY_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'COOKIERAY_REST_NAMESPACE', 'cookieray/v1' );

/**
 * Set to true once the Pro upgrade/pricing page URL is live.
 * Controls the "Upgrade to Pro" sidebar item and upgrade CTAs.
 */
define( 'COOKIERAY_SHOW_UPGRADE_LINKS', true );

require_once COOKIERAY_PLUGIN_DIR . 'includes/traits/trait-singleton.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/db/class-db-manager.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/db/class-db-queries.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/db/class-classifications.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-activator.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-deactivator.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-i18n.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-admin.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-frontend.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-consent-logger.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-scanner.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-deep-scanner.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-script-gate.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-base.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-cookies.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-banner.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-dashboard.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-settings.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-consent.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-scanner.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-deep-scanner.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/rest/class-rest-notifications.php';
require_once COOKIERAY_PLUGIN_DIR . 'includes/class-cookieray.php';

register_activation_hook( __FILE__, array( 'CookieRay\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'CookieRay\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'CookieRay\\Plugin', 'instance' ) );

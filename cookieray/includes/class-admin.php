<?php
/**
 * Admin menu + React bundle enqueue.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	use Singleton;

	const MENU_SLUG  = 'cookieray';
	const CAPABILITY = 'manage_options';

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'current_screen', array( $this, 'suppress_foreign_notices' ) );
	}

	/**
	 * Submenu items: label paired with its SPA hash route.
	 *
	 * Items are injected directly into the global $submenu array (not via
	 * add_submenu_page) so WordPress renders the URL field verbatim instead of
	 * passing it through add_query_arg, which would URL-encode the `#`.
	 */
	private static function submenu_items() {
		$is_pro_active = (bool) apply_filters( 'cookieray_is_pro_active', false );

		$items = array(
			array(
				'label' => __( 'Cookie Inventory', 'cookieray' ),
				'route' => '/cookies',
			),
			array(
				'label' => __( 'Banner Design', 'cookieray' ),
				'route' => '/banner',
			),
			array(
				'label' => __( 'Consent Logs', 'cookieray' ),
				'route' => '/consent-logs',
			),
			array(
				'label' => __( 'Scan History', 'cookieray' ),
				'route' => '/scan-history',
			),
			array(
				'label' => __( 'Settings', 'cookieray' ),
				'route' => '/settings',
			),
		);

		// Show "License" when pro is active; show "Upgrade to Pro" only when
		// COOKIERAY_SHOW_UPGRADE_LINKS is enabled (upgrade pricing page not yet live).
		if ( $is_pro_active || COOKIERAY_SHOW_UPGRADE_LINKS ) {
			$items[] = array(
				'label' => $is_pro_active ? __( 'License', 'cookieray' ) : __( 'Upgrade to Pro', 'cookieray' ),
				'route' => '/license',
			);
		}

		$items[] = array(
			'label' => __( 'Support', 'cookieray' ),
			'route' => '/support',
		);

		return $items;
	}

	public function suppress_foreign_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return;
		}
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
	}

	public function register_menu() {
		add_menu_page(
			__( 'CookieRay', 'cookieray' ),
			__( 'CookieRay', 'cookieray' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_app_root' ),
			COOKIERAY_PLUGIN_URL . 'assets/images/menu-icon.svg',
			58
		);

		// Inject submenu rows manually so the URL field carries the SPA hash route
		// without URL-encoding (clean `admin.php?page=cookieray#/banner`). Clicks
		// land on the parent page; the hash router takes over client-side.
		// WordPress only auto-generates the first "duplicate parent" submenu row
		// when at least one add_submenu_page() runs — we use none, so we add the
		// Dashboard row explicitly at index 0.
		global $submenu;
		$submenu[ self::MENU_SLUG ][0] = array(
			__( 'Dashboard', 'cookieray' ),
			self::CAPABILITY,
			'admin.php?page=' . self::MENU_SLUG,
		);
		$position = 10;
		foreach ( self::submenu_items() as $item ) {
			$submenu[ self::MENU_SLUG ][ $position ] = array(
				$item['label'],
				self::CAPABILITY,
				'admin.php?page=' . self::MENU_SLUG . '#' . $item['route'],
			);
			$position += 10;
		}
	}

	public function render_app_root() {
		echo '<div id="cookieray-admin-root" class="cookieray-admin"></div>';
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}

		$js_path  = COOKIERAY_PLUGIN_DIR . 'build/admin.js';
		$css_path = COOKIERAY_PLUGIN_DIR . 'build/admin.css';
		$version  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : COOKIERAY_VERSION;

		wp_enqueue_script(
			'cookieray-jspdf',
			COOKIERAY_PLUGIN_URL . 'assets/js/jspdf.min.js',
			array(),
			'4.2.1',
			true
		);

		wp_enqueue_script(
			'cookieray-admin',
			COOKIERAY_PLUGIN_URL . 'build/admin.js',
			array( 'wp-element', 'wp-i18n', 'wp-api-fetch', 'cookieray-jspdf' ),
			$version,
			true
		);

		wp_enqueue_style(
			'cookieray-admin',
			COOKIERAY_PLUGIN_URL . 'build/admin.css',
			array(),
			$version
		);

		wp_set_script_translations( 'cookieray-admin', 'cookieray', plugin_dir_path( COOKIERAY_PLUGIN_FILE ) . 'languages' );

		wp_localize_script(
			'cookieray-admin',
			'cookieRayData',
			array(
				'restUrl'         => esc_url_raw( rest_url( COOKIERAY_REST_NAMESPACE . '/' ) ),
				'restNonce'       => wp_create_nonce( 'wp_rest' ),
				'siteUrl'         => esc_url( home_url( '/' ) ),
				'adminUrl'        => admin_url(),
				'pluginUrl'       => COOKIERAY_PLUGIN_URL,
				'version'         => COOKIERAY_VERSION,
				'isPro'             => (bool) apply_filters( 'cookieray_is_pro_active', false ),
				'isProInstalled'    => (bool) apply_filters( 'cookieray_pro_plugin_installed', false ),
				'proFeatures'       => (array) apply_filters( 'cookieray_pro_features', array() ),
				'showUpgradeLinks'  => COOKIERAY_SHOW_UPGRADE_LINKS,
				'menuSlug'          => self::MENU_SLUG,
			)
		);

		// Reset all WordPress admin input styles that bleed into Mantine Switch's
		// hidden checkbox input (WP adds border, box-shadow, background to input:disabled).
		wp_add_inline_style(
			'cookieray-admin',
			'.mantine-Switch-input { background: transparent !important; border: none !important; border-color: transparent !important; box-shadow: none !important; outline: none !important; height: 0 !important; width: 0 !important; min-height: 0 !important; min-width: 0 !important; padding: 0 !important; margin: 0 !important; } .mantine-Switch-input::before, .mantine-Switch-input::after { content: none !important; display: none !important; }'
		);

		// Highlight "Upgrade to Pro" in WP admin dark sidebar when license inactive.
		if ( COOKIERAY_SHOW_UPGRADE_LINKS && ! apply_filters( 'cookieray_is_pro_active', false ) ) {
			wp_add_inline_style(
				'cookieray-admin',
				'#adminmenu a[href*="cookieray#/license"] { color: #F59E0B !important; font-weight: 600 !important; }'
			);
		}

		// Bridge the SPA hash router and the WP submenu highlight:
		//  - Patch pushState/replaceState so React Router navigations dispatch a
		//    sync event (pushState alone does NOT fire `hashchange`).
		//  - Click intercept on `#adminmenu` swaps full-reload anchors for
		//    `location.hash =` updates, keeping the SPA snappy.
		//  - sync() walks `.wp-submenu li` and toggles `.current` based on the
		//    `#/<route>` fragment of each link, overriding WP's incorrect
		//    server-side default (which always lands on Dashboard because
		//    $plugin_page is fixed to the parent slug).
		wp_add_inline_script(
			'cookieray-admin',
			'(function(){var d=window.cookieRayData||{};var menuSlug=d.menuSlug||"cookieray";var origPush=history.pushState;var origReplace=history.replaceState;function fire(){window.dispatchEvent(new Event("cookieray:locationchange"));}history.pushState=function(){var r=origPush.apply(this,arguments);fire();return r;};history.replaceState=function(){var r=origReplace.apply(this,arguments);fire();return r;};window.addEventListener("popstate",fire);function normHash(h){if(!h||h==="#")return"#/";return h.charAt(0)==="#"?h:"#"+h;}function hashOf(href){var m=href.match(/#(.+)$/);return m?normHash("#"+m[1]):"#/";}function sync(){var current=normHash(location.hash);var menu=document.getElementById("adminmenu");if(!menu)return;var top=menu.querySelector("#toplevel_page_"+menuSlug);if(!top)return;var items=top.querySelectorAll(".wp-submenu li");items.forEach(function(li){var a=li.querySelector("a");if(!a)return;var href=a.getAttribute("href")||"";if(!/[?&]page=cookieray(?:#|$|&)/.test(href))return;var linkHash=hashOf(href);var match=linkHash===current;li.classList.toggle("current",match);a.classList.toggle("current",match);});}function intercept(e){if(e.defaultPrevented)return;if(e.button!==0||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;var a=e.target.closest&&e.target.closest("a");if(!a)return;var menu=document.getElementById("adminmenu");if(!menu||!menu.contains(a))return;var href=a.getAttribute("href")||"";if(!/[?&]page=cookieray(?:#|$|&)/.test(href))return;var target=hashOf(href);e.preventDefault();if(normHash(location.hash)===target){sync();return;}location.hash=target;}document.addEventListener("click",intercept,true);sync();window.addEventListener("hashchange",sync);window.addEventListener("cookieray:locationchange",sync);})();',
			'before'
		);
	}
}

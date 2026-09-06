<?php
/**
 * Frontend banner bootstrap — enqueues assets and injects config.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\Traits\Singleton;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	use Singleton;

	private function __construct() {
		// Late enqueue so theme stylesheets load first; CookieRay stays scoped under #cookieray-root.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 99 );
		add_action( 'wp_head', array( $this, 'inject_google_consent_mode' ), 0 ); // Must run before any Google tag.
		add_action( 'wp_head', array( $this, 'inject_config' ), 1 );
		add_action( 'wp_head', array( $this, 'inject_custom_css' ), 1 );
		add_action( 'wp_head', array( $this, 'inject_blocker' ), 2 );

		// Register the server-side script gate on init so it runs before
		// scripts are enqueued.
		add_action( 'init', array( 'CookieRay\\Script_Gate', 'register' ) );
	}

	/**
	 * Inject Google Consent Mode v2 default signal + gtag proxy.
	 *
	 * Runs at wp_head priority 0 so it fires before GTM / gtag.js loads.
	 *
	 * Cache-safe: emits identical HTML for every visitor. Always defaults to
	 * deny-all + wait_for_update=500. The frontend JS reads the visitor's
	 * cookie and fires gtag('consent','update',...) within milliseconds —
	 * well under the 500ms wait window — so accepted visitors get tracked.
	 */
	public function inject_google_consent_mode() {
		if ( is_admin() || ! $this->should_load() ) {
			return;
		}

		$settings = get_option( 'cookieray_settings', array() );
		if ( empty( $settings['google_consent_mode'] ) ) {
			return;
		}

		$state = array(
			'ad_storage'              => 'denied',
			'analytics_storage'       => 'denied',
			'ad_user_data'            => 'denied',
			'ad_personalization'      => 'denied',
			'functionality_storage'   => 'granted', // Necessary — always on.
			'personalization_storage' => 'denied',
			'security_storage'        => 'granted', // Necessary — always on.
			'wait_for_update'         => 500,
		);

		// phpcs:disable WordPress.WP.EnqueuedResources
		echo '<script id="cookieray-gcm">'
			. 'window.dataLayer=window.dataLayer||[];'
			. 'window.__cookieRayConsentResolved=false;'
			// Wrap dataLayer.push directly: third-party tag managers (HT Easy
			// GA4, MonsterInsights, etc.) often redefine `window.gtag` AFTER
			// CookieRay loads, bypassing the gtag proxy below. Hooking
			// dataLayer.push catches premature `consent update: granted` calls
			// regardless of who pushed them.
			. '(function(){'
			.   'var nativePush=window.dataLayer.push;'
			.   'window.dataLayer.push=function(){'
			.     'for(var i=0;i<arguments.length;i++){'
			.       'var a=arguments[i];'
			.       'if(a&&a[0]==="consent"&&a[1]==="update"&&!window.__cookieRayConsentResolved){'
			.         'var g=a[2]||{};'
			.         'var hasGrant=false;'
			.         'for(var k in g){if(k!=="wait_for_update"&&g[k]==="granted"){hasGrant=true;break;}}'
			.         'if(hasGrant){'
			.           'arguments[i]=["consent","update",{}];'
			.         '}'
			.       '}'
			.     '}'
			.     'return nativePush.apply(this,arguments);'
			.   '};'
			. '})();'
			// Proxy gtag: suppress third-party consent 'update: granted' calls
			// arriving before visitor has chosen. CookieRay JS flips the flag
			// after reading the cookie client-side, then proxy becomes passthrough.
			. 'window.gtag=function(){'
			.   'var a=arguments;'
			.   'if(a[0]==="consent"&&a[1]==="update"&&!window.__cookieRayConsentResolved){'
			.     'var g=a[2]||{};'
			.     'var hasGrant=Object.keys(g).some(function(k){return k!=="wait_for_update"&&g[k]==="granted";});'
			.     'if(hasGrant)return;'
			.   '}'
			.   'dataLayer.push(a);'
			. '};'
			. 'function gtag(){window.gtag.apply(this,arguments);}'
			. 'gtag("consent","default",' . wp_json_encode( $state ) . ');'
			. '</script>' . "\n";
		// phpcs:enable WordPress.WP.EnqueuedResources
	}

	/**
	 * Print site-owner-defined CSS scoped to the banner markup.
	 *
	 * Stored as plain text (not sanitize_text_field, which would strip
	 * CSS-meaningful characters) — the only save-time guard is against
	 * breaking out of the <style> tag. Escape again here defensively since
	 * output happens on every page load, not just at save time.
	 */
	public function inject_custom_css() {
		if ( is_admin() || ! $this->should_load() ) {
			return;
		}

		$banner = get_option( 'cookieray_banner_settings', array() );
		$css    = trim( (string) ( $banner['custom_css'] ?? '' ) );
		if ( '' === $css ) {
			return;
		}

		$css = str_replace( '</style', '<\\/style', $css );
		echo '<style id="cookieray-custom-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function inject_blocker() {
		if ( is_admin() || ! $this->should_load() ) {
			return;
		}
		Script_Gate::inline_blocker_script();
	}

	public function enqueue_assets() {
		// Scanner loads for any logged-in admin regardless of banner_enabled state.
		if ( ! is_admin() && current_user_can( 'manage_options' ) && isset( $_GET['cookieray_scan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script(
				'cookieray-scanner',
				COOKIERAY_PLUGIN_URL . 'assets/js/scanner.js',
				array(),
				COOKIERAY_VERSION,
				true
			);
			wp_localize_script(
				'cookieray-scanner',
				'cookieRayScanner',
				array(
					'restUrl' => esc_url_raw( rest_url( COOKIERAY_REST_NAMESPACE . '/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'scanId'  => isset( $_GET['cookieray_scan_id'] ) ? (int) $_GET['cookieray_scan_id'] : 0, // phpcs:ignore WordPress.Security.NonceVerification
				)
			);
		}

		if ( is_admin() || ! $this->should_load() ) {
			return;
		}

		$js_path  = COOKIERAY_PLUGIN_DIR . 'build/frontend.js';
		$css_path = COOKIERAY_PLUGIN_DIR . 'build/frontend.css';

		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'cookieray-frontend',
				COOKIERAY_PLUGIN_URL . 'build/frontend.js',
				array(),
				(string) filemtime( $js_path ),
				true
			);

			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'cookieray-frontend',
					COOKIERAY_PLUGIN_URL . 'build/frontend.css',
					array(),
					(string) filemtime( $css_path )
				);
			}
		}
	}

	public function inject_config() {
		if ( is_admin() || ! $this->should_load() ) {
			return;
		}

		$banner   = get_option( 'cookieray_banner_settings', array() );
		$settings = get_option( 'cookieray_settings', array() );

		$uncat_handling = in_array( $settings['uncategorized_handling'] ?? '', array( 'block_always', 'marketing' ), true )
			? $settings['uncategorized_handling']
			: 'block_always';

		$config = array(
			'restUrl'               => esc_url_raw( rest_url( COOKIERAY_REST_NAMESPACE . '/' ) ),
			'consentMode'           => $settings['consent_mode'] ?? 'log_only',
			'cookieExpiry'          => (int) ( $settings['cookie_expiry'] ?? 365 ),
			'trustBadge'            => ! empty( $settings['trust_badge'] ),
			'googleConsentMode'     => ! empty( $settings['google_consent_mode'] ),
			'uncategorizedHandling' => $uncat_handling,
			'bannerVersion'         => self::compute_banner_version( $banner ),
			'country'               => Consent_Logger::detect_country(),
			'showManagePill'        => ! empty( $settings['show_manage_pill'] ),
			'banner'       => array(
				'headline'      => $banner['headline'] ?? __( 'We respect your privacy', 'cookieray' ),
				'description'   => $banner['description'] ?? __( 'This website uses cookies to enhance your browsing experience and provide personalized content.', 'cookieray' ),
				'position'      => $banner['position'] ?? 'bottom-right',
				'layout'        => $banner['layout'] ?? 'card',
				'overlay'       => ! empty( $banner['overlay'] ),
				'borderRadius'  => (int) ( $banner['border_radius'] ?? 16 ),
				'bgColor'       => $banner['bg_color'] ?? '#004D75',
				'textColor'     => $banner['text_color'] ?? '#181C1E',
				'accentColor'   => $banner['accent_color'] ?? '#006699',
				'acceptText'    => $banner['accept_text'] ?? __( 'Accept All Cookies', 'cookieray' ),
				'declineText'   => $banner['decline_text'] ?? __( 'Decline', 'cookieray' ),
				'settingsText'  => $banner['settings_text'] ?? __( 'Settings', 'cookieray' ),
				'showDecline'   => ! isset( $banner['show_decline'] ) || $banner['show_decline'],
				'showSettings'  => ! isset( $banner['show_settings'] ) || $banner['show_settings'],
				'showPrivacyLink' => ! empty( $banner['show_privacy_link'] ),
				'privacyLinkText' => $banner['privacy_link_text'] ?? '',
				// Re-validate the URL at output time: only http/https schemes are allowed,
				// blocking any javascript:/data: payload that might have slipped past the save-time sanitizer.
				'privacyLinkUrl'  => esc_url( $banner['privacy_link_url'] ?? '', array( 'http', 'https' ) ),
				'categoryLabels'  => array_replace_recursive( self::default_category_labels(), $banner['category_labels'] ?? array() ),
			),
		);

		// Inline site-owner-defined cookies (DB) keyed by category so the
		// frontend can delete them on decline alongside the static
		// known-cookies.json baseline.
		$config['customCookies'] = self::collect_custom_cookies();

		$config = apply_filters( 'cookieray_frontend_config', $config );

		echo '<script id="cookieray-config">window.cookieRayConfig = ' . wp_json_encode( $config ) . ';</script>' . "\n";
	}

	/**
	 * Read DB cookies for runtime use by the frontend deletion logic.
	 * Includes analytical/functional/marketing/uncategorized. Necessary
	 * cookies are excluded (never deleted on decline). Uncategorized
	 * cookies pass through with original category — frontend logic in
	 * deleteTrackingCookies() applies the configured fallback rule
	 * (block_always or marketing) at runtime.
	 *
	 * @return array<int, array{name:string,category:string,is_regex:bool}>
	 */
	private static function default_category_labels() {
		return array(
			'necessary'  => array(
				'label'       => __( 'Strictly Necessary', 'cookieray' ),
				'description' => __( 'Required for the site to function. Cannot be disabled.', 'cookieray' ),
			),
			'analytical' => array(
				'label'       => __( 'Analytics', 'cookieray' ),
				'description' => __( 'Help us understand how visitors use the site.', 'cookieray' ),
			),
			'functional' => array(
				'label'       => __( 'Functional', 'cookieray' ),
				'description' => __( 'Remember your preferences and enhance features.', 'cookieray' ),
			),
			'marketing'  => array(
				'label'       => __( 'Marketing', 'cookieray' ),
				'description' => __( 'Used to deliver personalized advertising.', 'cookieray' ),
			),
		);
	}

	private static function collect_custom_cookies() {
		if ( ! class_exists( '\\CookieRay\\DB\\DB_Queries' ) ) {
			return array();
		}
		$result = \CookieRay\DB\DB_Queries::get_cookies(
			array(
				'category__in'  => array( 'analytical', 'functional', 'marketing', 'uncategorized' ),
				'no_pagination' => true,
			)
		);
		$out = array();
		foreach ( ( $result['items'] ?? array() ) as $row ) {
			if ( empty( $row['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $row['name'],
				'category' => (string) $row['category'],
				'is_regex' => ! empty( $row['is_regex'] ),
			);
		}
		return $out;
	}

	/**
	 * Compute a short version hash for the current banner config + category list.
	 *
	 * Stored alongside each consent record. When the admin changes banner copy,
	 * adds/removes categories, or alters the privacy link, the hash changes —
	 * existing consents become invalid and the banner re-shows so visitors can
	 * re-consent under the new terms (GDPR transparency).
	 *
	 * @param array $banner Banner settings option.
	 * @return string 12-char hash.
	 */
	public static function compute_banner_version( $banner ) {
		$material = wp_json_encode(
			array(
				'h'  => $banner['headline']     ?? '',
				'd'  => $banner['description']  ?? '',
				'a'  => $banner['accept_text']  ?? '',
				'dc' => $banner['decline_text'] ?? '',
				's'  => $banner['settings_text'] ?? '',
				'pl' => $banner['privacy_link_url'] ?? '',
				// Categories are hardcoded in the JS but listed here so future
				// changes (adding a new category) automatically invalidate consent.
				'c'  => array( 'necessary', 'analytical', 'functional', 'marketing' ),
			)
		);
		return substr( hash( 'sha256', $material ), 0, 12 );
	}

	private function should_load() {
		$settings = get_option( 'cookieray_settings', array() );
		if ( empty( $settings['banner_enabled'] ) ) {
			return false;
		}
		/**
		 * Allow disabling the banner on specific pages.
		 */
		return (bool) apply_filters( 'cookieray_should_load_banner', true );
	}
}

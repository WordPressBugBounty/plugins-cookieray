<?php
/**
 * Banner design REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Banner extends REST_Base {

	protected $rest_base = 'banner';

	const OPTION_KEY        = 'cookieray_banner_settings';
	const PUBLIC_CACHE_KEY  = 'cookieray_banner_public_config';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/public',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public' ),
				'permission_callback' => array( $this, 'public_permission_check' ),
			)
		);
	}

	public function get_settings() {
		$settings                    = get_option( self::OPTION_KEY, array() );
		$settings['category_labels'] = array_replace_recursive(
			self::default_category_labels(),
			self::strip_empty_category_overrides( $settings['category_labels'] ?? array() )
		);
		return $this->success( $settings );
	}

	public function update_settings( $request ) {
		$incoming = $request->get_json_params();
		if ( empty( $incoming ) || ! is_array( $incoming ) ) {
			return $this->error( 'cookieray_invalid_payload', __( 'Invalid payload.', 'cookieray' ), 400 );
		}

		$current = get_option( self::OPTION_KEY, array() );
		$merged  = array_merge( (array) $current, $this->sanitize( $incoming ) );

		update_option( self::OPTION_KEY, $merged );
		delete_transient( self::PUBLIC_CACHE_KEY );

		return $this->success( $merged );
	}

	public function get_public() {
		$cached = get_transient( self::PUBLIC_CACHE_KEY );
		if ( false !== $cached ) {
			return $this->success( $cached );
		}

		$settings = get_option( self::OPTION_KEY, array() );
		$public   = array(
			'headline'      => $settings['headline'] ?? 'We respect your privacy',
			'description'   => $settings['description'] ?? 'This website uses cookies to enhance your browsing experience and provide personalized content.',
			'position'      => $settings['position'] ?? 'bottom-right',
			'layout'        => $settings['layout'] ?? 'card',
			'overlay'       => ! empty( $settings['overlay'] ),
			'border_radius' => (int) ( $settings['border_radius'] ?? 16 ),
			'bg_color'      => $settings['bg_color'] ?? '#004D75',
			'text_color'    => $settings['text_color'] ?? '#181C1E',
			'accent_color'  => $settings['accent_color'] ?? '#006699',
			'accept_text'   => $settings['accept_text'] ?? 'Accept All Cookies',
			'decline_text'  => $settings['decline_text'] ?? 'Decline',
			'settings_text' => $settings['settings_text'] ?? 'Settings',
			'show_decline'  => ! isset( $settings['show_decline'] ) || ! empty( $settings['show_decline'] ),
			'show_settings' => ! isset( $settings['show_settings'] ) || ! empty( $settings['show_settings'] ),
			'show_privacy_link' => ! empty( $settings['show_privacy_link'] ),
			'privacy_link_text' => $settings['privacy_link_text'] ?? '',
			'privacy_link_url'  => $settings['privacy_link_url'] ?? '',
			'custom_css'        => $settings['custom_css'] ?? '',
			'category_labels'   => array_replace_recursive(
				self::default_category_labels(),
				self::strip_empty_category_overrides( $settings['category_labels'] ?? array() )
			),
		);

		set_transient( self::PUBLIC_CACHE_KEY, $public, HOUR_IN_SECONDS );
		return $this->success( $public );
	}

	/**
	 * Drop any label/description that's blank so it can't shadow the default
	 * copy — covers rows saved before this guard existed at write time, or
	 * any other way an empty override slipped into storage.
	 */
	private static function strip_empty_category_overrides( $stored ) {
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $cat => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$entry = array();
			if ( isset( $row['label'] ) && '' !== trim( (string) $row['label'] ) ) {
				$entry['label'] = $row['label'];
			}
			if ( isset( $row['description'] ) && '' !== trim( (string) $row['description'] ) ) {
				$entry['description'] = $row['description'];
			}
			if ( ! empty( $entry ) ) {
				$out[ $cat ] = $entry;
			}
		}
		return $out;
	}

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

	private function sanitize( $data ) {
		$out = array();

		foreach ( array( 'headline', 'description', 'accept_text', 'decline_text', 'settings_text', 'privacy_link_text' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}
		if ( isset( $data['privacy_link_url'] ) ) {
			$out['privacy_link_url'] = esc_url_raw( $data['privacy_link_url'] );
		}
		foreach ( array( 'bg_color', 'text_color', 'accent_color' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$clean = sanitize_hex_color( $data[ $key ] );
				if ( $clean ) {
					$out[ $key ] = $clean;
				}
			}
		}
		if ( isset( $data['position'] ) ) {
			$out['position'] = sanitize_key( $data['position'] );
		}
		if ( isset( $data['layout'] ) ) {
			$out['layout'] = sanitize_key( $data['layout'] );
		}
		if ( isset( $data['border_radius'] ) ) {
			$out['border_radius'] = max( 0, min( 48, (int) $data['border_radius'] ) );
		}
		foreach ( array( 'overlay', 'show_decline', 'show_settings', 'show_privacy_link' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = (bool) $data[ $key ];
			}
		}
		if ( isset( $data['category_labels'] ) && is_array( $data['category_labels'] ) ) {
			$clean = array();
			foreach ( array( 'necessary', 'analytical', 'functional', 'marketing' ) as $cat ) {
				$row = $data['category_labels'][ $cat ] ?? array();
				if ( ! is_array( $row ) ) {
					continue;
				}
				// Only keep fields the admin actually typed something into — an
				// empty string here would otherwise permanently overwrite the
				// default copy for visitors (array_replace_recursive treats a
				// present-but-empty value as an explicit override, not "unset").
				$entry = array();
				$label = trim( sanitize_text_field( $row['label'] ?? '' ) );
				if ( '' !== $label ) {
					$entry['label'] = mb_substr( $label, 0, 40 );
				}
				$description = trim( sanitize_text_field( $row['description'] ?? '' ) );
				if ( '' !== $description ) {
					$entry['description'] = mb_substr( $description, 0, 160 );
				}
				if ( ! empty( $entry ) ) {
					$clean[ $cat ] = $entry;
				}
			}
			$out['category_labels'] = $clean;
		}
		// Custom CSS is a Pro feature. Already-saved CSS keeps rendering on the
		// live site regardless of current license state (soft gate) — this only
		// blocks *new* saves from a non-Pro request.
		if ( isset( $data['custom_css'] ) && apply_filters( 'cookieray_is_pro_active', false ) ) {
			// Plain-text CSS storage — sanitize_text_field would strip CSS-meaningful
			// characters (quotes, braces). Only guard against breaking out of the
			// <style> tag the value is later echoed into.
			$css               = str_replace( '</style', '<\\/style', (string) $data['custom_css'] );
			$out['custom_css'] = mb_substr( $css, 0, 5000 );
		}

		return $out;
	}
}

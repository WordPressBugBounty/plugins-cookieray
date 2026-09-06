<?php
/**
 * Cookies CRUD REST controller.
 *
 * @package CookieRay
 */

namespace CookieRay\REST;

use CookieRay\DB\DB_Queries;
use CookieRay\DB\Classifications;
use CookieRay\Script_Gate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST_Cookies extends REST_Base {

	protected $rest_base = 'cookies';

	const CACHE_PURGE_TRANSIENT_PREFIX = 'cookieray_cache_purge_notice_';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => array(
						'page'     => array( 'default' => 1 ),
						'per_page' => array( 'default' => 20 ),
						'search'   => array( 'default' => '' ),
						'category' => array( 'default' => '' ),
						'orderby'  => array( 'default' => 'created_at' ),
						'order'    => array( 'default' => 'DESC' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => $this->item_schema(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/bulk',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_action' ),
				'permission_callback' => array( $this, 'admin_permission_check' ),
				'args'                => array(
					'action' => array( 'required' => true ),
					// Optional — delete_all / delete_all_uncategorized
					// operate across all pages and ignore this parameter.
					'ids'    => array( 'required' => false, 'default' => array() ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/classifications',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_classifications' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
				array(
					// DELETE wipes every entry; powers the
					// "Clear Saved Cookie Classifications" admin button.
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear_classifications' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
					'args'                => $this->item_schema(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'admin_permission_check' ),
				),
			)
		);
	}

	protected function item_schema() {
		return array(
			'name'             => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'domain'           => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'path'             => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'provider'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			// `category` is the admin-facing input — historically the only
			// field in the form. We treat it as the admin's manual decision
			// and write through to manual_category. Set to "uncategorized"
			// (or empty) to clear the manual decision and fall back to the
			// scanner-detected value.
			'category'         => array(
				'type'              => 'string',
				'enum'              => array( 'necessary', 'analytical', 'functional', 'marketing', 'uncategorized' ),
			),
			'duration'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'duration_seconds' => array( 'type' => 'integer' ),
			'description'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'is_regex'         => array( 'type' => 'boolean' ),
			'is_third_party'   => array( 'type' => 'boolean' ),
			'script_pattern'   => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	public function get_items( $request ) {
		$result = DB_Queries::get_cookies(
			array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
				'search'   => $request->get_param( 'search' ),
				'category' => $request->get_param( 'category' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
			)
		);

		return rest_ensure_response(
			array(
				'items'       => $result['items'],
				'total'       => $result['total'],
				'total_pages' => $result['total_pages'],
			)
		);
	}

	public function create_item( $request ) {
		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return $this->error( 'cookieray_missing_name', __( 'Cookie name is required.', 'cookieray' ), 400 );
		}

		// `category` from the form is treated as the admin's manual choice.
		// "uncategorized" or empty means "no manual decision; use detected".
		$incoming_category = $request->get_param( 'category' );
		$manual_category   = ( null === $incoming_category || '' === $incoming_category || 'uncategorized' === $incoming_category )
			? null
			: (string) $incoming_category;

		$domain = strtolower( ltrim( (string) $request->get_param( 'domain' ), '.' ) );
		$path_in = (string) $request->get_param( 'path' );
		$path   = '' !== $path_in ? $path_in : '/';

		$cookie = DB_Queries::insert_cookie(
			array(
				'name'              => $name,
				'domain'            => $domain,
				'path'              => $path,
				'provider'          => (string) $request->get_param( 'provider' ),
				'detected_category' => 'uncategorized',
				'manual_category'   => $manual_category,
				'manual_source'     => null === $manual_category ? null : 'admin',
				'duration'          => (string) $request->get_param( 'duration' ),
				'duration_seconds'  => (int) $request->get_param( 'duration_seconds' ),
				'description'       => (string) $request->get_param( 'description' ),
				'is_regex'          => (bool) $request->get_param( 'is_regex' ),
				'auto_detected'     => 0,
				'script_pattern'    => (string) $request->get_param( 'script_pattern' ),
				'source_type'       => 'manual',
			)
		);

		if ( ! $cookie ) {
			return $this->error( 'cookieray_insert_failed', __( 'Failed to create cookie.', 'cookieray' ), 500 );
		}
		if ( null !== $manual_category ) {
			Classifications::remember( $name, $manual_category, $domain, $path );
		}
		self::after_mutation();
		return $this->success( $cookie, 201 );
	}

	public function update_item( $request ) {
		$id       = (int) $request->get_param( 'id' );
		$existing = DB_Queries::get_cookie( $id );
		if ( ! $existing ) {
			return $this->error( 'cookieray_not_found', __( 'Cookie not found.', 'cookieray' ), 404 );
		}

		$data = array();
		foreach ( array( 'name', 'domain', 'path', 'provider', 'duration', 'duration_seconds', 'description', 'is_regex', 'is_third_party', 'script_pattern' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = $request->get_param( $key );
			}
		}

		// Treat the inbound `category` field as the admin's manual decision.
		// Empty / "uncategorized" clears the manual decision and lets the
		// stored `detected_category` take over via update_cookie's
		// manual-clear contract.
		$category_in       = $request->get_param( 'category' );
		$category_provided = null !== $category_in;
		if ( $category_provided ) {
			if ( '' === $category_in || 'uncategorized' === $category_in ) {
				$data['manual_category'] = null;
				$data['manual_source']   = null;
			} else {
				$data['manual_category'] = (string) $category_in;
				$data['manual_source']   = 'admin';
			}
		}

		$cookie = DB_Queries::update_cookie( $id, $data );
		if ( ! $cookie ) {
			return $this->error( 'cookieray_update_failed', __( 'Failed to update cookie.', 'cookieray' ), 500 );
		}
		// Mirror manual decisions into Classifications memory so a future
		// delete-and-rescan cycle reapplies them. Empty manual_category =
		// "forget the decision."
		if ( $category_provided ) {
			$mem_name   = (string) $cookie['name'];
			$mem_domain = (string) ( $cookie['domain'] ?? '' );
			$mem_path   = (string) ( $cookie['path'] ?? '/' );
			if ( null === $data['manual_category'] ) {
				Classifications::forget( $mem_name, $mem_domain, $mem_path );
			} else {
				Classifications::remember( $mem_name, $data['manual_category'], $mem_domain, $mem_path );
			}
		}
		self::after_mutation();
		return $this->success( $cookie );
	}

	public function delete_item( $request ) {
		$id      = (int) $request->get_param( 'id' );
		$deleted = DB_Queries::delete_cookie( $id );
		if ( ! $deleted ) {
			return $this->error( 'cookieray_delete_failed', __( 'Failed to delete cookie.', 'cookieray' ), 500 );
		}
		self::after_mutation();
		return $this->success( array( 'deleted' => true, 'id' => $id ) );
	}

	public function bulk_action( $request ) {
		$action = $request->get_param( 'action' );
		$ids    = (array) $request->get_param( 'ids' );

		if ( 'delete' === $action ) {
			$count = DB_Queries::delete_cookies( $ids );
			self::after_mutation();
			return $this->success( array( 'deleted' => $count ) );
		}

		if ( 'delete_all' === $action ) {
			$count = DB_Queries::delete_all_cookies();
			self::after_mutation();
			return $this->success( array( 'deleted' => $count ) );
		}

		if ( 'delete_all_uncategorized' === $action ) {
			$count = DB_Queries::delete_cookies_by_effective_category( 'uncategorized' );
			self::after_mutation();
			return $this->success( array( 'deleted' => $count ) );
		}

		if ( in_array( $action, array( 'necessary', 'analytical', 'functional', 'marketing', 'uncategorized' ), true ) ) {
			$updated_count = 0;
			foreach ( $ids as $id ) {
				$payload  = ( 'uncategorized' === $action )
					? array( 'manual_category' => null, 'manual_source' => null )
					: array( 'manual_category' => $action, 'manual_source' => 'admin' );
				$updated = DB_Queries::update_cookie( (int) $id, $payload );
				if ( $updated && ! empty( $updated['name'] ) ) {
					$mem_name   = (string) $updated['name'];
					$mem_domain = (string) ( $updated['domain'] ?? '' );
					$mem_path   = (string) ( $updated['path'] ?? '/' );
					if ( 'uncategorized' === $action ) {
						Classifications::forget( $mem_name, $mem_domain, $mem_path );
					} else {
						Classifications::remember( $mem_name, $action, $mem_domain, $mem_path );
					}
					$updated_count++;
				}
			}
			self::after_mutation();
			return $this->success( array( 'updated' => $updated_count ) );
		}

		return $this->error( 'cookieray_unknown_action', __( 'Unknown bulk action.', 'cookieray' ), 400 );
	}

	/**
	 * Read-only listing of remembered classifications. Used by the Settings
	 * page to surface "X cookies remembered" alongside the Clear button.
	 */
	public function get_classifications() {
		$all   = Classifications::all();
		$items = array();
		foreach ( $all as $key => $entry ) {
			$items[] = array(
				'key'        => $key,
				'category'   => $entry['category'] ?? '',
				'updated_at' => $entry['updated_at'] ?? '',
			);
		}
		return $this->success(
			array(
				'total' => count( $items ),
				'items' => $items,
			)
		);
	}

	/**
	 * Drop every remembered classification AND reset manual_category/source
	 * on every existing inventory row so the effective category falls back
	 * to detected_category immediately. Pairing the option wipe with the
	 * row reset keeps admin display and frontend mapping consistent without
	 * requiring a rescan.
	 */
	public function clear_classifications() {
		$cleared = Classifications::clear_all();
		$reset   = DB_Queries::clear_all_manual_categories();
		self::after_mutation();
		return $this->success(
			array(
				'cleared'    => (int) $cleared,
				'rows_reset' => (int) $reset,
			)
		);
	}

	/**
	 * Bookkeeping after any mutation: clear the in-process script gate
	 * pattern cache and arm a per-user "purge cache" admin notice. Cache
	 * caches at edge / page-cache plugins are still the admin's problem
	 * to flush — the notice prompts them to do so.
	 */
	private static function after_mutation() {
		Script_Gate::clear_cache();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id ) {
			set_transient( self::CACHE_PURGE_TRANSIENT_PREFIX . $user_id, 1, HOUR_IN_SECONDS );
		}
	}
}

<?php
/**
 * Manual classification memory.
 *
 * Stores admin-assigned cookie categories keyed by a stable cookie identifier
 * so they can be re-applied automatically on future scans. Independent of the
 * cookies inventory table — deleting all inventory rows does NOT wipe memory,
 * by design: admins should not have to recategorize the same cookie after
 * every scan.
 *
 * Storage: single wp_options row (`cookieray_classifications`) holding an
 * associative map `stable_key => array{ category, updated_at }`. Option
 * keeps the surface area minimal (no schema migration) and the dataset
 * stays small in practice (one entry per uniquely-named cookie an admin
 * has touched).
 *
 * Stable key: lowercase trimmed cookie name. Domain/path are not yet stored
 * on cookie rows, so `make_key()` only consumes the name. The signature
 * accepts optional domain/path for forward compatibility — when those
 * columns land, callers will start passing them and the key will widen
 * automatically.
 *
 * @package CookieRay
 */

namespace CookieRay\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Classifications {

	const OPTION_KEY = 'cookieray_classifications';

	/**
	 * Process-scoped cache so a single request that reads memory for many
	 * cookie rows (e.g. inventory listing) hits get_option() at most once.
	 *
	 * @var array<string, array{category:string,updated_at:string}>|null
	 */
	private static $cache = null;

	/**
	 * Build a stable identifier for a cookie. Lowercased + trimmed. Domain
	 * and path are appended if provided; today they are always blank but
	 * the API is shaped for the day cookie rows carry them.
	 */
	public static function make_key( $name, $domain = '', $path = '' ) {
		$name   = strtolower( trim( (string) $name ) );
		$domain = strtolower( trim( (string) $domain ) );
		$path   = trim( (string) $path );
		if ( '' === $name ) {
			return '';
		}
		$key = $name;
		if ( '' !== $domain ) {
			$key .= '|' . $domain;
		}
		if ( '' !== $path ) {
			$key .= '|' . $path;
		}
		return $key;
	}

	/**
	 * Whole memory map. Reads option once per request.
	 *
	 * @return array<string, array{category:string,updated_at:string}>
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$raw = get_option( self::OPTION_KEY, array() );
			self::$cache = is_array( $raw ) ? $raw : array();
		}
		return self::$cache;
	}

	/**
	 * Look up a remembered category for a cookie key.
	 *
	 * @return array{category:string,updated_at:string}|null
	 */
	public static function get( $name, $domain = '', $path = '' ) {
		$key = self::make_key( $name, $domain, $path );
		if ( '' === $key ) {
			return null;
		}
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Save (or overwrite) a remembered category. Caller is responsible for
	 * deciding whether the save is appropriate (e.g. only persist when the
	 * `remember_manual_categories` setting is enabled and only for valid
	 * non-uncategorized categories).
	 */
	public static function remember( $name, $category, $domain = '', $path = '' ) {
		$key      = self::make_key( $name, $domain, $path );
		$category = sanitize_key( (string) $category );
		if ( '' === $key || '' === $category ) {
			return false;
		}
		$all         = self::all();
		$all[ $key ] = array(
			'category'   => $category,
			'updated_at' => current_time( 'mysql' ),
		);
		self::$cache = $all;
		return update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Forget a single cookie key. Used if we ever expose per-row memory
	 * removal in the UI; not currently called.
	 */
	public static function forget( $name, $domain = '', $path = '' ) {
		$key = self::make_key( $name, $domain, $path );
		if ( '' === $key ) {
			return false;
		}
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}
		unset( $all[ $key ] );
		self::$cache = $all;
		return update_option( self::OPTION_KEY, $all, false );
	}

	/**
	 * Wipe every remembered classification. Powers the
	 * "Clear Saved Cookie Classifications" admin action. Returns the number
	 * of entries that were removed so the UI can show a count.
	 */
	public static function clear_all() {
		$all = self::all();
		$n   = count( $all );
		self::$cache = array();
		delete_option( self::OPTION_KEY );
		return $n;
	}

	/**
	 * Flush the in-process cache. Called after writes that bypass the
	 * helpers (defensive — currently no callers, but keeps the contract
	 * explicit for future code).
	 */
	public static function flush_cache() {
		self::$cache = null;
	}

	/**
	 * Whether the site setting opts in to applying remembered categories
	 * on future scans. Default ON to match the spec.
	 */
	public static function is_enabled() {
		$settings = get_option( 'cookieray_settings', array() );
		// Treat missing key as "on" so existing installs benefit without
		// requiring an explicit settings save.
		return ! isset( $settings['remember_manual_categories'] )
			? true
			: (bool) $settings['remember_manual_categories'];
	}
}

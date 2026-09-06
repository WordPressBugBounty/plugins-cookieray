<?php
/**
 * Reusable database query layer.
 *
 * @package CookieRay
 */

namespace CookieRay\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_Queries {

	private static function cache_last_changed( $key ) {
		$v = wp_cache_get( $key, 'cookieray' );
		if ( false === $v ) {
			$v = microtime( true );
			wp_cache_set( $key, $v, 'cookieray' );
		}
		return $v;
	}

	private static function cache_invalidate( $key ) {
		wp_cache_delete( $key, 'cookieray' );
	}

	/* --------------------------------------------------------------------
	 *  Cookies
	 * ------------------------------------------------------------------ */

	public static function get_cookies( $args = array() ) {
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );

		$defaults = array(
			'page'               => 1,
			'per_page'           => 20,
			'search'             => '',
			'category'           => '',
			'category__in'       => array(),
			'has_script_pattern' => false,
			'no_pagination'      => false,
			'orderby'            => 'created_at',
			'order'              => 'DESC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$where[]  = '(name LIKE %s OR provider LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		if ( ! empty( $args['category'] ) && 'all' !== $args['category'] ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}

		if ( ! empty( $args['category__in'] ) && is_array( $args['category__in'] ) ) {
			$valid_cats = array_values( array_filter( array_map( 'sanitize_key', $args['category__in'] ) ) );
			if ( ! empty( $valid_cats ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $valid_cats ), '%s' ) );
				$where[]      = "category IN ({$placeholders})";
				$params       = array_merge( $params, $valid_cats );
			}
		}

		if ( ! empty( $args['has_script_pattern'] ) ) {
			$where[] = "(script_pattern IS NOT NULL AND script_pattern <> '')";
		}

		$where_sql = implode( ' AND ', $where );
		$orderby   = in_array( $args['orderby'], array( 'name', 'provider', 'category', 'created_at', 'updated_at' ), true )
			? $args['orderby']
			: 'created_at';
		$order     = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$cache_key = 'cr_cookies_' . md5( wp_json_encode( $args ) . self::cache_last_changed( 'cr_cookies_changed' ) );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}

		// Count.
		$count_sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		if ( ! empty( $args['no_pagination'] ) ) {
			$sql  = "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( $table ), $params ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

			$result = array(
				'items'       => array_map( array( __CLASS__, 'format_cookie' ), $rows ?: array() ),
				'total'       => $total,
				'total_pages' => 1,
				'page'        => 1,
				'per_page'    => $total,
			);
			wp_cache_set( $cache_key, $result, 'cookieray' );
			return $result;
		}

		$page     = max( 1, absint( $args['page'] ) );
		$per_page = max( 1, min( 100, absint( $args['per_page'] ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Rows.
		$sql = "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_params = array_merge( array( $table ), $params, array( $per_page, $offset ) );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$result = array(
			'items'       => array_map( array( __CLASS__, 'format_cookie' ), $rows ?: array() ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	public static function get_cookie( $id ) {
		$id        = absint( $id );
		$cache_key = 'cr_cookie_' . $id;
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table  = DB_Manager::table( 'cookies' );
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $row ? self::format_cookie( $row ) : null;
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	/**
	 * Effective category resolution. The frontend reads only the
	 * materialized `category` column; this helper is the single source
	 * of truth for what gets written into that column.
	 */
	public static function compute_effective( $detected, $manual ) {
		$manual   = is_string( $manual ) ? trim( $manual ) : $manual;
		$detected = is_string( $detected ) ? trim( $detected ) : $detected;
		if ( ! empty( $manual ) ) {
			return $manual;
		}
		return ! empty( $detected ) ? $detected : 'uncategorized';
	}

	public static function insert_cookie( $data ) {
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );
		$now   = current_time( 'mysql' );

		$allowed_source_types = array( 'header', 'js', 'manual', 'inferred' );
		$source_type = isset( $data['source_type'] ) && in_array( $data['source_type'], $allowed_source_types, true )
			? $data['source_type']
			: 'inferred';

		$detected = isset( $data['detected_category'] ) && '' !== $data['detected_category']
			? (string) $data['detected_category']
			: ( $data['category'] ?? 'uncategorized' );
		$manual_raw = $data['manual_category'] ?? null;
		$manual     = ( null === $manual_raw || '' === $manual_raw ) ? null : (string) $manual_raw;
		$manual_src = null;
		if ( null !== $manual ) {
			$source_in  = $data['manual_source'] ?? null;
			$manual_src = in_array( $source_in, array( 'admin', 'remembered' ), true ) ? $source_in : 'admin';
		}

		$row = array(
			'name'              => $data['name'],
			'domain'            => isset( $data['domain'] ) ? (string) $data['domain'] : '',
			'path'              => isset( $data['path'] ) ? (string) $data['path'] : '',
			'provider'          => $data['provider'] ?? '',
			'detected_category' => $detected,
			'manual_category'   => $manual,
			'manual_source'     => $manual_src,
			'category'          => self::compute_effective( $detected, $manual ),
			'duration'          => $data['duration'] ?? '',
			'duration_seconds'  => (int) ( $data['duration_seconds'] ?? 0 ),
			'description'       => $data['description'] ?? '',
			'is_regex'          => ! empty( $data['is_regex'] ) ? 1 : 0,
			'auto_detected'     => isset( $data['auto_detected'] ) ? (int) $data['auto_detected'] : 0,
			'is_third_party'    => ! empty( $data['is_third_party'] ) ? 1 : 0,
			'script_pattern'    => $data['script_pattern'] ?? '',
			'source_type'       => $source_type,
			'last_detected_at'  => $data['last_detected_at'] ?? $now,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		$result = $wpdb->insert( $table, $row ); // phpcs:ignore
		if ( false === $result ) {
			return false;
		}
		self::cache_invalidate( 'cr_cookies_changed' );
		return self::get_cookie( $wpdb->insert_id );
	}

	/**
	 * Upsert by stable identity (name, domain, path). Refreshes the
	 * scanner-detected fields on every call without disturbing any
	 * manual decision the admin already recorded.
	 *
	 * Memory-supplied manual_category/manual_source land via INSERT only;
	 * the ON DUPLICATE KEY UPDATE clause uses COALESCE so an existing
	 * manual decision wins over any incoming memory-derived values.
	 *
	 * Returns 'inserted' for newly created rows, 'updated' for rows that
	 * already existed (changed or not), or false on query failure. Callers
	 * that track "new cookies discovered" must distinguish the two so the
	 * scan record's `cookies_new` matches inventory rows actually added.
	 */
	public static function upsert_cookie( $data ) {
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );
		$now   = current_time( 'mysql' );

		$allowed_source_types = array( 'header', 'js', 'manual', 'inferred' );
		$source_type = isset( $data['source_type'] ) && in_array( $data['source_type'], $allowed_source_types, true )
			? $data['source_type']
			: 'inferred';

		$detected = isset( $data['detected_category'] ) && '' !== $data['detected_category']
			? (string) $data['detected_category']
			: 'uncategorized';
		$manual_raw = $data['manual_category'] ?? null;
		$manual     = ( null === $manual_raw || '' === $manual_raw ) ? null : (string) $manual_raw;
		$manual_src = null;
		if ( null !== $manual ) {
			$source_in  = $data['manual_source'] ?? null;
			$manual_src = in_array( $source_in, array( 'admin', 'remembered' ), true ) ? $source_in : 'remembered';
		}
		$effective = self::compute_effective( $detected, $manual );

		$last_detected = $data['last_detected_at'] ?? $now;

		// Use INSERT ... ON DUPLICATE KEY UPDATE keyed on the stable_key
		// UNIQUE index so the scanner can re-run safely without touching
		// manual_category/manual_source on existing rows.
		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"INSERT INTO %i (
				name, domain, path, provider, detected_category, manual_category, manual_source,
				category, duration, duration_seconds, description, is_regex, auto_detected,
				is_third_party, script_pattern, source_type, last_detected_at, created_at, updated_at
			) VALUES (
				%s, %s, %s, %s, %s, %s, %s,
				%s, %s, %d, %s, %d, %d,
				%d, %s, %s, %s, %s, %s
			)
			ON DUPLICATE KEY UPDATE
				detected_category = VALUES(detected_category),
				provider          = VALUES(provider),
				duration          = COALESCE(NULLIF(VALUES(duration), ''), duration),
				duration_seconds  = GREATEST(VALUES(duration_seconds), duration_seconds),
				description       = COALESCE(NULLIF(VALUES(description), ''), description),
				is_regex          = VALUES(is_regex),
				auto_detected     = VALUES(auto_detected),
				is_third_party    = VALUES(is_third_party),
				script_pattern    = COALESCE(NULLIF(VALUES(script_pattern), ''), script_pattern),
				source_type       = VALUES(source_type),
				manual_category   = COALESCE(NULLIF(manual_category, ''), NULLIF(VALUES(manual_category), '')),
				manual_source     = COALESCE(NULLIF(manual_source, ''),   NULLIF(VALUES(manual_source), '')),
				category          = COALESCE(NULLIF(manual_category, ''), NULLIF(VALUES(manual_category), ''), NULLIF(VALUES(detected_category), ''), 'uncategorized'),
				last_detected_at  = VALUES(last_detected_at),
				updated_at        = VALUES(updated_at)",
			$table,
			(string) $data['name'],
			isset( $data['domain'] ) ? (string) $data['domain'] : '',
			isset( $data['path'] ) ? (string) $data['path'] : '',
			(string) ( $data['provider'] ?? '' ),
			$detected,
			null === $manual ? '' : $manual,
			null === $manual_src ? '' : $manual_src,
			$effective,
			(string) ( $data['duration'] ?? '' ),
			(int) ( $data['duration_seconds'] ?? 0 ),
			(string) ( $data['description'] ?? '' ),
			! empty( $data['is_regex'] ) ? 1 : 0,
			isset( $data['auto_detected'] ) ? (int) $data['auto_detected'] : 0,
			! empty( $data['is_third_party'] ) ? 1 : 0,
			(string) ( $data['script_pattern'] ?? '' ),
			$source_type,
			$last_detected,
			$now,
			$now
		);

		// Empty-string values for nullable manual_category/manual_source are
		// converted to true NULLs after the INSERT — VALUES() can't carry
		// NULL through wpdb::prepare() without a wrapper. The ON DUPLICATE
		// COALESCE step still preserves any pre-existing manual decision.
		$result = $wpdb->query( $sql ); // phpcs:ignore
		if ( false === $result ) {
			return false;
		}
		// MySQL with default mysqli flags returns rows_affected = 1 for a
		// fresh INSERT, 2 when ON DUPLICATE KEY UPDATE actually changed at
		// least one column, and 0 when the existing row was identical.
		// Capture immediately — the manual_* normalization UPDATEs below
		// will overwrite $wpdb->rows_affected.
		$status = ( 1 === (int) $wpdb->rows_affected ) ? 'inserted' : 'updated';
		self::cache_invalidate( 'cr_cookies_changed' );
		// If the INSERT just placed empty strings for manual_*, normalize
		// to NULL on the row we just touched so format_cookie sees the
		// correct "no manual decision" state.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE %i SET manual_category = NULL WHERE name = %s AND domain = %s AND path = %s AND manual_category = ''",
			$table,
			(string) $data['name'],
			isset( $data['domain'] ) ? (string) $data['domain'] : '',
			isset( $data['path'] ) ? (string) $data['path'] : ''
		) );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE %i SET manual_source = NULL WHERE name = %s AND domain = %s AND path = %s AND manual_source = ''",
			$table,
			(string) $data['name'],
			isset( $data['domain'] ) ? (string) $data['domain'] : '',
			isset( $data['path'] ) ? (string) $data['path'] : ''
		) );
		return $status;
	}

	public static function update_cookie( $id, $data ) {
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );

		// Read the existing row first so we can recompute the effective
		// category in PHP no matter which subset of fields the caller is
		// touching. Also lets us honor the manual-clear contract: setting
		// manual_category to NULL implies clearing manual_source and
		// recomputing category from detected_category.
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore
		if ( ! $existing ) {
			return false;
		}

		$row = array();
		foreach ( array( 'name', 'domain', 'path', 'provider', 'duration', 'description', 'script_pattern', 'detected_category' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = (string) $data[ $key ];
			}
		}
		if ( array_key_exists( 'duration_seconds', $data ) ) {
			$row['duration_seconds'] = (int) $data['duration_seconds'];
		}
		if ( array_key_exists( 'is_regex', $data ) ) {
			$row['is_regex'] = $data['is_regex'] ? 1 : 0;
		}
		if ( array_key_exists( 'is_third_party', $data ) ) {
			$row['is_third_party'] = $data['is_third_party'] ? 1 : 0;
		}

		// Manual-clear contract: when the caller hands in manual_category,
		// recompute manual_source in lockstep so the row never ends up with
		// a stale source pointing at a NULL category.
		$next_manual_set = array_key_exists( 'manual_category', $data );
		$next_manual     = $existing['manual_category'];
		$next_source     = $existing['manual_source'];
		if ( $next_manual_set ) {
			$incoming = $data['manual_category'];
			if ( null === $incoming || '' === $incoming || 'uncategorized' === $incoming ) {
				$next_manual = null;
				$next_source = null;
			} else {
				$next_manual  = (string) $incoming;
				$source_in    = $data['manual_source'] ?? null;
				$next_source  = in_array( $source_in, array( 'admin', 'remembered' ), true ) ? $source_in : 'admin';
			}
			$row['manual_category'] = $next_manual;
			$row['manual_source']   = $next_source;
		}

		// Always recompute the materialized effective column so admin
		// display and frontend mapping stay in sync regardless of which
		// fields just changed.
		$next_detected = array_key_exists( 'detected_category', $data )
			? (string) $data['detected_category']
			: ( $existing['detected_category'] ?: 'uncategorized' );
		$row['category']   = self::compute_effective( $next_detected, $next_manual );
		$row['updated_at'] = current_time( 'mysql' );

		// Use $wpdb->update which handles NULL via the explicit format
		// array — pass null where the column should be NULLed.
		$formats = array();
		foreach ( $row as $col => $val ) {
			if ( null === $val ) {
				$formats[] = '%s';
			} elseif ( in_array( $col, array( 'duration_seconds', 'is_regex', 'is_third_party', 'auto_detected' ), true ) ) {
				$formats[] = '%d';
			} else {
				$formats[] = '%s';
			}
		}
		$result = $wpdb->update( $table, $row, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore
		if ( false === $result ) {
			return false;
		}
		self::cache_invalidate( 'cr_cookies_changed' );
		wp_cache_delete( 'cr_cookie_' . absint( $id ), 'cookieray' );
		return self::get_cookie( $id );
	}

	public static function delete_cookie( $id ) {
		global $wpdb;
		$table  = DB_Manager::table( 'cookies' );
		$result = (bool) $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore
		if ( $result ) {
			self::cache_invalidate( 'cr_cookies_changed' );
			wp_cache_delete( 'cr_cookie_' . absint( $id ), 'cookieray' );
		}
		return $result;
	}

	public static function delete_cookies( $ids ) {
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );
		$ids   = array_map( 'absint', (array) $ids );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$result = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", array_merge( array( $table ), $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $result ) {
			self::cache_invalidate( 'cr_cookies_changed' );
			foreach ( $ids as $id ) {
				wp_cache_delete( 'cr_cookie_' . $id, 'cookieray' );
			}
		}
		return $result;
	}

	/**
	 * Wipe every inventory row. Powers "Delete All Cookies" — distinct
	 * from "Delete Selected" so admins can reliably reset before a
	 * rescan regardless of pagination.
	 */
	public static function delete_all_cookies() {
		global $wpdb;
		$table  = DB_Manager::table( 'cookies' );
		$result = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::cache_invalidate( 'cr_cookies_changed' );
		return $result;
	}

	/**
	 * Delete every row whose materialized effective category equals the
	 * supplied value. Used by "Delete All Uncategorized" so admins can
	 * clear the entire backlog rather than just the visible page.
	 */
	public static function delete_cookies_by_effective_category( $category ) {
		global $wpdb;
		$category = sanitize_key( (string) $category );
		if ( '' === $category ) {
			return 0;
		}
		$table  = DB_Manager::table( 'cookies' );
		$result = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE category = %s', $table, $category ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $result ) {
			self::cache_invalidate( 'cr_cookies_changed' );
		}
		return $result;
	}

	/**
	 * Reset manual-category state on every inventory row so the
	 * effective category falls back to the scanner-detected value.
	 * Powers "Clear Saved Cookie Classifications" — paired with
	 * Classifications::clear_all() so admin display and frontend
	 * mapping are immediately consistent without requiring a rescan.
	 */
	public static function clear_all_manual_categories() {
		global $wpdb;
		$table  = DB_Manager::table( 'cookies' );
		$result = (int) $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE %i
			   SET manual_category = NULL,
			       manual_source   = NULL,
			       category        = COALESCE(NULLIF(detected_category, ''), 'uncategorized'),
			       updated_at      = NOW()",
			$table
		) );
		self::cache_invalidate( 'cr_cookies_changed' );
		return $result;
	}

	public static function count_cookies_by_category() {
		$cache_key = 'cr_cookies_by_cat_' . self::cache_last_changed( 'cr_cookies_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = DB_Manager::table( 'cookies' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT category, COUNT(*) as total FROM %i GROUP BY category', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$out   = array(
			'necessary'     => 0,
			'analytical'    => 0,
			'functional'    => 0,
			'marketing'     => 0,
			'uncategorized' => 0,
		);
		foreach ( $rows ?: array() as $r ) {
			$out[ $r['category'] ] = (int) $r['total'];
		}
		$out['total'] = array_sum( $out );
		wp_cache_set( $cache_key, $out, 'cookieray' );
		return $out;
	}

	public static function format_cookie( $row ) {
		if ( ! $row ) {
			return null;
		}
		$detected = $row['detected_category'] ?? ( $row['category'] ?? 'uncategorized' );
		$manual   = isset( $row['manual_category'] ) && '' !== $row['manual_category'] ? $row['manual_category'] : null;
		$source   = isset( $row['manual_source'] ) && '' !== $row['manual_source'] ? $row['manual_source'] : null;

		return array(
			'id'                => (int) $row['id'],
			'name'              => $row['name'],
			'domain'            => (string) ( $row['domain'] ?? '' ),
			'path'              => (string) ( $row['path'] ?? '' ),
			'provider'          => $row['provider'],
			'category'          => $row['category'], // effective — source of truth for admin UI + frontend
			'detected_category' => (string) $detected,
			'manual_category'   => $manual,
			'manual_source'     => $source,
			'duration'          => $row['duration'],
			'duration_seconds'  => (int) $row['duration_seconds'],
			'description'       => $row['description'],
			'is_regex'          => (bool) $row['is_regex'],
			'auto_detected'     => (bool) $row['auto_detected'],
			'is_third_party'    => (bool) ( $row['is_third_party'] ?? false ),
			'script_pattern'    => $row['script_pattern'],
			'source_type'       => $row['source_type'] ?? 'inferred',
			'last_detected_at'  => $row['last_detected_at'] ?? null,
			'created_at'        => $row['created_at'],
			'updated_at'        => $row['updated_at'],
		);
	}

	/* --------------------------------------------------------------------
	 *  Consent logs
	 * ------------------------------------------------------------------ */

	public static function insert_consent_log( $data ) {
		global $wpdb;
		$table = DB_Manager::table( 'consent_logs' );
		$row   = array(
			'visitor_hash'   => $data['visitor_hash'],
			'consent_id'     => $data['consent_id'],
			'status'         => $data['status'],
			'categories'     => wp_json_encode( $data['categories'] ?? array() ),
			'ip_country'     => $data['ip_country'] ?? '',
			'user_agent'     => substr( $data['user_agent'] ?? '', 0, 255 ),
			'page_url'       => substr( $data['page_url'] ?? '', 0, 500 ),
			'banner_version' => substr( $data['banner_version'] ?? '', 0, 32 ),
			'created_at'     => current_time( 'mysql' ),
		);
		$result = $wpdb->insert( $table, $row ); // phpcs:ignore
		if ( false !== $result ) {
			self::cache_invalidate( 'cr_consent_changed' );
		}
		return $result;
	}

	public static function count_consents( $since_days = null ) {
		$cache_key = 'cr_consent_count_' . (int) $since_days . '_' . self::cache_last_changed( 'cr_consent_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = DB_Manager::table( 'consent_logs' );
		if ( $since_days ) {
			$since  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$since_days} days" ) );
			$result = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s', $table, $since ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		} else {
			$result = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		}
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	public static function consent_status_breakdown( $since_days = 30 ) {
		$cache_key = 'cr_consent_status_' . (int) $since_days . '_' . self::cache_last_changed( 'cr_consent_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = DB_Manager::table( 'consent_logs' );
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$since_days} days" ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) as total FROM %i WHERE created_at >= %s GROUP BY status', $table, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$out   = array(
			'accepted_all' => 0,
			'declined'     => 0,
			'custom'       => 0,
		);
		foreach ( $rows ?: array() as $r ) {
			$out[ $r['status'] ] = (int) $r['total'];
		}
		wp_cache_set( $cache_key, $out, 'cookieray' );
		return $out;
	}

	public static function consents_daily( $days = 10 ) {
		$cache_key = 'cr_consent_daily_' . (int) $days . '_' . self::cache_last_changed( 'cr_consent_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table = DB_Manager::table( 'consent_logs' );
		$since = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT DATE(created_at) as day, COUNT(*) as total FROM %i WHERE DATE(created_at) >= %s GROUP BY DATE(created_at)', $table, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

		$by_day = array();
		foreach ( $rows ?: array() as $r ) {
			$by_day[ $r['day'] ] = (int) $r['total'];
		}

		$out = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$day   = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$out[] = array(
				'date'  => $day,
				'count' => $by_day[ $day ] ?? 0,
			);
		}
		wp_cache_set( $cache_key, $out, 'cookieray' );
		return $out;
	}

	public static function purge_consent_logs_older_than( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$table = DB_Manager::table( 'consent_logs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// Direct DELETE is required to purge expired logs; caching does not apply to write operations.
		$result = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff )
		);
		if ( $result ) {
			self::cache_invalidate( 'cr_consent_changed' );
		}
		return $result;
	}

	public static function recent_consents( $limit = 10 ) {
		$limit     = absint( $limit );
		$cache_key = 'cr_recent_consents_' . $limit . '_' . self::cache_last_changed( 'cr_consent_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table  = DB_Manager::table( 'consent_logs' );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, status, ip_country, page_url, created_at FROM %i ORDER BY created_at DESC LIMIT %d', $table, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $rows ?: array();
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	public static function get_consent_logs( $args = array() ) {
		global $wpdb;
		$table = DB_Manager::table( 'consent_logs' );

		$defaults = array(
			'page'     => 1,
			'per_page' => 20,
			'search'   => '',
			'status'   => '',
			'from'     => '',
			'to'       => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$term      = $args['search'];
			$like      = '%' . $wpdb->esc_like( $term ) . '%';
			// Strip display prefix (usr_ / usr) so "usr_5ecdccc2" matches visitor_hash.
			$hash_term = preg_replace( '/^usr_?/i', '', $term );
			$hash_like = '%' . $wpdb->esc_like( $hash_term ) . '%';
			$where[]   = '(consent_id LIKE %s OR visitor_hash LIKE %s OR page_url LIKE %s)';
			$params[]  = $like;
			$params[]  = $hash_like;
			$params[]  = $like;
		}

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $args['from'] ) );
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $args['to'] ) );
		}

		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, absint( $args['page'] ) );
		$per_page  = max( 1, min( 500, absint( $args['per_page'] ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$cache_key = 'cr_consent_logs_' . md5( wp_json_encode( $args ) . self::cache_last_changed( 'cr_consent_changed' ) );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}

		$count_sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$sql        = "SELECT id, visitor_hash, consent_id, status, categories, ip_country, user_agent, page_url, created_at FROM %i WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_params = array_merge( array( $table ), $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$result = array(
			'items'       => array_map( array( __CLASS__, 'format_consent_log' ), $rows ?: array() ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	public static function format_consent_log( $row ) {
		if ( ! $row ) {
			return null;
		}
		$categories = array();
		if ( ! empty( $row['categories'] ) ) {
			$decoded = json_decode( $row['categories'], true );
			if ( is_array( $decoded ) ) {
				$categories = $decoded;
			}
		}

		// Mask visitor hash to show anonymized short id.
		$hash    = (string) $row['visitor_hash'];
		$user_id = $hash ? 'usr_' . substr( $hash, 0, 8 ) : '';

		// Mask last two octets of the stored page URL's host-derived IP is not stored; mask user-agent-derived ip via ip_country only.
		return array(
			'id'            => (int) $row['id'],
			'user_id'       => $user_id,
			'consent_id'    => $row['consent_id'],
			'status'        => $row['status'],
			'categories'    => $categories,
			'ip_masked'     => self::mask_country_ip( $row['ip_country'] ),
			'ip_country'    => $row['ip_country'],
			'user_agent_hash' => $row['user_agent'],
			'page_url'      => $row['page_url'],
			'created_at'    => $row['created_at'],
		);
	}

	private static function mask_country_ip( $country ) {
		// We don't store raw IP — show a masked placeholder including country if known.
		$country = $country ? strtoupper( $country ) : '';
		return $country ? $country . '.***.***.***' : '***.***.***.***';
	}

	/* --------------------------------------------------------------------
	 *  Scans listing
	 * ------------------------------------------------------------------ */

	public static function get_scans( $args = array() ) {
		global $wpdb;
		$table = DB_Manager::table( 'scans' );

		$defaults = array(
			'page'     => 1,
			'per_page' => 20,
			'status'   => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) && 'all' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, absint( $args['page'] ) );
		$per_page  = max( 1, min( 200, absint( $args['per_page'] ) ) );
		$offset    = ( $page - 1 ) * $per_page;

		$cache_key = 'cr_scans_' . md5( wp_json_encode( $args ) . self::cache_last_changed( 'cr_scans_changed' ) );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}

		$count_sql  = "SELECT COUNT(*) FROM %i WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total      = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, array_merge( array( $table ), $params ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql        = "SELECT * FROM %i WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$all_params = array_merge( array( $table ), $params, array( $per_page, $offset ) );
		$rows       = $wpdb->get_results( $wpdb->prepare( $sql, $all_params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		$result = array(
			'items'       => array_map( array( __CLASS__, 'format_scan' ), $rows ?: array() ),
			'total'       => $total,
			'total_pages' => (int) ceil( $total / $per_page ),
			'page'        => $page,
			'per_page'    => $per_page,
		);
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	public static function format_scan( $row ) {
		if ( ! $row ) {
			return null;
		}
		$duration = null;
		if ( ! empty( $row['started_at'] ) && ! empty( $row['completed_at'] ) ) {
			$duration = max( 0, strtotime( $row['completed_at'] ) - strtotime( $row['started_at'] ) );
		}
		return array(
			'id'            => (int) $row['id'],
			'status'        => $row['status'],
			'trigger'       => $row['scan_trigger'] ?? 'manual',
			'cookies_found' => (int) $row['cookies_found'],
			'cookies_new'   => (int) $row['cookies_new'],
			'pages_scanned' => (int) $row['pages_scanned'],
			'started_at'    => $row['started_at'],
			'completed_at'  => $row['completed_at'],
			'duration'      => $duration,
			'error_log'     => $row['error_log'],
			'notes'         => $row['notes'] ?? '',
		);
	}

	/* --------------------------------------------------------------------
	 *  Scans
	 * ------------------------------------------------------------------ */

	public static function insert_scan( $data = array() ) {
		global $wpdb;
		$table = DB_Manager::table( 'scans' );
		$row   = array(
			'status'       => $data['status'] ?? 'pending',
			'scan_trigger' => in_array( $data['trigger'] ?? '', array( 'manual', 'automated' ), true )
				? $data['trigger']
				: 'manual',
			'started_at'   => current_time( 'mysql' ),
		);
		$wpdb->insert( $table, $row ); // phpcs:ignore
		$id = (int) $wpdb->insert_id;
		self::cache_invalidate( 'cr_scans_changed' );
		return $id;
	}

	public static function update_scan( $id, $data ) {
		global $wpdb;
		$table    = DB_Manager::table( 'scans' );
		$int_cols = array( 'cookies_found', 'cookies_new', 'pages_scanned' );
		$formats  = array();
		foreach ( $data as $col => $val ) {
			$formats[] = in_array( $col, $int_cols, true ) ? '%d' : '%s';
		}
		$result = $wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::cache_invalidate( 'cr_scans_changed' );
		wp_cache_delete( 'cr_scan_' . absint( $id ), 'cookieray' );
		return $result;
	}

	public static function get_scan( $id ) {
		$id        = absint( $id );
		$cache_key = 'cr_scan_' . $id;
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table  = DB_Manager::table( 'scans' );
		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}

	/**
	 * Recompute cookies_found and cookies_new from the inventory state.
	 *
	 * Per-phase counters (server scan, deep scan cron, client iframe scan)
	 * cannot reliably accumulate because the phases run in different
	 * processes and one phase's "inserted" cookie can become another
	 * phase's "updated" depending on order. Querying inventory directly
	 * against the scan's started_at gives a single source of truth that
	 * always matches what the admin sees in the Cookie Inventory.
	 *
	 *  - cookies_found = rows whose last_detected_at is within the scan
	 *    window (touched during this scan, regardless of phase).
	 *  - cookies_new   = rows whose created_at is within the scan window
	 *    (newly added to inventory during this scan).
	 *
	 * Called at the end of every scan phase so the badge stays consistent
	 * even if a later phase deletes/inserts rows.
	 */
	public static function recompute_scan_counts( $scan_id ) {
		global $wpdb;
		$scan_id = (int) $scan_id;
		if ( ! $scan_id ) {
			return;
		}
		$scan = self::get_scan( $scan_id );
		if ( ! $scan || empty( $scan['started_at'] ) ) {
			return;
		}
		$cookies_table = DB_Manager::table( 'cookies' );
		$started       = $scan['started_at'];

		$found = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'SELECT COUNT(*) FROM %i WHERE last_detected_at >= %s',
			$cookies_table,
			$started
		) );
		$new = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			'SELECT COUNT(*) FROM %i WHERE created_at >= %s',
			$cookies_table,
			$started
		) );

		self::update_scan(
			$scan_id,
			array(
				'cookies_found' => $found,
				'cookies_new'   => $new,
			)
		);
	}

	public static function latest_scan() {
		$cache_key = 'cr_latest_scan_' . self::cache_last_changed( 'cr_scans_changed' );
		$cached    = wp_cache_get( $cache_key, 'cookieray' );
		if ( false !== $cached ) {
			return $cached;
		}
		global $wpdb;
		$table  = DB_Manager::table( 'scans' );
		$result = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_set( $cache_key, $result, 'cookieray' );
		return $result;
	}
}

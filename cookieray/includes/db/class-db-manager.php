<?php
/**
 * Database manager — creates custom tables via dbDelta.
 *
 * @package CookieRay
 */

namespace CookieRay\DB;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DB_Manager {

	const DB_VERSION     = '1.0.0';
	const VERSION_OPTION = 'cookieray_db_version';

	/**
	 * Run install() if the stored DB version is older than current.
	 * Safe to call on every admin_init — dbDelta is idempotent.
	 */
	public static function maybe_upgrade() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'cookieray_cookies';
		$exists     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists !== $table_name ) {
			self::install();
			update_option( self::VERSION_OPTION, self::DB_VERSION );
			return;
		}

	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix;

		$cookies = "CREATE TABLE {$prefix}cookieray_cookies (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			domain VARCHAR(255) NOT NULL DEFAULT '',
			path VARCHAR(255) NOT NULL DEFAULT '',
			provider VARCHAR(255) DEFAULT '' NOT NULL,
			detected_category VARCHAR(32) NOT NULL DEFAULT 'uncategorized',
			manual_category VARCHAR(32) NULL DEFAULT NULL,
			manual_source VARCHAR(16) NULL DEFAULT NULL,
			category VARCHAR(32) DEFAULT 'uncategorized' NOT NULL,
			duration VARCHAR(100) DEFAULT '' NOT NULL,
			duration_seconds INT UNSIGNED DEFAULT 0 NOT NULL,
			description TEXT NULL,
			is_regex TINYINT(1) DEFAULT 0 NOT NULL,
			auto_detected TINYINT(1) DEFAULT 1 NOT NULL,
			is_third_party TINYINT(1) DEFAULT 0 NOT NULL,
			script_pattern TEXT NULL,
			source_type VARCHAR(16) NOT NULL DEFAULT 'inferred',
			last_detected_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			updated_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY category (category),
			UNIQUE KEY stable_key (name(100), domain(100), path(50))
		) {$charset_collate};";

		$consent_logs = "CREATE TABLE {$prefix}cookieray_consent_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_hash CHAR(64) NOT NULL,
			consent_id CHAR(36) NOT NULL,
			status VARCHAR(32) NOT NULL,
			categories TEXT NULL,
			ip_country CHAR(2) DEFAULT '' NOT NULL,
			user_agent VARCHAR(255) DEFAULT '' NOT NULL,
			page_url VARCHAR(500) DEFAULT '' NOT NULL,
			banner_version VARCHAR(32) DEFAULT '' NOT NULL,
			created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY visitor_hash (visitor_hash),
			KEY consent_id (consent_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		$scans = "CREATE TABLE {$prefix}cookieray_scans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			status VARCHAR(32) DEFAULT 'pending' NOT NULL,
			scan_trigger VARCHAR(16) DEFAULT 'manual' NOT NULL,
			cookies_found INT UNSIGNED DEFAULT 0 NOT NULL,
			cookies_new INT UNSIGNED DEFAULT 0 NOT NULL,
			pages_scanned INT UNSIGNED DEFAULT 0 NOT NULL,
			started_at DATETIME NULL,
			completed_at DATETIME NULL,
			error_log TEXT NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		$third_party_scripts = "CREATE TABLE {$prefix}cookieray_third_party_scripts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			scan_id BIGINT UNSIGNED NOT NULL,
			page_url VARCHAR(2048) NOT NULL DEFAULT '',
			script_url VARCHAR(2048) NOT NULL DEFAULT '',
			provider VARCHAR(128) NOT NULL DEFAULT '',
			category VARCHAR(64) NOT NULL DEFAULT 'uncategorized',
			type VARCHAR(32) NOT NULL DEFAULT 'script',
			risk_level VARCHAR(16) NOT NULL DEFAULT 'medium',
			detected_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY risk_level (risk_level)
		) {$charset_collate};";

		dbDelta( $cookies );
		dbDelta( $consent_logs );
		dbDelta( $scans );
		dbDelta( $third_party_scripts );
	}

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'cookieray_' . $name;
	}
}

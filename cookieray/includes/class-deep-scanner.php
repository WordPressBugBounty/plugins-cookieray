<?php
/**
 * Deep Scanner — crawls all pages and detects third-party trackers,
 * pixels, iframes, and inline scripts.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\DB\DB_Queries;
use CookieRay\DB\DB_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deep_Scanner {

	const MAX_DEEP_URLS = 50;
	const URL_TIMEOUT   = 6;

	/**
	 * In-memory cache of the tracker database.
	 *
	 * @var array|null
	 */
	private static $tracker_db = null;

	/**
	 * Standalone entry point — called by WP-Cron via REST controller.
	 * Creates/updates its own scan record.
	 *
	 * @param int $scan_id The scan row ID.
	 */
	public static function run( int $scan_id ): void {
		if ( ! $scan_id ) {
			return;
		}

		@set_time_limit( 0 ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore

		DB_Queries::update_scan(
			$scan_id,
			array(
				'status'     => 'running',
				'started_at' => current_time( 'mysql' ),
				'scan_type'  => 'deep',
			)
		);

		$urls         = self::collect_urls();
		$all_findings = array();
		$seen_cookies = array();
		$pages        = 0;
		$errors       = array();

		$scanner = Scanner::instance();

		foreach ( $urls as $url ) {
			try {
				$response = Scanner::fetch_uncached(
					$url,
					array(
						'timeout'    => self::URL_TIMEOUT,
						'user-agent' => 'CookieRay/' . COOKIERAY_VERSION . ' (DeepScanner)',
					)
				);

				if ( is_wp_error( $response ) ) {
					$errors[] = $url . ': ' . $response->get_error_message();
					continue;
				}

				$html = wp_remote_retrieve_body( $response );
				$pages++;

				$page_findings = self::analyse_page( $html, $url );
				$all_findings  = array_merge( $all_findings, $page_findings );

				$set_cookies = wp_remote_retrieve_header( $response, 'set-cookie' );
				if ( ! empty( $set_cookies ) ) {
					$list      = is_array( $set_cookies ) ? $set_cookies : array( $set_cookies );
					$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
					foreach ( $list as $raw ) {
						$attrs = Scanner::parse_cookie_attrs( $raw );
						if ( '' === $attrs['name'] ) {
							continue;
						}
						// First-party cookies stay host-only (domain='') so
						// they never split into duplicate rows when WP_HTTP
						// populates the URL host as the cookie domain.
						$attrs_domain   = strtolower( $attrs['domain'] );
						if ( '' === $attrs_domain || $attrs_domain === $site_host ) {
							$attrs['domain'] = '';
						}
						$key = strtolower( $attrs['name'] ) . '|' . $attrs['domain'] . '|' . $attrs['path'];
						if ( isset( $seen_cookies[ $key ] ) ) {
							continue;
						}
						$seen_cookies[ $key ] = true;
						$meta = $scanner->categorize( $attrs['name'] );
						$row  = Scanner::build_scanner_row(
							$attrs['name'],
							$attrs['domain'],
							$attrs['path'],
							array(
								'provider'         => $meta['provider'] ?? 'Set-Cookie header',
								'detected_category' => $meta['category'] ?? 'uncategorized',
								'duration'         => $meta['duration'] ?? '',
								'duration_seconds' => $meta['duration_seconds'] ?? 0,
								'description'      => $meta['description'] ?? '',
								'auto_detected'    => 1,
								'script_pattern'   => 'Deep scan: ' . $url,
								'source_type'      => 'header',
								'is_regex'         => ! empty( $meta['is_regex'] ),
							)
						);
						DB_Queries::upsert_cookie( $row );
					}
				}

				if ( $html ) {
					self::infer_cookies_from_page( $html, $scanner, $seen_cookies );
					self::infer_sbjs_cookies_if_present( $html, $scanner, $seen_cookies );
				}
			} catch ( \Throwable $e ) {
				$errors[] = $url . ': ' . $e->getMessage();
			}
		}

		self::save_findings( $scan_id, $all_findings );

		// Trackers (scripts/pixels/iframes) are surfaced separately in the
		// scan-results endpoint — they are not cookies and must not be added
		// into `cookies_found`. Cookie counts are reconciled below against
		// the inventory directly so the scan badge always matches the
		// visible Cookie Inventory regardless of which phase last touched a
		// row.
		DB_Queries::update_scan(
			$scan_id,
			array(
				'status'        => 'completed',
				'pages_scanned' => $pages,
				'completed_at'  => current_time( 'mysql' ),
				'error_log'     => $errors ? implode( "\n", $errors ) : null,
			)
		);
		DB_Queries::recompute_scan_counts( $scan_id );

		delete_transient( 'cookieray_dashboard_stats' );
		do_action( 'cookieray_deep_scan_completed', $scan_id );
	}

	/**
	 * Embedded entry point — called automatically after a basic scan.
	 * Only detects trackers and saves findings; does NOT touch scan status.
	 *
	 * @param int $scan_id The parent scan row ID.
	 */
	public static function run_embedded( int $scan_id ): void {
		if ( ! $scan_id ) {
			return;
		}

		@set_time_limit( 0 ); // phpcs:ignore
		@ignore_user_abort( true ); // phpcs:ignore

		$urls         = self::collect_urls();
		$all_findings = array();
		$seen_cookies = array();
		$scanner      = Scanner::instance();

		foreach ( $urls as $url ) {
			try {
				$response = Scanner::fetch_uncached(
					$url,
					array(
						'timeout'    => self::URL_TIMEOUT,
						'user-agent' => 'CookieRay/' . COOKIERAY_VERSION . ' (DeepScanner)',
					)
				);

				if ( is_wp_error( $response ) ) {
					continue;
				}

				$html = wp_remote_retrieve_body( $response );
				if ( $html ) {
					$page_findings = self::analyse_page( $html, $url );
					$all_findings  = array_merge( $all_findings, $page_findings );

					// Server-side fetch never executes JS, so client-set
					// trackers (`_ga`, `_gid`, etc. set by GTM/GA after load)
					// never appear in Set-Cookie headers. Match dictionary
					// `setter_patterns` against actual external script/pixel/
					// iframe URLs and classified inline tracker bodies — not
					// raw HTML — so a stray `google.com` mention in a link or
					// comment cannot trigger phantom inventory rows.
					self::infer_cookies_from_page( $html, $scanner, $seen_cookies );
					self::infer_sbjs_cookies_if_present( $html, $scanner, $seen_cookies );
				}
			} catch ( \Throwable $e ) {
				// Silent — don't affect the parent scan record.
			}
		}

		self::save_findings( $scan_id, $all_findings );

		// Reconcile counts against inventory. Per-phase increments cannot
		// be trusted because the iframe-driven client scan may run before
		// or after this cron worker, and either order produced wrong totals
		// previously (badge "2 New" while inventory grew to 11).
		DB_Queries::recompute_scan_counts( $scan_id );
		// Async phase landed — flip to 'completed' so the UI polling loop
		// stops waiting. Only flip 'processing' so 'failed' states stick.
		$current = DB_Queries::get_scan( $scan_id );
		if ( $current && 'processing' === ( $current['status'] ?? '' ) ) {
			DB_Queries::update_scan( $scan_id, array( 'status' => 'completed' ) );
		}

		delete_transient( 'cookieray_dashboard_stats' );
		do_action( 'cookieray_deep_scan_completed', $scan_id );
	}

	/**
	 * Collect up to MAX_DEEP_URLS from sitemap + WP_Query.
	 */
	private static function collect_urls(): array {
		$urls = array();

		$sitemap_urls = self::parse_sitemap();
		if ( count( $sitemap_urls ) >= 5 ) {
			$urls = $sitemap_urls;
		}

		if ( count( $urls ) < 5 ) {
			$urls  = array( home_url( '/' ) );
			$posts = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => 'publish',
					'posts_per_page' => self::MAX_DEEP_URLS - 1,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);
			foreach ( $posts as $post ) {
				$urls[] = get_permalink( $post );
			}
		}

		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$urls      = array_filter(
			$urls,
			function ( $url ) use ( $home_host ) {
				$host = wp_parse_url( $url, PHP_URL_HOST );
				return $host && $host === $home_host;
			}
		);

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return array_slice( $urls, 0, self::MAX_DEEP_URLS );
	}

	private static function parse_sitemap(): array {
		$urls = array();

		$response = Scanner::fetch_uncached(
			home_url( '/sitemap.xml' ),
			array(
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $urls;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $urls;
		}

		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		if ( false !== $xml ) {
			foreach ( $xml->url as $url_node ) {
				if ( isset( $url_node->loc ) ) {
					$urls[] = (string) $url_node->loc;
				}
			}
		} else {
			if ( preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/i', $body, $matches ) ) {
				$urls = $matches[1];
			}
		}
		libxml_clear_errors();

		return $urls;
	}

	private static function analyse_page( string $html, string $page_url ): array {
		$findings = array();

		// 1. External <script src="..."> tags.
		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				if ( ! self::is_external( $url ) ) {
					continue;
				}
				$match = self::match_tracker( $url, 'script' );
				if ( $match ) {
					$findings[] = array_merge(
						$match,
						array(
							'script_url' => $url,
							'type'       => 'script',
							'page_url'   => $page_url,
						)
					);
				}
			}
		}

		// 2. Tracking pixels.
		if ( preg_match_all( '/<img[^>]+>/i', $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				if ( ! preg_match( '/width=["\']1["\']|height=["\']1["\']|display\s*:\s*none/i', $tag ) ) {
					continue;
				}
				if ( ! preg_match( '/src=["\']([^"\']+)["\']/', $tag, $src ) ) {
					continue;
				}
				if ( empty( $src[1] ) || ! self::is_external( $src[1] ) ) {
					continue;
				}
				$match = self::match_tracker( $src[1], 'pixel' );
				if ( $match ) {
					$findings[] = array_merge(
						$match,
						array(
							'script_url' => $src[1],
							'type'       => 'pixel',
							'page_url'   => $page_url,
						)
					);
				}
			}
		}

		// 3. <iframe> trackers.
		if ( preg_match_all( '/<iframe[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				if ( ! self::is_external( $url ) ) {
					continue;
				}
				$match = self::match_tracker( $url, 'iframe' );
				if ( $match ) {
					$findings[] = array_merge(
						$match,
						array(
							'script_url' => $url,
							'type'       => 'iframe',
							'page_url'   => $page_url,
						)
					);
				}
			}
		}

		// 4. <noscript> pixel detection.
		if ( preg_match_all( '/<noscript[^>]*>(.*?)<\/noscript>/is', $html, $matches ) ) {
			foreach ( $matches[1] as $noscript_content ) {
				if ( preg_match_all( '/<img[^>]+>/i', $noscript_content, $img_matches ) ) {
					foreach ( $img_matches[0] as $tag ) {
						if ( ! preg_match( '/width=["\']1["\']|height=["\']1["\']|display\s*:\s*none/i', $tag ) ) {
							continue;
						}
						if ( ! preg_match( '/src=["\']([^"\']+)["\']/', $tag, $src ) ) {
							continue;
						}
						if ( empty( $src[1] ) || ! self::is_external( $src[1] ) ) {
							continue;
						}
						$match = self::match_tracker( $src[1], 'pixel' );
						if ( $match ) {
							$findings[] = array_merge(
								$match,
								array(
									'script_url' => $src[1],
									'type'       => 'pixel',
									'page_url'   => $page_url,
								)
							);
						}
					}
				}
			}
		}

		// 5. Inline script pattern scan.
		if ( preg_match_all( '/(<script(?![^>]*src)[^>]*>)(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				// Skip CookieRay's own injected scripts — they contain block-list patterns
				// as strings, not actual tracker code, which causes false positives.
				if ( preg_match( '/id=["\']cookieray-/i', $m[1] ) ) {
					continue;
				}
				$content = trim( $m[2] );
				if ( empty( $content ) ) {
					continue;
				}
				$match = self::match_inline( $content );
				if ( $match ) {
					$findings[] = array_merge(
						$match,
						array(
							'script_url' => '(inline)',
							'type'       => 'inline',
							'page_url'   => $page_url,
						)
					);
				}
			}
		}

		return $findings;
	}

	private static function is_external( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host !== $home_host;
	}

	private static function match_tracker( string $url, string $type = 'script' ): ?array {
		$db = self::get_tracker_db();
		foreach ( $db as $entry ) {
			if ( self::url_matches_domain_pattern( $url, $entry['domain_pattern'] ) ) {
				return array(
					'provider'    => $entry['provider'],
					'category'    => $entry['category'],
					'risk_level'  => $entry['risk_level'],
					'description' => $entry['description'] ?? '',
				);
			}
		}
		return null;
	}

	/**
	 * Domain-aware match: pattern matches host (or host+path) of URL, not
	 * an arbitrary substring. Prevents `/img/notgoogletagmanager.com/x.jpg`
	 * being flagged as Google Tag Manager.
	 *
	 * Pattern with no slash → host substring match only.
	 * Pattern with slash    → host+path substring match (preserves entries
	 *                         like `google.com/pagead`).
	 */
	private static function url_matches_domain_pattern( string $url, string $pattern ): bool {
		if ( '' === $pattern ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		if ( false === strpos( $pattern, '/' ) ) {
			return false !== stripos( $host, $pattern );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return false !== stripos( $host . $path, $pattern );
	}

	/**
	 * Whether HTML suggests Source Buster JS is embedded (script URL, inline, or minified identifiers).
	 */
	private static function html_signals_sourcebuster( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}
		$lower = strtolower( $html );
		if ( false !== strpos( $lower, 'sourcebuster' ) || false !== strpos( $lower, 'sbjs.min' ) ) {
			return true;
		}
		if ( preg_match( '#[\'"]([^\'"]*(?:sourcebuster|/sbjs)[^\'"]*\\.js[^\'"]*)[\'"]#i', $html ) ) {
			return true;
		}
		// Bundled / merged files: library code references these cookie names.
		if ( preg_match( '/\bsbjs_(?:migrations|current_add|first_add|current|first|udata|session)\b/', $html ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Source Buster sets standard sbjs_* cookies only in the browser; they are absent from
	 * Set-Cookie on server fetches. When the library is detected in HTML, add the usual names.
	 *
	 * @param string  $html         Page HTML.
	 * @param Scanner $scanner      Scanner instance.
	 * @param array   $seen_cookies Dedup map keyed like Scanner "name|domain|path".
	 * @return array{found:int,inserted:int} `found` counts unique stable_keys observed this call;
	 *         `inserted` counts rows actually inserted into the inventory.
	 */
	private static function infer_sbjs_cookies_if_present( string $html, Scanner $scanner, array &$seen_cookies ): array {
		if ( ! self::html_signals_sourcebuster( $html ) ) {
			return array( 'found' => 0, 'inserted' => 0 );
		}
		$names = array(
			'sbjs_migrations',
			'sbjs_current_add',
			'sbjs_first_add',
			'sbjs_current',
			'sbjs_first',
			'sbjs_udata',
			'sbjs_session',
		);
		$found    = 0;
		$inserted = 0;
		foreach ( $names as $name ) {
			$key = strtolower( $name ) . '||/';
			if ( isset( $seen_cookies[ $key ] ) ) {
				continue;
			}
			$seen_cookies[ $key ] = true;
			$found++;
			$meta = $scanner->categorize( $name, 'sourcebuster sbjs' );
			$row  = Scanner::build_scanner_row(
				$name,
				'',
				'/',
				array(
					'provider'          => $meta['provider'] ?? __( 'Source Buster JS', 'cookieray' ),
					'detected_category' => $meta['category'] ?? 'analytical',
					'duration'          => $meta['duration'] ?? '',
					'duration_seconds'  => (int) ( $meta['duration_seconds'] ?? 0 ),
					'description'       => $meta['description'] ?? '',
					'auto_detected'     => 1,
					'is_third_party'    => ! empty( $meta['is_third_party'] ),
					'script_pattern'    => __( 'Inferred: Source Buster JS detected in page HTML', 'cookieray' ),
					'source_type'       => 'inferred',
					'is_regex'          => ! empty( $meta['is_regex'] ),
				)
			);
			if ( 'inserted' === DB_Queries::upsert_cookie( $row ) ) {
				$inserted++;
			}
		}
		return array( 'found' => $found, 'inserted' => $inserted );
	}

	/**
	 * Infer cookies set by client-side trackers without executing JS.
	 *
	 * Walks the dictionary's `setter_patterns` against the URLs of external
	 * <script>, <img> (1x1 pixel), <iframe> tags and the bodies of inline
	 * scripts that `match_inline()` already classified as known tracker code.
	 * Matching against raw HTML (the previous approach) produced massive false
	 * positives because patterns like `google.com` or `googletagmanager.com`
	 * caught any link, comment, or unrelated mention.
	 *
	 * Returns count of rows upserted.
	 *
	 * @param string  $html         Page HTML.
	 * @param Scanner $scanner      Scanner instance for dictionary access.
	 * @param array   $seen_cookies By-ref dedup map keyed by stable_key.
	 */
	private static function infer_cookies_from_page( string $html, Scanner $scanner, array &$seen_cookies ): array {
		$candidates = array();

		if ( preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $src ) {
				if ( '' !== $src ) {
					$candidates[] = $src;
				}
			}
		}
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $src ) {
				if ( '' !== $src ) {
					$candidates[] = $src;
				}
			}
		}
		if ( preg_match_all( '/<iframe[^>]+src=["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $src ) {
				if ( '' !== $src ) {
					$candidates[] = $src;
				}
			}
		}

		// Inline script bodies — only ones already classified as a known
		// tracker by `match_inline()`. Prevents arbitrary inline JS that
		// happens to mention a tracker domain (e.g. a string literal in a
		// CMS plugin) from triggering setter-pattern matches.
		if ( preg_match_all( '/(<script(?![^>]*src)[^>]*>)(.*?)<\/script>/is', $html, $sm, PREG_SET_ORDER ) ) {
			foreach ( $sm as $bm ) {
				if ( preg_match( '/id=["\']cookieray-/i', $bm[1] ) ) {
					continue;
				}
				$content = trim( $bm[2] );
				if ( '' === $content ) {
					continue;
				}
				if ( self::match_inline( $content ) ) {
					$candidates[] = $content;
				}
			}
		}

		if ( empty( $candidates ) ) {
			return array( 'found' => 0, 'inserted' => 0 );
		}

		$found    = 0;
		$inserted = 0;
		foreach ( $scanner->get_dictionary() as $entry ) {
			if ( empty( $entry['setter_patterns'] ) ) {
				continue;
			}
			$cookie_key = strtolower( $entry['name'] ) . '||/';
			if ( isset( $seen_cookies[ $cookie_key ] ) ) {
				continue;
			}
			foreach ( $entry['setter_patterns'] as $pattern ) {
				if ( ! $pattern ) {
					continue;
				}
				$hit = false;
				foreach ( $candidates as $candidate ) {
					if ( stripos( $candidate, $pattern ) !== false ) {
						$hit = true;
						break;
					}
				}
				if ( ! $hit ) {
					continue;
				}
				$seen_cookies[ $cookie_key ] = true;
				$found++;
				$row = Scanner::build_scanner_row(
					$entry['name'],
					'',
					'/',
					array(
						'provider'          => $entry['provider'] ?? '',
						'detected_category' => $entry['category'] ?? 'uncategorized',
						'duration'          => $entry['duration'] ?? '',
						'duration_seconds'  => $entry['duration_seconds'] ?? 0,
						'description'       => $entry['description'] ?? '',
						'auto_detected'     => 1,
						'is_third_party'    => ! empty( $entry['is_third_party'] ),
						'script_pattern'    => 'Inferred from setter_pattern: ' . $pattern,
						'source_type'       => 'inferred',
						'is_regex'          => ! empty( $entry['is_regex'] ),
					)
				);
				if ( 'inserted' === DB_Queries::upsert_cookie( $row ) ) {
					$inserted++;
				}
				break;
			}
		}
		return array( 'found' => $found, 'inserted' => $inserted );
	}

	private static function match_inline( string $content ): ?array {
		$db = self::get_tracker_db();
		foreach ( $db as $entry ) {
			if ( empty( $entry['inline_patterns'] ) ) {
				continue;
			}
			foreach ( $entry['inline_patterns'] as $pattern ) {
				if ( $pattern && stripos( $content, $pattern ) !== false ) {
					return array(
						'provider'    => $entry['provider'],
						'category'    => $entry['category'],
						'risk_level'  => $entry['risk_level'],
						'description' => $entry['description'] ?? '',
					);
				}
			}
		}
		return null;
	}

	private static function save_findings( int $scan_id, array $findings ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cookieray_third_party_scripts';
		$now   = current_time( 'mysql' );

		foreach ( $findings as $f ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT id FROM %i WHERE scan_id = %d AND provider = %s AND type = %s AND page_url = %s LIMIT 1',
					$table,
					$scan_id,
					$f['provider'] ?? '',
					$f['type'] ?? 'script',
					$f['page_url'] ?? ''
				)
			);

			if ( $exists ) {
				continue;
			}

			$wpdb->insert( // phpcs:ignore
				$table,
				array(
					'scan_id'    => $scan_id,
					'page_url'   => substr( $f['page_url'] ?? '', 0, 2048 ),
					'script_url' => substr( $f['script_url'] ?? '', 0, 2048 ),
					'provider'   => substr( $f['provider'] ?? '', 0, 128 ),
					'category'   => substr( $f['category'] ?? 'uncategorized', 0, 64 ),
					'type'       => substr( $f['type'] ?? 'script', 0, 32 ),
					'risk_level' => substr( $f['risk_level'] ?? 'medium', 0, 16 ),
					'detected_at' => $now,
				)
			);
		}
	}

	private static function get_tracker_db(): array {
		if ( null !== self::$tracker_db ) {
			return self::$tracker_db;
		}

		$path = COOKIERAY_PLUGIN_DIR . 'includes/data/known-trackers.json';
		if ( ! file_exists( $path ) ) {
			self::$tracker_db = array();
			return self::$tracker_db;
		}

		$json             = file_get_contents( $path ); // phpcs:ignore
		$data             = json_decode( $json, true );
		self::$tracker_db = is_array( $data ) ? $data : array();
		return self::$tracker_db;
	}

}

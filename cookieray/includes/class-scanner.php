<?php
/**
 * Cookie scanner engine — server-side crawl + client-side report intake.
 *
 * @package CookieRay
 */

namespace CookieRay;

use CookieRay\DB\DB_Queries;
use CookieRay\DB\DB_Manager;
use CookieRay\DB\Classifications;
use CookieRay\Traits\Singleton;

if (!defined('ABSPATH')) {
	exit;
}

class Scanner
{

	use Singleton;

	const ACTION_RUN = 'cookieray_run_scan';
	const URL_TIMEOUT = 4;

	/**
	 * In-memory cache of the known-cookies dictionary.
	 *
	 * @var array|null
	 */
	private $dictionary = null;

	private function __construct()
	{
		add_action(self::ACTION_RUN, array($this, 'run_scan'));
	}

	/**
	 * Fetch a URL while bypassing common page-cache plugins.
	 *
	 * Combines a query-string buster (defeats WP Rocket / W3TC default
	 * variation) with no-cache headers (LiteSpeed, generic CDNs). Cache
	 * plugins respect different signals — we send all of them.
	 *
	 * @param string $url        URL to fetch.
	 * @param array  $extra_args Args merged into wp_remote_get options.
	 * @return array|\WP_Error
	 */
	public static function fetch_uncached($url, $extra_args = array())
	{
		$separator = (strpos($url, '?') === false) ? '?' : '&';
		$url      .= $separator . 'cookieray_nocache=' . time();

		$defaults = array(
			'timeout'     => self::URL_TIMEOUT,
			'redirection' => 2,
			'sslverify'   => false,
			'user-agent'  => 'CookieRay/' . COOKIERAY_VERSION . ' (Scanner)',
			'headers'     => array(
				'Cache-Control'             => 'no-cache, no-store, must-revalidate',
				'Pragma'                    => 'no-cache',
				'X-Litespeed-Cache-Control' => 'no-cache',
				'X-Cache-Bypass'            => '1',
			),
		);

		// Allow caller to override headers individually instead of clobbering.
		if (!empty($extra_args['headers']) && is_array($extra_args['headers'])) {
			$extra_args['headers'] = array_merge($defaults['headers'], $extra_args['headers']);
		}

		return wp_remote_get($url, array_merge($defaults, $extra_args));
	}

	/* ------------------------------------------------------------------
	 *  Core scan execution
	 * ---------------------------------------------------------------- */

	public function run_scan($scan_id)
	{
		$scan_id = (int) $scan_id;
		if (!$scan_id) {
			return;
		}

		// Cron worker context: lift PHP execution caps so a 100+ URL crawl
		// with per-URL timeout doesn't get killed mid-scan and leave the
		// scan record stuck in 'running' state.
		@set_time_limit(0); // phpcs:ignore
		@ignore_user_abort(true); // phpcs:ignore

		DB_Queries::update_scan(
			$scan_id,
			array(
				'status' => 'running',
				'started_at' => current_time('mysql'),
			)
		);

		$urls = $this->get_urls_to_scan();
		$seen_cookies = array();
		$pages = 0;
		$errors = array();

		foreach ($urls as $url) {
			try {
				$cookies = $this->scan_url($url);
				$pages++;

				foreach ($cookies as $stable_key => $attrs) {
					if (isset($seen_cookies[$stable_key])) {
						continue;
					}
					$seen_cookies[$stable_key] = true;

					$cookie_name = $attrs['name'];
					$source      = $attrs['source'];
					$meta        = $this->categorize($cookie_name, $source . ' ' . $url);
					// Build a row keyed by (name, domain, path); upsert
					// refreshes detected_category + last_detected_at on
					// every scan and never overwrites an existing manual
					// decision.
					$row = self::build_scanner_row(
						$cookie_name,
						$attrs['domain'],
						$attrs['path'],
						array(
							'provider'         => $meta['provider'] ?? $source,
							'detected_category' => $meta['category'] ?? 'uncategorized',
							'duration'         => $meta['duration'] ?? '',
							'duration_seconds' => $meta['duration_seconds'] ?? 0,
							'description'      => $meta['description'] ?? '',
							'auto_detected'    => 1,
							'is_third_party'   => !empty($meta['is_third_party']),
							'script_pattern'   => $source,
							'source_type'      => 'header',
							'is_regex'         => !empty($meta['is_regex']),
						)
					);
					DB_Queries::upsert_cookie($row);
				}
			} catch (\Throwable $e) {
				$errors[] = $url . ': ' . $e->getMessage();
			}
		}

		DB_Queries::update_scan(
			$scan_id,
			array(
				// Server-side phase done. Two async phases (deep-scan cron,
				// client iframe scan) still need to land. The frontend
				// polls status and stays in loading state until one of
				// them flips this to 'completed' (or the 10-minute stuck
				// timeout in the status endpoint forces 'failed'). Using
				// a distinct 'processing' marker makes the wait length
				// data-driven — large sites stay loading as long as the
				// async phases need, no fixed UI timer.
				'status' => 'processing',
				'pages_scanned' => $pages,
				'completed_at' => current_time('mysql'),
				'error_log' => $errors ? implode("\n", $errors) : null,
			)
		);
		// Reconcile cookie counts against actual inventory state. Per-phase
		// increments (server scan, deep scan cron, client iframe scan) ran
		// in different processes and produced inconsistent totals — this is
		// the single source of truth that always matches Cookie Inventory.
		DB_Queries::recompute_scan_counts($scan_id);

		delete_transient('cookieray_dashboard_stats');

		// Queue deep scan asynchronously to detect JS trackers, pixels, and iframes.
		wp_schedule_single_event(time(), 'cookieray_run_deep_scan_embedded', array($scan_id));
		if (function_exists('spawn_cron')) {
			spawn_cron();
		}

		do_action('cookieray_scan_completed', $scan_id);
	}

	/**
	 * Gather URLs to crawl: home, key published content, known CMS routes,
	 * WooCommerce flow pages, and any registered public custom post types.
	 *
	 * E-commerce + auth-gated flows often set tracking cookies that don't appear
	 * on plain content pages, so we explicitly include them.
	 */
	private function get_urls_to_scan()
	{
		$urls = array(home_url('/'));

		// All public post types — not just post/page. Catches WooCommerce
		// products, custom CPTs, portfolios, etc.
		$post_types = get_post_types(array('public' => true), 'names');
		// Drop attachment — media URLs rarely set unique cookies and bloat the scan.
		unset($post_types['attachment']);

		$posts = get_posts(
			array(
				'post_type'      => array_values($post_types),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		foreach ($posts as $post) {
			$urls[] = get_permalink($post);
		}

		// WooCommerce key flow pages — cart, checkout, my-account, shop.
		// These often run payment/auth/fraud detection scripts that set unique cookies.
		if (function_exists('wc_get_page_id')) {
			$wc_page_keys = array('shop', 'cart', 'checkout', 'myaccount', 'terms', 'privacy');
			foreach ($wc_page_keys as $key) {
				$pid = wc_get_page_id($key);
				if ($pid && $pid > 0) {
					$urls[] = get_permalink($pid);
				}
			}
		}

		// WordPress login + register pages — auth flow trackers.
		$urls[] = wp_login_url();
		if (get_option('users_can_register')) {
			$urls[] = wp_registration_url();
		}

		// Sitemap URLs — some plugins set per-route cookies only on docs or catalog
		// pages that do not appear in get_posts() ordering or are listed only in XML.
		$sitemap_cap = (int) apply_filters('cookieray_scan_sitemap_url_limit', 100);
		if ($sitemap_cap > 0) {
			$urls = array_merge($urls, self::get_sitemap_page_urls($sitemap_cap));
		}

		/**
		 * Allow plugins/themes to add or filter the URL list.
		 *
		 * @param array $urls
		 */
		$urls = apply_filters('cookieray_scan_urls', $urls);

		return array_values(array_unique(array_filter($urls)));
	}

	/**
	 * Extract loc targets from sitemap or sitemap-index XML.
	 *
	 * @param string $body Response body.
	 * @return array<int, string>
	 */
	private static function parse_sitemap_locs($body)
	{
		$body = (string) $body;
		if ('' === $body) {
			return array();
		}
		if (!preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $body, $matches)) {
			return array();
		}
		return array_map('trim', $matches[1]);
	}

	/**
	 * Public article URLs from wp-sitemap.xml / sitemap.xml / Yoast index (same host only).
	 *
	 * @param int $max Max URLs to return.
	 * @return array<int, string>
	 */
	private static function get_sitemap_page_urls($max)
	{
		$max = (int) $max;
		if ($max <= 0) {
			return array();
		}

		$home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
		$page_urls = array();
		$seeds = array_unique(
			array_filter(
				array(
					home_url('/wp-sitemap.xml'),
					home_url('/sitemap.xml'),
					home_url('/sitemap_index.xml'),
				)
			)
		);
		$done = false;

		foreach ($seeds as $seed) {
			if ($done || count($page_urls) >= $max) {
				break;
			}
			$response = self::fetch_uncached(
				$seed,
				array('timeout' => 6)
			);
			if (is_wp_error($response)) {
				continue;
			}
			$locs = self::parse_sitemap_locs(wp_remote_retrieve_body($response));
			$child_sitemaps = array();
			foreach ($locs as $loc) {
				$h = wp_parse_url($loc, PHP_URL_HOST);
				if (!$h || strtolower((string) $h) !== $home_host) {
					continue;
				}
				if (preg_match('/\.xml(\?.*)?$/i', $loc)) {
					$child_sitemaps[] = $loc;
				} elseif (count($page_urls) < $max) {
					$page_urls[] = $loc;
				}
			}

			foreach (array_slice($child_sitemaps, 0, 20) as $child) {
				if ($done || count($page_urls) >= $max) {
					break;
				}
				$r2 = self::fetch_uncached(
					$child,
					array('timeout' => 6)
				);
				if (is_wp_error($r2)) {
					continue;
				}
				foreach (self::parse_sitemap_locs(wp_remote_retrieve_body($r2)) as $loc) {
					if (count($page_urls) >= $max) {
						$done = true;
						break;
					}
					$h = wp_parse_url($loc, PHP_URL_HOST);
					if (
						$h && strtolower((string) $h) === $home_host
						&& !preg_match('/\.xml(\?.*)?$/i', $loc)
					) {
						$page_urls[] = $loc;
					}
				}
			}
		}

		return array_values(array_unique($page_urls));
	}

	/**
	 * Scan a single URL. Returns a map of stable_key => attrs+source where
	 * attrs is `['name', 'domain', 'path']`.
	 *
	 * Domain normalization: cookies set on the site's own host get
	 * `domain=''` (host-only convention). This avoids a duplicate-row bug
	 * where the Set-Cookie header parse returned `domain=''` (no Domain
	 * attr) while WP_Http_Cookie populated `domain` with the request host —
	 * different stable keys, both surviving the UNIQUE index.
	 *
	 * Only an explicit cross-domain/subdomain `Domain=` attribute keeps a
	 * non-empty domain in the stable key.
	 */
	private function scan_url($url)
	{
		$response = self::fetch_uncached($url);

		if (is_wp_error($response)) {
			return array();
		}

		$site_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
		$cookies   = array();

		$normalize_domain = function ($d) use ($site_host) {
			$d = strtolower((string) $d);
			if ('' === $d || $d === $site_host) {
				return '';
			}
			return $d;
		};

		// (1) Set-Cookie headers — full attribute parse so we keep domain/path.
		$set_cookies = wp_remote_retrieve_header($response, 'set-cookie');
		if (!empty($set_cookies)) {
			$list = is_array($set_cookies) ? $set_cookies : array($set_cookies);
			foreach ($list as $raw) {
				$attrs = self::parse_cookie_attrs($raw);
				if ('' === $attrs['name']) {
					continue;
				}
				$attrs['domain'] = $normalize_domain($attrs['domain']);
				$key = strtolower($attrs['name']) . '|' . $attrs['domain'] . '|' . $attrs['path'];
				if (!isset($cookies[$key])) {
					$cookies[$key] = array(
						'name'   => $attrs['name'],
						'domain' => $attrs['domain'],
						'path'   => $attrs['path'],
						'source' => 'Set-Cookie header',
					);
				}
			}
		}

		// (2) WP_HTTP cookie collection (cookies array on the response).
		if (!empty($response['cookies']) && is_array($response['cookies'])) {
			foreach ($response['cookies'] as $cookie) {
				if (empty($cookie->name)) {
					continue;
				}
				$d = $normalize_domain(isset($cookie->domain) ? ltrim((string) $cookie->domain, '.') : '');
				$p = isset($cookie->path) && '' !== $cookie->path ? (string) $cookie->path : '/';
				$key = strtolower($cookie->name) . '|' . $d . '|' . $p;
				if (!isset($cookies[$key])) {
					$cookies[$key] = array(
						'name'   => $cookie->name,
						'domain' => $d,
						'path'   => $p,
						'source' => 'Set-Cookie header',
					);
				}
			}
		}

		return $cookies;
	}

	private function parse_cookie_name($raw)
	{
		$attrs = self::parse_cookie_attrs($raw);
		return $attrs['name'];
	}

	/**
	 * Parse a raw `Set-Cookie` header into name + domain + path. Used to
	 * build the stable identity (name, domain, path) for the cookies
	 * inventory.
	 *
	 * - Domain is lowercased and the leading dot stripped.
	 * - Path defaults to '/' when the header omits it.
	 * - Domain defaults to '' (host-only / first-party) when omitted —
	 *   the upsert path uses the empty string in the UNIQUE composite
	 *   index, matching the legacy "no domain captured" behavior.
	 */
	public static function parse_cookie_attrs($raw)
	{
		$out = array('name' => '', 'domain' => '', 'path' => '');
		$raw = trim((string) $raw);
		if ('' === $raw) {
			return $out;
		}
		$segments = array_map('trim', explode(';', $raw));
		if (!empty($segments)) {
			$first = array_shift($segments);
			$eq = strpos($first, '=');
			if (false !== $eq) {
				$out['name'] = trim(substr($first, 0, $eq));
			}
		}
		foreach ($segments as $seg) {
			if ('' === $seg) {
				continue;
			}
			$eq = strpos($seg, '=');
			if (false === $eq) {
				continue;
			}
			$key = strtolower(trim(substr($seg, 0, $eq)));
			$val = trim(substr($seg, $eq + 1));
			if ('domain' === $key) {
				$out['domain'] = strtolower(ltrim($val, '.'));
			} elseif ('path' === $key) {
				$out['path'] = '' !== $val ? $val : '/';
			}
		}
		if ('' === $out['path']) {
			$out['path'] = '/';
		}
		return $out;
	}

	/**
	 * Build a row payload suitable for DB_Queries::upsert_cookie() out of
	 * the scanner's detected metadata, applying a remembered manual
	 * classification when the setting is enabled.
	 *
	 * The detected category lives in `detected_category`. When memory has
	 * an entry for the stable key, we set `manual_category` and
	 * `manual_source = 'remembered'`. The upsert helper then COALESCEs
	 * the materialized `category` column so admin display equals frontend
	 * behavior — manual decision wins, otherwise detected wins.
	 *
	 * @param string $name   Cookie name.
	 * @param string $domain Cookie domain (may be '').
	 * @param string $path   Cookie path (defaults to '/').
	 * @param array  $row    Base row data (will not have category fields set).
	 * @return array Row ready for upsert.
	 */
	public static function build_scanner_row($name, $domain, $path, array $row)
	{
		$row['name']   = $name;
		$row['domain'] = (string) $domain;
		$row['path']   = '' !== (string) $path ? (string) $path : '/';

		$detected = $row['detected_category'] ?? ($row['category'] ?? 'uncategorized');
		$row['detected_category'] = $detected ?: 'uncategorized';
		// Caller used `category` historically — drop it; the upsert helper
		// recomputes the effective column from detected/manual.
		unset($row['category']);
		$row['manual_category'] = null;
		$row['manual_source']   = null;

		if (Classifications::is_enabled()) {
			$entry = Classifications::get($name, $row['domain'], $row['path']);
			if ($entry && !empty($entry['category']) && 'uncategorized' !== $entry['category']) {
				$row['manual_category'] = (string) $entry['category'];
				$row['manual_source']   = 'remembered';
			}
		}
		return $row;
	}

	/* ------------------------------------------------------------------
	 *  Categorization (known cookies dictionary)
	 * ---------------------------------------------------------------- */

	/**
	 * Look up cookie metadata by name.
	 *
	 * @return array Metadata (empty when unknown).
	 */
	public function categorize($name, $source = '')
	{
		foreach ($this->get_dictionary() as $entry) {
			if (!empty($entry['is_regex']) && !empty($entry['pattern'])) {
				if (@preg_match('/' . $entry['pattern'] . '/', $name)) { // phpcs:ignore
					return $entry;
				}
			} elseif (strcasecmp($entry['name'], $name) === 0) {
				return $entry;
			}
		}

		// Domain-pattern fallback: when the cookie name is unknown, check if the
		// setter source (e.g. Set-Cookie origin URL or scan page URL) hints at a
		// known third-party tracker. Cuts the "uncategorized" rate significantly
		// for cookies set by named providers we just don't have an exact entry for.
		if ('' !== (string) $source) {
			$haystack = strtolower($source);
			foreach ($this->get_domain_rules() as $needle => $rule) {
				if (false !== strpos($haystack, $needle)) {
					return array(
						'provider'    => $rule[1],
						'category'    => $rule[0],
						'duration'    => '',
						'description' => sprintf('Auto-classified by setter domain (%s). Provider: %s.', $needle, $rule[1]),
					);
				}
			}
		}

		return array();
	}

	/**
	 * Domain → [category, provider] map used as fallback when a cookie name
	 * isn't in the dictionary. Order matters — first hit wins.
	 */
	private function get_domain_rules()
	{
		return array(
			'analytics.tiktok.com'   => array('marketing', 'TikTok'),
			'ads.tiktok.com'         => array('marketing', 'TikTok'),
			'tiktok.com'             => array('marketing', 'TikTok'),
			'facebook.com'           => array('marketing', 'Facebook'),
			'connect.facebook.net'   => array('marketing', 'Facebook'),
			'doubleclick.net'        => array('marketing', 'Google Ads'),
			'googleadservices.com'   => array('marketing', 'Google Ads'),
			'googlesyndication.com'  => array('marketing', 'Google Ads'),
			'google-analytics.com'   => array('analytical', 'Google Analytics'),
			'analytics.google.com'   => array('analytical', 'Google Analytics'),
			'googletagmanager.com'   => array('analytical', 'Google Tag Manager'),
			'linkedin.com'           => array('marketing', 'LinkedIn'),
			'licdn.com'              => array('marketing', 'LinkedIn'),
			'clarity.ms'             => array('analytical', 'Microsoft Clarity'),
			'bing.com'               => array('marketing', 'Microsoft Bing'),
			'bat.bing.com'           => array('marketing', 'Bing UET'),
			'hotjar.com'             => array('analytical', 'Hotjar'),
			'mixpanel.com'           => array('analytical', 'Mixpanel'),
			'amplitude.com'          => array('analytical', 'Amplitude'),
			'segment.io'             => array('analytical', 'Segment'),
			'segment.com'            => array('analytical', 'Segment'),
			'pinterest.com'          => array('marketing', 'Pinterest'),
			'snapchat.com'           => array('marketing', 'Snapchat'),
			'sc-static.net'          => array('marketing', 'Snapchat'),
			'reddit.com'             => array('marketing', 'Reddit'),
			'twitter.com'            => array('marketing', 'Twitter / X'),
			't.co'                   => array('marketing', 'Twitter / X'),
			'adobe.com'              => array('analytical', 'Adobe Analytics'),
			'omtrdc.net'             => array('analytical', 'Adobe Analytics'),
			'demdex.net'             => array('marketing', 'Adobe Audience Manager'),
			'2o7.net'                => array('analytical', 'Adobe Analytics'),
			'mailchimp.com'          => array('marketing', 'Mailchimp'),
			'list-manage.com'        => array('marketing', 'Mailchimp'),
			'hubspot.com'            => array('marketing', 'HubSpot'),
			'hs-analytics.net'       => array('analytical', 'HubSpot'),
			'intercom.io'            => array('functional', 'Intercom'),
			'crisp.chat'             => array('functional', 'Crisp Chat'),
			'zopim.com'              => array('functional', 'Zendesk Chat'),
			'tawk.to'                => array('functional', 'Tawk.to'),
			'fullstory.com'          => array('analytical', 'FullStory'),
			'optimizely.com'         => array('analytical', 'Optimizely'),
			'cdn.optimizely.com'     => array('analytical', 'Optimizely'),
			'crazyegg.com'           => array('analytical', 'Crazy Egg'),
			'mouseflow.com'          => array('analytical', 'Mouseflow'),
			'stripe.com'             => array('necessary', 'Stripe'),
			'cloudflare.com'         => array('necessary', 'Cloudflare'),
			'recaptcha.net'          => array('necessary', 'Google reCAPTCHA'),
			'gstatic.com/recaptcha'  => array('necessary', 'Google reCAPTCHA'),
		);
	}

	/**
	 * Load + cache the known-cookies dictionary.
	 */
	public function get_dictionary()
	{
		if (null !== $this->dictionary) {
			return $this->dictionary;
		}

		$path = COOKIERAY_PLUGIN_DIR . 'includes/data/known-cookies.json';
		if (!file_exists($path)) {
			$this->dictionary = array();
			return $this->dictionary;
		}

		$json = file_get_contents($path); // phpcs:ignore
		$data = json_decode($json, true);
		$this->dictionary = is_array($data) ? $data : array();
		return $this->dictionary;
	}

	/* ------------------------------------------------------------------
	 *  Client-side report intake
	 * ---------------------------------------------------------------- */

	/**
	 * Merge cookies + storage findings reported by the admin-only client scanner.
	 *
	 * @param array $report {
	 *     @type array  $cookies        Array of {name, value?} objects.
	 *     @type array  $localStorage   Array of {key, value} (new) or strings (legacy).
	 *     @type array  $sessionStorage Array of {key, value} (new) or strings (legacy).
	 *     @type array  $indexedDb      Array of {name, version} objects.
	 *     @type array  $serviceWorkers Array of {scope, script} objects.
	 *     @type string $url            URL where findings were collected.
	 *     @type int    $scan_id        Optional scan row to attribute findings to.
	 * }
	 * @return array Summary of merge operation.
	 */
	public function ingest_client_report($report)
	{
		$added = 0;
		$existing = 0;
		$url = (string) ($report['url'] ?? '');

		// (1) document.cookie entries — treat as full cookies regardless of dictionary match.
		$cookies = isset($report['cookies']) && is_array($report['cookies'])
			? $report['cookies']
			: array();

		foreach ($cookies as $item) {
			$name = isset($item['name']) ? trim((string) $item['name']) : '';
			if ('' === $name) {
				continue;
			}

			// Browser-reported cookies don't carry the original Set-Cookie
			// header, so domain/path are unknown — fall back to the empty
			// stable-key tail. Future-proofed: if the client scanner starts
			// emitting attrs, drop them straight in via $item['domain']/
			// $item['path'].
			$domain = isset($item['domain']) ? strtolower(ltrim((string) $item['domain'], '.')) : '';
			$path   = isset($item['path']) && '' !== $item['path'] ? (string) $item['path'] : '/';

			$meta = $this->categorize($name, $url);
			$row  = self::build_scanner_row(
				$name,
				$domain,
				$path,
				array(
					'provider'         => $meta['provider'] ?? '',
					'detected_category' => $meta['category'] ?? 'uncategorized',
					'duration'         => $meta['duration'] ?? '',
					'duration_seconds' => $meta['duration_seconds'] ?? 0,
					'description'      => $meta['description'] ?? '',
					'auto_detected'    => 1,
					'is_third_party'   => 0,
					'script_pattern'   => 'Client-side scan: ' . $url,
					'source_type'      => 'js',
					'is_regex'         => !empty($meta['is_regex']),
				)
			);
			$result = DB_Queries::upsert_cookie($row);
			if ('inserted' === $result) {
				$added++;
			} elseif ('updated' === $result) {
				$existing++;
			}
		}

		// (2) localStorage / sessionStorage — only persist entries whose key matches
		// a known tracker in the dictionary, otherwise we'd pollute the inventory
		// with arbitrary application state. Supports both legacy (array of strings)
		// and new ({key, value}) payload shapes.
		$storage_added = 0;
		foreach (array('localStorage' => 'localStorage', 'sessionStorage' => 'sessionStorage') as $field => $label) {
			$entries = isset($report[$field]) && is_array($report[$field]) ? $report[$field] : array();
			foreach ($entries as $entry) {
				$key = is_array($entry) ? (string) ($entry['key'] ?? '') : (string) $entry;
				if ('' === $key) {
					continue;
				}
				$meta = $this->categorize($key, $url);
				// Skip when the dictionary missed AND memory has no rescue —
				// don't pollute inventory with arbitrary application state.
				$remembered = Classifications::is_enabled() ? Classifications::get($key, '', '/') : null;
				if (
					(empty($meta) || empty($meta['category']) || 'uncategorized' === $meta['category'])
					&& empty($remembered['category'])
				) {
					continue;
				}
				$row = self::build_scanner_row(
					$key,
					'',
					'/',
					array(
						'provider'         => $meta['provider'] ?? '',
						'detected_category' => $meta['category'] ?? 'uncategorized',
						'duration'         => $meta['duration'] ?? '',
						'duration_seconds' => $meta['duration_seconds'] ?? 0,
						'description'      => ($meta['description'] ?? '') . ' [Detected in ' . $label . '.]',
						'auto_detected'    => 1,
						'is_third_party'   => 0,
						'script_pattern'   => $label . ': ' . $url,
						'source_type'      => 'js',
						'is_regex'         => !empty($meta['is_regex']),
					)
				);
				if ('inserted' === DB_Queries::upsert_cookie($row)) {
					$storage_added++;
				}
			}
		}

		// (3) IndexedDB databases + (4) Service Workers — surfaced in the response
		// so the admin sees counts in scan history; not yet persisted as cookies
		// because their names rarely overlap with the cookie dictionary.
		$indexed_db = isset($report['indexedDb']) && is_array($report['indexedDb'])
			? array_values(array_filter(array_map(function ($d) {
				return is_array($d) && !empty($d['name']) ? (string) $d['name'] : '';
			}, $report['indexedDb'])))
			: array();
		$service_workers = isset($report['serviceWorkers']) && is_array($report['serviceWorkers'])
			? array_values(array_filter(array_map(function ($s) {
				return is_array($s) && !empty($s['script']) ? (string) $s['script'] : '';
			}, $report['serviceWorkers'])))
			: array();

		if (!empty($report['scan_id'])) {
			$scan_id = (int) $report['scan_id'];
			$scan = DB_Queries::get_scan($scan_id);
			if ($scan) {
				// Append IndexedDB / SW info to the scan's `notes` field (informational,
				// rendered separately from `error_log` so they don't look like errors).
				$notes = array();
				if ($indexed_db) {
					$notes[] = 'IndexedDB databases detected: ' . implode(', ', $indexed_db);
				}
				if ($service_workers) {
					$notes[] = 'Service Workers detected: ' . implode(', ', $service_workers);
				}
				if ($notes) {
					$existing_notes = (string) ($scan['notes'] ?? '');
					DB_Queries::update_scan($scan_id, array(
						'notes' => trim($existing_notes . "\n" . implode("\n", $notes)),
					));
				}
				// Cookie counts come from a single recompute against the
				// inventory rather than per-phase increments — keeps the
				// scan badge in sync with the visible Cookie Inventory.
				DB_Queries::recompute_scan_counts($scan_id);
				// Async phase landed — flip the scan to 'completed' so the
				// UI polling loop can stop waiting. Whichever async phase
				// (this client report OR the deep-scan cron) finishes last
				// idempotently re-flips the same value. We only override
				// 'processing'; never 'failed', so error states survive.
				$current = DB_Queries::get_scan($scan_id);
				if ($current && 'processing' === ($current['status'] ?? '')) {
					DB_Queries::update_scan($scan_id, array('status' => 'completed'));
				}
			}
		}

		delete_transient('cookieray_dashboard_stats');

		return array(
			'added' => $added,
			'existing' => $existing,
			'storage_added' => $storage_added,
			'indexed_db_count' => count($indexed_db),
			'service_workers_count' => count($service_workers),
			'total' => count($cookies),
		);
	}
}

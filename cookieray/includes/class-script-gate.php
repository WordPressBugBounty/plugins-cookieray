<?php
/**
 * Script gate — converts tracking scripts to inert <script type="text/plain">
 * tags until the visitor grants consent.
 *
 * Two layers:
 *   1. WordPress `script_loader_tag` filter — catches enqueued scripts.
 *   2. A small inline JS blocker injected in wp_head at priority 2 — catches
 *      dynamically-injected <script> nodes (e.g. Google Tag Manager).
 *
 * @package CookieRay
 */

namespace CookieRay;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Script_Gate {

	/**
	 * Cached pattern map for the current request. Reset by clear_cache().
	 *
	 * @var array<string, string[]>|null
	 */
	private static $pattern_cache = null;

	/**
	 * Return patterns grouped by category. Pulled from the known-cookies
	 * dictionary AND merged with site-owner-defined patterns from the
	 * cookies DB so the gate and scanner share a single source of truth.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_pattern_map() {
		if ( self::$pattern_cache !== null ) {
			return self::$pattern_cache;
		}

		$out = array(
			'analytical'    => array(),
			'marketing'     => array(),
			'functional'    => array(),
			'uncategorized' => array(),
		);

		$file = COOKIERAY_PLUGIN_DIR . 'includes/data/known-cookies.json';
		if ( ! file_exists( $file ) ) {
			self::$pattern_cache = $out;
			return $out;
		}

		$raw  = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$list = json_decode( $raw, true );
		if ( ! is_array( $list ) ) {
			self::$pattern_cache = $out;
			return $out;
		}

		foreach ( $list as $entry ) {
			if ( empty( $entry['setter_patterns'] ) || ! is_array( $entry['setter_patterns'] ) ) {
				continue;
			}
			$cat = $entry['category'] ?? 'analytical';
			if ( ! isset( $out[ $cat ] ) ) {
				continue;
			}
			foreach ( $entry['setter_patterns'] as $pattern ) {
				$pattern = (string) $pattern;
				if ( $pattern !== '' && ! in_array( $pattern, $out[ $cat ], true ) ) {
					$out[ $cat ][] = $pattern;
				}
			}
		}

		// Resolve uncategorized fallback rule from settings.
		// 'block_always' keeps uncategorized patterns in their own bucket so the
		// frontend never restores them (no banner toggle = no consent path).
		// 'marketing' folds them into Marketing so the visitor's Marketing
		// consent decision controls them.
		$settings       = get_option( 'cookieray_settings', array() );
		$uncat_handling = in_array( $settings['uncategorized_handling'] ?? '', array( 'block_always', 'marketing' ), true )
			? $settings['uncategorized_handling']
			: 'block_always';

		// Merge site-owner-defined script patterns from the cookies DB so that
		// scanned/manually-added cookies participate in script gating alongside
		// the static known-cookies.json baseline.
		if ( class_exists( '\\CookieRay\\DB\\DB_Queries' ) ) {
			$db = \CookieRay\DB\DB_Queries::get_cookies(
				array(
					'category__in'       => array( 'analytical', 'functional', 'marketing' ),
					'has_script_pattern' => true,
					'no_pagination'      => true,
				)
			);
			foreach ( ( $db['items'] ?? array() ) as $row ) {
				$cat = $row['category'] ?? '';
				$pat = trim( (string) ( $row['script_pattern'] ?? '' ) );
				if ( '' === $pat || ! isset( $out[ $cat ] ) ) {
					continue;
				}
				if ( ! in_array( $pat, $out[ $cat ], true ) ) {
					$out[ $cat ][] = $pat;
				}
			}

			// Uncategorized DB rows — route based on fallback rule.
			$unk = \CookieRay\DB\DB_Queries::get_cookies(
				array(
					'category'           => 'uncategorized',
					'has_script_pattern' => true,
					'no_pagination'      => true,
				)
			);
			$bucket = ( 'marketing' === $uncat_handling ) ? 'marketing' : 'uncategorized';
			foreach ( ( $unk['items'] ?? array() ) as $row ) {
				$pat = trim( (string) ( $row['script_pattern'] ?? '' ) );
				if ( '' === $pat ) {
					continue;
				}
				if ( ! in_array( $pat, $out[ $bucket ], true ) ) {
					$out[ $bucket ][] = $pat;
				}
			}
		}

		self::$pattern_cache = $out;
		return $out;
	}

	/**
	 * Reset the in-process pattern cache so the next call to get_pattern_map()
	 * rebuilds with the latest DB state. Called from REST cookie mutations.
	 */
	public static function clear_cache() {
		self::$pattern_cache = null;
	}

	/**
	 * Flat "pattern → category" map used by the PHP filter for O(1) matching.
	 *
	 * @return array<string, string>
	 */
	public static function get_flat_map() {
		$flat = array();
		foreach ( self::get_pattern_map() as $cat => $patterns ) {
			foreach ( $patterns as $p ) {
				$flat[ $p ] = $cat;
			}
		}
		return $flat;
	}

	/**
	 * Check whether the gate should actively block on this request.
	 *
	 * Cache-safe: depends only on a site setting, not on $_COOKIE. Same
	 * answer for every visitor. JS handles per-visitor unblocking after
	 * reading the consent cookie client-side.
	 */
	public static function should_gate() {
		$settings = get_option( 'cookieray_settings', array() );
		return ( $settings['consent_mode'] ?? 'log_only' ) === 'block_until_consent';
	}

	/**
	 * Register the script_loader_tag filter. Called from Frontend::init()
	 * only when needed.
	 */
	public static function register() {
		if ( ! self::should_gate() ) {
			return;
		}
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_tag' ), 10, 3 );
	}

	/**
	 * For each enqueued script whose src matches a known tracking pattern and
	 * whose category the visitor hasn't accepted, convert the tag to an inert
	 * placeholder that the client-side unblocker can restore after consent.
	 *
	 * @param string $tag    The full <script> HTML.
	 * @param string $handle The script handle.
	 * @param string $src    The resolved src URL.
	 */
	public static function filter_tag( $tag, $handle, $src ) {
		if ( ! $src ) {
			return $tag;
		}
		// Never gate our own bundle.
		if ( 0 === strpos( $handle, 'cookieray' ) ) {
			return $tag;
		}

		$matched = self::match_category( $src );
		if ( ! $matched ) {
			return $tag;
		}

		// Cache-safe: always neutralize matched scripts. JS unblocks per-visitor
		// based on their consent cookie via unblockScripts() in script-blocker.js.

		// Replace `src="..."` with `data-cookieray-src="..."` and switch to
		// type="text/plain" so the browser parses but does NOT execute it.
		$replacement = sprintf(
			'type="text/plain" data-cookieray-category="%s" data-cookieray-src="%s"',
			esc_attr( $matched ),
			esc_attr( $src )
		);
		$tag = preg_replace( '/\s(src=(["\'])' . preg_quote( $src, '/' ) . '\2)/', ' ' . $replacement, $tag, 1 );

		// Also strip any existing type="..." so our type="text/plain" wins.
		$tag = preg_replace( '/\stype=(["\'])[^"\']*\1/', '', $tag );
		$tag = preg_replace( '/<script\s/', '<script type="text/plain" ', $tag, 1 );

		return $tag;
	}

	/**
	 * Match a script src against the known pattern list.
	 * Returns the category ('analytical' | 'marketing' | 'functional') or null.
	 *
	 * Domain-aware: pattern is matched against URL host (or host+path when
	 * the pattern itself contains a slash). Prevents a benign URL whose path
	 * happens to mention a tracker domain (e.g. /img/googletagmanager.png)
	 * from being treated as a tracker.
	 */
	public static function match_category( $src ) {
		$host = wp_parse_url( $src, PHP_URL_HOST );
		if ( ! $host ) {
			return null;
		}
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		$flat = self::get_flat_map();
		foreach ( $flat as $pattern => $category ) {
			if ( '' === $pattern ) {
				continue;
			}
			$haystack = ( false === strpos( $pattern, '/' ) ) ? $host : $host . $path;
			if ( false !== stripos( $haystack, $pattern ) ) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * Output the inline early blocker script — a tiny MutationObserver that
	 * catches dynamically-injected <script src="..."> nodes (the ones WP's
	 * script_loader_tag filter can't see, like GTM-injected trackers).
	 * Echoed in wp_head priority 2 so it runs before any downstream scripts.
	 */
	public static function inline_blocker_script() {
		$patterns  = self::get_pattern_map();
		$settings  = get_option( 'cookieray_settings', array() );
		// Domains that should bypass neutralization even in block_until_consent
		// mode — e.g. Meta Pixel when running in Meta Consent Mode (always-load
		// with fbq('consent','revoke') default, like GTM/GCM v2).
		$allowlist = apply_filters( 'cookieray_script_gate_allowlist', array() );
		$js_data   = wp_json_encode(
			array(
				'patterns'  => $patterns,
				'mode'      => $settings['consent_mode'] ?? 'log_only',
				'allowlist' => array_values( (array) $allowlist ),
			)
		);

		// phpcs:disable WordPress.WP.EnqueuedResources
		?>
<script id="cookieray-early-blocker">
(function(){
	var cfg = <?php echo $js_data; // phpcs:ignore ?>;
	if (cfg.mode !== 'block_until_consent') return;

	function match(src){
		if (!src) return null;
		var url;
		try { url = new URL(src, location.href); } catch (e) { return null; }
		var host = url.hostname.toLowerCase();
		var hostPath = (host + url.pathname).toLowerCase();
		var al = cfg.allowlist || [];
		for (var a = 0; a < al.length; a++) {
			var ap = (al[a] || '').toLowerCase();
			if (ap && host.indexOf(ap) !== -1) return null;
		}
		var groups = cfg.patterns || {};
		for (var cat in groups) {
			var list = groups[cat] || [];
			for (var i = 0; i < list.length; i++) {
				var p = (list[i] || '').toLowerCase();
				if (!p) continue;
				var haystack = p.indexOf('/') === -1 ? host : hostPath;
				if (haystack.indexOf(p) !== -1) return cat;
			}
		}
		return null;
	}

	// Cache-safe: always neutralize matched scripts. The main bundle reads
	// the visitor's cookie and calls unblockScripts(cats) to restore them.
	function neutralize(el){
		try {
			if (el.getAttribute('type') === 'text/plain') return;
			var src = el.getAttribute('src') || el.src;
			if (!src) return;
			var cat = match(src);
			if (!cat) return;
			el.setAttribute('data-cookieray-src', src);
			el.setAttribute('data-cookieray-category', cat);
			el.setAttribute('type', 'text/plain');
			el.removeAttribute('src');
		} catch (e) {}
	}

	// Synchronous interception: dynamically-injected <script async src="...">
	// (e.g. createElement+appendChild) starts fetching at insert time, BEFORE
	// MutationObserver microtasks fire. Hook insertion methods so neutralize
	// runs in the same call frame as the insertion itself.
	var nativeAppendChild   = Node.prototype.appendChild;
	var nativeInsertBefore  = Node.prototype.insertBefore;
	var nativeReplaceChild  = Node.prototype.replaceChild;
	var nativeAppend        = Element.prototype.append;
	var nativePrepend       = Element.prototype.prepend;
	var nativeSetAttribute  = Element.prototype.setAttribute;

	function preInsert(node){
		if (node && node.nodeType === 1 && node.nodeName === 'SCRIPT') {
			neutralize(node);
		}
	}
	function preInsertMany(args){
		for (var i = 0; i < args.length; i++) preInsert(args[i]);
	}

	Node.prototype.appendChild = function(node){
		preInsert(node);
		return nativeAppendChild.call(this, node);
	};
	Node.prototype.insertBefore = function(node, ref){
		preInsert(node);
		return nativeInsertBefore.call(this, node, ref);
	};
	Node.prototype.replaceChild = function(newNode, oldNode){
		preInsert(newNode);
		return nativeReplaceChild.call(this, newNode, oldNode);
	};
	Element.prototype.append = function(){
		preInsertMany(arguments);
		return nativeAppend.apply(this, arguments);
	};
	Element.prototype.prepend = function(){
		preInsertMany(arguments);
		return nativePrepend.apply(this, arguments);
	};

	// Hook setAttribute on script elements so `script.setAttribute('src', ...)`
	// AFTER insertion (or `script.src = ...` before insertion in any order) gets
	// neutralized. The browser kicks off the fetch at the moment src is set on
	// an in-document script.
	Element.prototype.setAttribute = function(name, value){
		if (this.nodeName === 'SCRIPT' && typeof name === 'string' && name.toLowerCase() === 'src') {
			var cat = match(value);
			if (cat) {
				nativeSetAttribute.call(this, 'data-cookieray-src', value);
				nativeSetAttribute.call(this, 'data-cookieray-category', cat);
				nativeSetAttribute.call(this, 'type', 'text/plain');
				return;
			}
		}
		return nativeSetAttribute.call(this, name, value);
	};

	// Also intercept the `src` property setter — many trackers use `s.src = '...'`.
	var srcDesc = Object.getOwnPropertyDescriptor(HTMLScriptElement.prototype, 'src')
		|| Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'src');
	if (srcDesc && srcDesc.set) {
		var nativeSrcSet = srcDesc.set;
		Object.defineProperty(HTMLScriptElement.prototype, 'src', {
			configurable: true,
			enumerable: srcDesc.enumerable,
			get: srcDesc.get,
			set: function(value){
				var cat = match(value);
				if (cat) {
					nativeSetAttribute.call(this, 'data-cookieray-src', value);
					nativeSetAttribute.call(this, 'data-cookieray-category', cat);
					nativeSetAttribute.call(this, 'type', 'text/plain');
					return;
				}
				return nativeSrcSet.call(this, value);
			}
		});
	}

	// Fallback: parser-inserted, document.write, or paths we didn't hook.
	var observer = new MutationObserver(function(mutations){
		for (var m = 0; m < mutations.length; m++) {
			var added = mutations[m].addedNodes;
			for (var n = 0; n < added.length; n++) {
				var node = added[n];
				if (node && node.nodeName === 'SCRIPT' && node.getAttribute && node.getAttribute('src')) {
					neutralize(node);
				}
			}
		}
	});
	observer.observe(document.documentElement, { childList: true, subtree: true });

	// Expose so the main bundle can restore scripts after consent.
	window.cookieRayBlocker = {
		disconnect: function(){
			observer.disconnect();
			Node.prototype.appendChild   = nativeAppendChild;
			Node.prototype.insertBefore  = nativeInsertBefore;
			Node.prototype.replaceChild  = nativeReplaceChild;
			Element.prototype.append     = nativeAppend;
			Element.prototype.prepend    = nativePrepend;
			Element.prototype.setAttribute = nativeSetAttribute;
			if (srcDesc) Object.defineProperty(HTMLScriptElement.prototype, 'src', srcDesc);
		},
		match: match
	};
})();
</script>
		<?php
		// phpcs:enable WordPress.WP.EnqueuedResources
	}
}

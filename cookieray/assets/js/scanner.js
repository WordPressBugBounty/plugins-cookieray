/**
 * CookieRay client-side scanner.
 *
 * Runs only when an admin visits a frontend page with ?cookieray_scan=1.
 * Collects cookies, local/session storage, IndexedDB databases, and Service
 * Worker registrations. Simulates basic interaction (scroll + click) to
 * trigger lazy-loaded trackers before the snapshot. Posts findings to
 * /wp-json/cookieray/v1/scanner/report, then redirects back without the flag.
 */

// Cookies set exclusively for logged-in WP admins — not present for normal visitors.
var WP_ADMIN_COOKIE_PREFIXES = [
  'wordpress_',
  'wordpress_logged_in_',
  'wordpressuser_',
  'wordpresspass_',
  'wp-settings-',
  'wp_postpass_',
  'comment_author_',
  'comment_author_email_',
  'comment_author_url_',
  'cookieray_',
];

function isAdminCookie(name) {
  for (var i = 0; i < WP_ADMIN_COOKIE_PREFIXES.length; i++) {
    if (name.indexOf(WP_ADMIN_COOKIE_PREFIXES[i]) === 0) return true;
  }
  return false;
}

function collectCookies() {
  if (!document.cookie) return [];
  return document.cookie
    .split(';')
    .map((c) => c.trim())
    .filter(Boolean)
    .map((c) => {
      const eq = c.indexOf('=');
      return {
        name: eq >= 0 ? c.slice(0, eq) : c,
        value: eq >= 0 ? c.slice(eq + 1) : '',
      };
    })
    .filter((c) => !isAdminCookie(c.name));
}

// Capture both the key and a truncated value preview. Many tracker IDs live in
// localStorage values (Hotjar `_hjid`, Amplitude `amplitude_id_*`, etc.) — keys
// alone hide what's actually being stored.
function collectStorage(store) {
  const items = [];
  try {
    for (let i = 0; i < store.length; i++) {
      const key = store.key(i);
      let value = '';
      try {
        const raw = store.getItem(key) || '';
        value = raw.length > 200 ? raw.slice(0, 200) + '…' : raw;
      } catch (e) {
        /* value read may throw on some sandboxed entries */
      }
      items.push({ key: key, value: value });
    }
  } catch (e) {
    /* may throw in sandboxed iframes */
  }
  return items;
}

// Enumerate IndexedDB databases. Modern trackers (Meta SDK, TikTok Pixel)
// increasingly persist identifiers in IndexedDB instead of cookies, so this
// surfaces tracking that document.cookie never sees.
async function collectIndexedDB() {
  if (!window.indexedDB || typeof window.indexedDB.databases !== 'function') {
    return [];
  }
  try {
    const dbs = await window.indexedDB.databases();
    return (dbs || [])
      .filter((d) => d && d.name)
      .map((d) => ({ name: d.name, version: d.version || 0 }));
  } catch (e) {
    return [];
  }
}

// Detect registered Service Workers — they can intercept network requests and
// set cookies/storage in the background, completely invisible to a normal scan.
async function collectServiceWorkers() {
  if (!('serviceWorker' in navigator) || typeof navigator.serviceWorker.getRegistrations !== 'function') {
    return [];
  }
  try {
    const regs = await navigator.serviceWorker.getRegistrations();
    return (regs || []).map((r) => ({
      scope: r.scope || '',
      script: (r.active && r.active.scriptURL) || (r.installing && r.installing.scriptURL) || (r.waiting && r.waiting.scriptURL) || '',
    }));
  } catch (e) {
    return [];
  }
}

// Trigger lazy-loaded trackers by simulating real user activity:
//   - scroll to bottom and back (catches IntersectionObserver-gated scripts)
//   - dispatch a synthetic click on the body (catches click-tracking)
// We swallow all errors — if any of this fails, we still want the rest of the
// snapshot to go through.
function simulateInteraction() {
  try {
    const startY = window.scrollY;
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' });
    window.scrollTo({ top: startY, behavior: 'instant' });
  } catch (e) { /* noop */ }

  try {
    const evt = new MouseEvent('click', { bubbles: true, cancelable: true });
    document.body && document.body.dispatchEvent(evt);
  } catch (e) { /* noop */ }

  try {
    window.dispatchEvent(new Event('scroll'));
    window.dispatchEvent(new Event('focus'));
  } catch (e) { /* noop */ }
}

async function run(config) {
  // Trigger interaction first, then wait briefly for any newly-loaded trackers
  // to set their cookies before we snapshot.
  simulateInteraction();
  await new Promise((r) => setTimeout(r, 800));

  const [indexedDb, serviceWorkers] = await Promise.all([
    collectIndexedDB(),
    collectServiceWorkers(),
  ]);

  const payload = {
    cookies: collectCookies(),
    localStorage: collectStorage(window.localStorage),
    sessionStorage: collectStorage(window.sessionStorage),
    indexedDb: indexedDb,
    serviceWorkers: serviceWorkers,
    url: window.location.href,
    scan_id: config.scanId || 0,
  };

  try {
    const res = await fetch(config.restUrl + 'scanner/report', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.nonce,
      },
      body: JSON.stringify(payload),
    });
    const summary = await res.json().catch(() => ({}));
    console.log('[CookieRay] client scan complete', summary);
  } catch (e) {
    console.warn('[CookieRay] client scan failed', e);
  }

  // Clean the URL so reloads don't retrigger.
  const url = new URL(window.location.href);
  url.searchParams.delete('cookieray_scan');
  window.history.replaceState({}, '', url.toString());
}

function shouldRun() {
  const params = new URLSearchParams(window.location.search);
  return params.get('cookieray_scan') === '1';
}

function runAfterDelay(config) {
  // 2.5s for initial page settle, then run() does its own simulate+wait pass.
  var delay = 2500;
  if (document.readyState === 'complete') {
    setTimeout(function () { run(config); }, delay);
  } else {
    window.addEventListener('load', function () {
      setTimeout(function () { run(config); }, delay);
    });
  }
}

if (shouldRun() && window.cookieRayScanner) {
  runAfterDelay(window.cookieRayScanner);
}

=== CookieRay - Cookie Banner for Cookie Consent (GDPR/CCPA Compliant) ===
Contributors: devitemsllc, aslamhasib, zenaulislam, madhusudandev
Tags: cookie consent, gdpr, ccpa, cookie banner, privacy
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie consent banner, script blocking, Google Consent Mode v2, cookie scanner, and consent logs for WordPress.


== Description ==

CookieRay is a cookie consent and privacy tooling plugin for WordPress. It stores data on your hosting account and runs in your environment. There is no external SaaS dashboard for site operation.

CookieRay helps website owners manage cookie consent settings for GDPR, CCPA, ePrivacy, and other privacy-related requirements. Legal compliance depends on your website setup, third-party tools, region, and how the plugin is configured.

CookieRay provides a visitor-facing consent experience, configurable script blocking, cookie discovery from your site, detailed consent records, and a setup dashboard to help you manage cookie consent records and support privacy-related workflows.

= Cookie consent banner =

A customizable consent surface with card and bar layouts, live preview in the admin, and nine placement positions. Visitors can Accept All, Decline All, or open a Preferences modal to choose which categories to allow (Necessary, Analytical, Functional, and Marketing). Banner text, colors, buttons, and fonts are editable without writing code.

= Script blocking =

Choose Log Only recording or Strict blocking in Settings. In Strict mode, CookieRay delays known tracking scripts until the visitor agrees to categories that match those trackers. Common patterns for services such as Google Analytics, Meta Pixel, Hotjar, HubSpot, TikTok Pixel, and many others are included out of the box.

= Google Consent Mode v2 =

When enabled, CookieRay outputs consent state in the visitor's browser for Google tags (such as GA4, Google Ads, or Google Tag Manager) that **you have already placed on your site**. Google Consent Mode v2 lets Google tags adjust their behavior based on visitor consent choices while supporting privacy-conscious measurement modes when consent is withheld.

CookieRay itself does not open separate server-side uploads of visitor payloads to Google; instead it surfaces JavaScript so tags you load can read consent signaling. Behavior of those downstream tags depends on how you configured Google products.

Further reading: Google's Consent Mode documentation (linked under External Services).

= Cookie scanner =

Run a cookie scan from the WordPress admin to detect cookies surfaced on responses from your own site.

When an administrator starts a scan, CookieRay queues work on your server and uses the WordPress HTTP API to request public URLs belonging to **your site domain** (for example the homepage, published content URLs, WooCommerce storefront pages where applicable, and related routes depending on plugins). Cookie names surfaced in responses can be merged with your inventory for categorization alongside the plugin's bundled tracking-cookie reference data.

CookieRay also runs deeper script-discovery passes that analyze HTML fetched from a bounded set of URLs (the embeddable deep scan inspects up to 50 URLs on the same domain, chosen from the XML sitemap when available or from the home page plus recent posts and pages). Scan coverage still depends on plugins, caches, authenticated-only flows, and what responses your site serves to CookieRay's unauthenticated crawler.

There is **no recurring unattended scan timetable built into CookieRay**. You start scans from CookieRay screens (background processing completes those runs without another manual click).

= Cookie inventory =

View, edit, and categorize cookies discovered on your site. Assign cookies to Necessary, Analytical, Functional, or Marketing categories. Bulk-categorize or delete entries. Custom cookies can be added manually, with regex pattern support where appropriate.

= Consent logs =

Consent events can be logged with identifiers, statuses, granted categories, page URL, banner version, and timestamp fields available for review. Entries are searchable and filterable in the admin. You can export records as CSV, and export individual receipts as PDF for your record-keeping process.

= Dashboard =

See a summarized score guided by configurable checks (blocking mode, categorized cookies, policy link usage, consent expiry and scan recency reminders, whether Google Consent Mode output is enabled, and related items). Use it as onboarding guidance alongside your legal or compliance review.

= Scan history =

Review past scans: timing, URLs covered, counts, and summaries of results to track changes over time.

= Settings =

Configure consent behavior (Log Only vs Strict blocking), consent expiry, consent log retention, Google Consent Mode v2 toggle, optional data portability export flows, and related options. Banner and behavior settings are stored through WordPress options alongside plugin metadata.

= Privacy and data handling =

CookieRay does not send usage data, telemetry, or visitor payloads to CookieRay-operated servers. Consent logs and inventories stay in your site's database unless you export them elsewhere.

Third-party trackers you integrate yourself (such as Google Analytics, Google Ads, Google Tag Manager, Meta Pixel, marketing pixels, embedded chat widgets, and similar vendors) may still collect information according to their own tags, configurations, vendor accounts, regions, and published policies, even when CookieRay delays or respects categories for scripts it recognizes.

A WordPress Cron task deletes consent records older than the retention period configured in Settings, whenever WordPress Cron runs normally on your host.

== Installation ==

1. Upload the `cookieray` folder to the `/wp-content/plugins/` directory, or install directly from the WordPress plugin directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **CookieRay** in the admin sidebar. You will be redirected to the Dashboard on first activation.
4. Follow the checklist on the Dashboard to complete your setup.
5. Go to **Settings** and choose your consent behavior (Log Only or Strict blocking).
6. Customize your banner in **Banner Design** and enable it when ready.
7. Run a cookie scan when you are ready to populate or refresh your Cookie Inventory.

== Pro Features ==

* Scheduled automatic cookie scans
* Geo targeting – show or hide the banner by country
* Integration with Google Tag Manager, Meta Pixel, Adobe Experience Platform
* Trust Badge – "Privacy Protected" badge displayed in the site footer
* Premium support and priority updates

[Upgrade to CookieRay Pro](https://wp.cookieray.com/#pricing)


== Frequently Asked Questions ==

= Does CookieRay block tracking scripts automatically? =

Strict blocking delays known tracking scripts from running until consent covers the categories you associate with those tags. Log Only records consent without enforcing blocking; ensure third-party integrations respect consent appropriately in that mode.

= What is Google Consent Mode v2? =

Consent Mode is a mechanism for signaling consent defaults and updates to Google tags loaded on your pages. CookieRay emits the signals those tags consume when Consent Mode is enabled. Google's documentation describes how tags respond when analytics or advertising cookies are refused.

= Does CookieRay send any data to external servers operated by CookieRay? =

No. CookieRay does not send usage data, telemetry, or visitor content to CookieRay-operated servers.

If you install third-party analytics or advertising tags (such as Google Analytics, Google Ads, Google Tag Manager, Meta Pixel, or similar), those vendors may collect or process visitor data according to their own implementations, tags, accounts, region settings, and policies.

See **External Services** for how CookieRay interacts with your own-site HTTP requests during scans and browser-side Consent Mode output.

= How does strict script blocking work? =

In Strict mode CookieRay intervenes early so many known tracking snippets do not execute before consent applies. Detailed techniques can vary between releases; the goal is delaying third-party trackers until consent allows them within the categories CookieRay exposes.

If you temporarily need maximum compatibility troubleshooting, compare behavior against Log Only mode alongside your caching and optimization stack.

= How is visitor IP address data handled? =

CookieRay does **not** store raw IP addresses in consent logs.

Before storage CookieRay derives a compact visitor fingerprint from combined request signals (currently IP plus user-agent material) so raw IP octets never persist verbatim. Separately, CookieRay saves a shortened hash suffix suitable for distinguishing devices in the admin, not the verbatim user-agent string.

Prefer treating stored fields as opaque identifiers suitable for aggregate operational review, not as airtight legal identity proof.

Country hints primarily come from common CDN or reverse-proxy HTTP headers stored by your host stack. Where the optional PHP GeoIP extension is enabled on the server with a local database, CookieRay may perform a server-local lookup without calling an external geolocation API service.

= How is the Dashboard score calculated? =

The checklist contributes to a summarized score reflecting selected setup items (Strict mode, categorized cookies, a configured policy link, recency reminders for scans or consent expiry boundaries, whether Google Consent Mode output remains enabled, and related items), each influencing the weighted display.

Treat the score as internal guidance, not a statutory compliance certification.

= Can I export consent logs? =

Yes. The Consent Logs screen lets you search, filter, and export CSV. Individual PDF receipts are available where supported. Use exports within your organizational policy for retention and auditing.

= Does CookieRay work with page caching plugins? =

The banner initializes from front-end scripts after page load. CookieRay aims to behave sanely alongside common optimization plugins (WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed Cache, SG Optimizer, and others); still verify behavior with your caching rules.

= Does CookieRay work with WordPress Multisite? =

CookieRay targets standard single-site installations. Network-wide multisite setups are not a supported configuration in this release.

= Is there a conflict with other cookie consent plugins? =

Running two consent-management solutions together can duplicate banners or fight over script interception. Disable overlapping plugins while evaluating CookieRay.

= What database tables does CookieRay create? =

CookieRay creates:

* `{prefix}cookieray_cookies` - cookie inventory
* `{prefix}cookieray_consent_logs` - consent audit records
* `{prefix}cookieray_scans` - scanner run summaries
* `{prefix}cookieray_third_party_scripts` - deeper script discovery rows linked to scans

= Where are plugin settings stored? =

Settings reside in WordPress core options. Keys typically include `cookieray_settings`, `cookieray_banner_settings`, and `cookieray_db_version`.

= Does deleting the plugin remove data? =

When you delete CookieRay via **Plugins ▸ Delete**, WordPress triggers `uninstall.php`, which drops CookieRay-owned database tables listed above (with the `{prefix}` that matches your site) and removes those option keys along with CookieRay-managed scheduled purge hooks referenced there.

Manual database backups before deletion remain good practice whenever removing tools that processed visitor consent.

= Does the banner support RTL languages? =

Banner behavior follows WordPress text direction settings for front-end contexts the plugin controls.

== Support ==

For questions, bug reports, or help with configuration, visit the [CookieRay support page](https://wp.cookieray.com/contact).

== External Services ==

CookieRay does not send analytics, telemetry, or visitor data to CookieRay-operated servers.

= 1. Same-site cookie scanner =

When an administrator runs a cookie scan, CookieRay uses the WordPress HTTP API (wp_remote_get) to fetch public URLs belonging to the same WordPress site — for example the homepage, published post URLs, WooCommerce storefront pages, and sitemap entries.

What is sent: Standard HTTP GET requests to your own site's public URLs.
When: Only when an administrator triggers a scan from CookieRay admin screens.
Who receives the requests: Your own web server. No data is sent to CookieRay or any third party.
Source file: includes/class-scanner.php

= 2. Google Consent Mode v2 (optional, admin-enabled) =

When enabled in Settings, CookieRay outputs JavaScript on visitor pages that signals consent state to Google tags you have independently installed (e.g. GA4, Google Ads, Google Tag Manager). CookieRay does not make server-side HTTP calls to Google.

What is sent: Browser-side consent signals read by locally loaded Google tags you control.
When: When Consent Mode is enabled in CookieRay Settings and visitors load pages that include your Google tags.
Who receives downstream data: Google, per your Google account and tag configuration.

Google Privacy Policy: https://policies.google.com/privacy
Google Terms of Service: https://policies.google.com/terms
Google Consent Mode documentation: https://support.google.com/analytics/answer/9976101

== Screenshots ==

1. Dashboard with compliance score, total consent count, recent activity, and one-click site scan.
2. Consent Logs with date range filters, consent type, visitor details, and page URL tracking.
3. Cookie Inventory with category tabs, detected cookies, providers, and bulk actions.
4. Scan History & Orchestration — review past scans and configure a recurring scan schedule.
5. Google Consent Mode v2 settings to connect consent signals to Analytics, Ads, and Tag Manager.
6. Banner Design editor with content controls and real-time live preview.

== Changelog ==

= 1.0.2 =

* Added: Text Color control for the consent banner.
* Added: editable labels and descriptions for the Necessary, Analytical, Functional, and Marketing cookie categories shown in the visitor preferences panel.
* Added: custom day-count input for consent expiration and consent log retention, alongside the existing presets.
* Fixed: Dashboard compliance score and checklist not refreshing immediately after saving Trust Badge, Geo Targeting, Integrations, or License changes (Pro).
* Tested: Compatibility with the latest version of WordPress.

= 1.0.1 =

* Added: Compatibility with the Pro version.
* Fixed: Minor issues to ensure the plugin works properly.

= 1.0.0 =
* Cookie consent banner with card and bar layouts, live preview editor, and nine placement positions.
* Strict script blocking modes for delaying known trackers until matched consent categories.
* Google Consent Mode v2 integration for signaling consent preferences to locally installed Google tags.
* Cookie scanner leveraging WordPress outbound HTTP fetching of same-site URLs when administrators run scans.
* Cookie Inventory with category filters (Necessary, Analytical, Functional, Marketing) and bulk actions.
* Consent Logs with hashed visitor fingerprint handling, CSV export, and PDF receipts where enabled.
* Scan History summaries with duration metrics, discoveries, and error reporting surfaced in admin workflows.
* Dashboard checklist with score guidance tying together blocking mode, housekeeping tasks, scans, expiry policy, optional Google Consent Mode checks, etc.
* Settings page with Consent Behavior, consent expiration timings, retention windows, portability export hooks, cron-driven old-log purges according to retention.
* Responsive admin chrome with mobile-aware navigation behavior.

== Upgrade Notice ==

= 1.0.2 =
Dashboard refresh fix, plus new banner text color, custom category text, and custom expiration controls.

= 1.0.1 =
Features and bug fixes, including updated support and pricing URLs.

= 1.0.0 =
Initial release.

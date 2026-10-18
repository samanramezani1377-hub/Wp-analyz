=== WP Analyz – Private Analytics ===
Contributors: wp-analyz
Tags: analytics, privacy, woocommerce, statistics, self-hosted
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WordPress analytics. No Google Analytics, Google Tag Manager, or third-party analytics script.

== Features ==
* First-party deferred tracker: page views, product/category views, search-result views, scroll depth, outbound clicks, cart and checkout clicks, basic form events, JavaScript errors, TTFB, LCP, CLS, and a clearly labeled interaction-latency estimate.
* Dashboard for visitors, sessions, page views, event counts, purchases, revenue, popular pages, referrer hosts, devices, UTM campaigns, search terms, and performance metric summaries.
* WooCommerce server-side purchase events with deterministic event IDs to prevent duplicate order counts. After consent, first-party session cookies can associate classic and Store API checkout orders with a browser session where supported.
* Consent gate enabled by default for advanced tracking. Optional baseline page/product/category counts and performance metrics run without analytics cookies or browser IDs. Connect an existing consent banner with `window.wpAnalyzConsent('granted')` and `window.wpAnalyzConsent('denied')`.
* Configurable retention, administrator CSV export with spreadsheet-formula mitigation, and WordPress personal-data exporter/eraser hooks.
* Full IP addresses are not stored in event rows. The collection endpoint limits payload size and rate per IP. No third-party analytics requests are made.

== Installation ==
1. Upload the `wp-analyz` folder to `/wp-content/plugins/` or upload the ZIP via Plugins > Add New > Upload Plugin.
2. Activate **WP Analyz – Private Analytics**.
3. Open **WP Analyz** in the WordPress admin menu and review Settings.
4. Aggregate baseline counts are enabled by default. Advanced tracking consent is enabled by default. Connect your existing consent manager:
   * After consent: `window.wpAnalyzConsent('granted');`
   * On rejection or revocation: `window.wpAnalyzConsent('denied');`

The plugin does not provide a consent banner.

== Privacy and limitations ==
* Before consent, baseline mode records page/product/category views and performance metrics without visitor/session IDs, referrer, title, search term, UTM values, interaction events, analytics cookies, localStorage, or sessionStorage. Baseline page and performance data is stored as daily grouped counters, not per-event rows. An essential `wpa_consent=denied` preference cookie persists rejection. After consent, advanced tracking may use first-party cookies to associate WooCommerce orders with sessions.
* By default, visitor IDs are session-scoped and do not reliably identify returning visitors across browser sessions. Persistent IDs use `localStorage` and may require consent.
* Baseline analytics stores daily counters by page path and coarse device/performance dimensions and still requires an appropriate legal basis and transparent disclosure; no-cookie tracking is not automatically exempt from GDPR/ePrivacy rules. Enable it only where applicable law permits it. The plugin does not make a site legally compliant by itself.
* Search terms and UTM campaign values are stored in event data and can contain personal information. Avoid collecting sensitive terms and review your privacy notice.
* Query strings and full referrer URLs are discarded. The plugin stores page paths, external referrer hostnames, and allowlisted UTM source/medium/campaign values.
* LCP and CLS are collected where browser APIs support them. Interaction latency is a lightweight estimate, not a standards-complete INP measurement.
* IP is used transiently for rate limiting and is not stored in event rows. Public collection endpoints can still receive fabricated traffic; use a WAF/rate limits and monitor anomalies.
* This is a technical tool, not legal advice or a guarantee of GDPR/ePrivacy compliance.
* Analytics history is preserved by default on uninstall. To delete the table during uninstall, define `WPA_DELETE_DATA_ON_UNINSTALL` as true in `wp-config.php` before deleting the plugin.

== Developer notes ==
Event endpoint: `POST /wp-json/wp-analyz/v1/event`
Browser endpoint events: `page_view`, `product_view`, `category_view`, `search`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `form_submit`, `outbound_click`, `login`, `sign_up`, `scroll_depth`, `web_vital`, `js_error`. Purchase is accepted only from server-side WooCommerce hooks, never from public browser submissions.
Smoke tests: `php tests/smoke.php` and `node tests/tracker-smoke.js`.

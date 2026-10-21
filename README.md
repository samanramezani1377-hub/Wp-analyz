# WP Analyz — Private WordPress Analytics

WP Analyz is a self-hosted analytics plugin for WordPress and WooCommerce. Events are stored in a dedicated table in the site's own WordPress database. It does not load Google Analytics, Google Tag Manager, or third-party analytics scripts.

## Features

- Two front-end modes: consent-enabled advanced tracking, or a separate lightweight aggregate-only tracker when consent mode is disabled. New installations default to aggregate-only mode; advanced tracking is off until an administrator enables consent mode.
- Aggregate-only mode records page/product/category views, search-result counts (not terms), coarse device/browser classes, scroll/click/cart/checkout/form/error event counts, performance metrics, and aggregate WooCommerce sales without visitor/session IDs, analytics cookies, browser storage, page titles, referrers, search terms, or UTM values.
- Deferred first-party advanced tracker for page views, product/category views, search-result views, scroll depth, outbound clicks, add/remove-to-cart clicks, checkout-button clicks, basic form events, JavaScript errors, TTFB, LCP, CLS, and a clearly labeled interaction-latency estimate.
- Dashboard for consented visitors/sessions, page views, events, purchases, popular pages, referrer hosts, devices, UTM campaigns, search terms, performance metrics, daily trends, custom date ranges, and previous-period comparisons.
- Product activity report compares product views and add-to-cart events, and adds units sold from WooCommerce lookup tables when available; funnel counts are explicitly labeled as event counts rather than unique-user conversion rates.
- WooCommerce order-status summary, idempotent purchase recording, refund events that reduce net revenue, and revenue reports separated by currency.
- Scroll tracking is throttled to animation frames, and report scans use bounded keyset batches with a supporting composite index.
- WooCommerce server-side purchase events with deterministic event IDs to prevent duplicate order counts. After consent, first-party session cookies can associate classic and Store API checkout orders with the browser session where supported.
- Consent gate enabled by default, session-only visitor IDs by default, optional persistent visitor IDs, configurable retention, CSV export with spreadsheet-formula mitigation, and WordPress personal-data exporter/eraser hooks.
- No full IP addresses stored in event rows, an 8 KB request-body limit, per-IP rate limiting, and no third-party analytics requests.

## Installation

1. Upload the `wp-analyz` folder to `/wp-content/plugins/`, or upload the ZIP from **WordPress Admin → Plugins → Add New Plugin → Upload Plugin**.
2. Activate **WP Analyz – Private Analytics**.
3. Open **WP Analyz** in the WordPress admin menu and review its settings.
4. The consent mode is enabled by default. With it enabled, aggregate baseline counts can run before consent (if enabled), while advanced tracking waits for your consent manager. Turning consent mode off switches to a separate aggregate-only tracker and does not load the advanced tracker or its consent API. The plugin itself never displays a consent banner, and this setting does not hide or disable banners provided by other plugins. Connect your existing consent manager only when consent mode is enabled:

```js
window.wpAnalyzConsent('granted'); // after consent
window.wpAnalyzConsent('denied');  // rejection or revocation
```

The plugin does not display a consent banner itself.

## Privacy and limitations

- With consent mode enabled, baseline mode records page views, product/category views, coarse device/browser classes, and performance metrics before consent; these are daily grouped counters without visitor/session IDs. It does not use analytics cookies, localStorage, or sessionStorage. An essential `wpa_consent=denied` preference cookie is kept after rejection so baseline events stop on subsequent pages.
- With consent mode disabled, only `assets/js/tracker-basic.js` is enqueued on the front end (plus the small inline configuration needed to provide the endpoint and current product/category IDs). The advanced `assets/js/tracker.js` and its `window.wpAnalyzConsent` API are not loaded. Aggregate-only mode adds counts for search-result pages and limited interactions, but does not collect search terms, UTM values, page titles, referrers, or visitor/session histories. It does not set cookies or access browser storage and respects an existing explicit `wpa_consent=denied` cookie. After consent, advanced tracking may use `wpa_consent`, `wpa_visitor_id`, and `wpa_session_id` cookies to associate WooCommerce orders with sessions.
- The default visitor ID is held in `sessionStorage`; it does not reliably identify returning visitors across browser sessions. Persistent IDs use `localStorage` and may require consent.
- Aggregate-only counters are grouped by day, event, path, coarse device/browser class, and (for product/category views) object ID. They may still constitute personal data depending on context. They require an appropriate legal basis and transparent disclosure; absence of analytics cookies or visitor IDs does not automatically exempt processing from applicable rules. The plugin does not make a site legally compliant by itself.
- After consent, search terms and UTM campaign values are stored in event data and can contain personal information. Avoid collecting sensitive terms and review your privacy notice.
- Query strings and full referrer URLs are discarded. The plugin stores page paths, external referrer hostnames, and allowlisted UTM source/medium/campaign values.
- LCP and CLS are collected where browser APIs support them. Interaction latency is a lightweight estimate, not a standards-complete INP measurement.
- IP is used transiently for rate limiting and is not saved in event rows. Public collection endpoints can still receive fabricated traffic; use a WAF/rate limits and monitor anomalies.
- This is a technical tool, not legal advice or a guarantee of GDPR/ePrivacy compliance.
- Analytics event rows, daily aggregates, and WooCommerce analytics-context metadata are cleaned according to the configured retention period (daily cleanup processes bounded batches). Deactivating WP Analyz preserves its data. **Deleting the plugin from WordPress permanently removes** its event and aggregate tables, settings/version options, database-backed `wpa_` transient records, scheduled cleanup, and the plugin's five WooCommerce analytics metadata keys from both legacy postmeta and HPOS order-meta tables. WooCommerce order details and other plugins' data are not deleted. The old `WPA_DELETE_DATA_ON_UNINSTALL` constant is no longer required. Uninstall cannot remotely erase cookies or browser storage already held on visitors' devices, server/hosting logs, backups, or transient entries held only in an external object-cache backend; those require separate expiry/retention handling.

## Developer notes

- Event endpoint: `POST /wp-json/wp-analyz/v1/event`
- Purchase is accepted only from server-side WooCommerce hooks, never from public browser submissions.
- Campaign, search-term, and performance summaries scan matching event rows in bounded keyset batches. CSV exports stream event and aggregate rows in batches rather than silently stopping at a fixed 50,000-row cap.
- Revenue is net of recorded full/partial WooCommerce refunds and shown separately by currency; refund/order hooks are idempotency-guarded. Validate gateway-specific refund flows in a staging WooCommerce install before production use.
- Product reports compare view and add-to-cart event counts; funnel metrics are event counts, not unique-user conversion rates. WooCommerce status counts are cached briefly and may lag status changes by up to five minutes.
- WordPress personal-data export/erasure also looks up WooCommerce orders by billing email, including guest orders, and handles their linked purchase analytics.
- Run the smoke tests with `php tests/smoke.php`, `node tests/tracker-smoke.js`, and `node tests/basic-tracker-smoke.js`.
- License: GPL-2.0-or-later.

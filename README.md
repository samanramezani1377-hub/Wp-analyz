# WP Analyz — Private WordPress Analytics

WP Analyz is a self-hosted analytics plugin for WordPress and WooCommerce. Events are stored in a dedicated table in the site's own WordPress database. It does not load Google Analytics, Google Tag Manager, or third-party analytics scripts.

## Features

- Deferred first-party tracker for page views, product/category views, search-result views, scroll depth, outbound clicks, add/remove-to-cart clicks, checkout-button clicks, basic form events, JavaScript errors, TTFB, LCP, CLS, and a clearly labeled interaction-latency estimate.
- Dashboard for visitors, sessions, page views, events, purchases, revenue, popular pages, referrer hosts, devices, UTM campaigns, search terms, performance metric summaries, and daily summaries.
- WooCommerce server-side purchase events with deterministic event IDs to prevent duplicate order counts. After consent, first-party session cookies can associate classic and Store API checkout orders with the browser session where supported.
- Consent gate enabled by default, session-only visitor IDs by default, optional persistent visitor IDs, configurable retention, CSV export with spreadsheet-formula mitigation, and WordPress personal-data exporter/eraser hooks.
- No full IP addresses stored in event rows, an 8 KB request-body limit, per-IP rate limiting, and no third-party analytics requests.

## Installation

1. Upload the `wp-analyz` folder to `/wp-content/plugins/`, or upload the ZIP from **WordPress Admin → Plugins → Add New Plugin → Upload Plugin**.
2. Activate **WP Analyz – Private Analytics**.
3. Open **WP Analyz** in the WordPress admin menu and review its settings.
4. Consent is required by default. Connect your existing consent banner:

```js
window.wpAnalyzConsent('granted'); // after consent
window.wpAnalyzConsent('denied');  // rejection or revocation
```

The plugin does not display a consent banner itself.

## Privacy and limitations

- When consent is required, the tracker does not start before consent. After consent, first-party session cookies (`wpa_consent`, `wpa_visitor_id`, `wpa_session_id`) are used to associate WooCommerce checkout orders with the current analytics session. They are cleared on rejection/revocation. If the browser blocks cookies or the checkout integration does not expose the context, a consent-required purchase may not be recorded.
- The default visitor ID is held in `sessionStorage`; it does not reliably identify returning visitors across browser sessions. Persistent IDs use `localStorage` and may require consent.
- If consent is disabled in settings, the administrator is responsible for establishing an appropriate legal basis. The plugin does not make a site legally compliant by itself.
- Search terms and UTM campaign values are stored in event data and can contain personal information. Avoid collecting sensitive terms and review your privacy notice.
- Query strings and full referrer URLs are discarded. The plugin stores page paths, external referrer hostnames, and allowlisted UTM source/medium/campaign values.
- LCP and CLS are collected where browser APIs support them. Interaction latency is a lightweight estimate, not a standards-complete INP measurement.
- IP is used transiently for rate limiting and is not saved in event rows. Public collection endpoints can still receive fabricated traffic; use a WAF/rate limits and monitor anomalies.
- This is a technical tool, not legal advice or a guarantee of GDPR/ePrivacy compliance.
- Analytics history is preserved by default on uninstall. To delete the table during uninstall, define `WPA_DELETE_DATA_ON_UNINSTALL` as true in `wp-config.php` before deleting the plugin.

## Developer notes

- Event endpoint: `POST /wp-json/wp-analyz/v1/event`
- Purchase is accepted only from server-side WooCommerce hooks, never from public browser submissions.
- Run the smoke tests with `php tests/smoke.php` and `node tests/tracker-smoke.js`.
- License: GPL-2.0-or-later.

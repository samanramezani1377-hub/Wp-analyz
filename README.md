# WP Analyz — Private WordPress Analytics

A self-hosted WordPress and WooCommerce analytics plugin. Event data is stored in a dedicated table in the site's own WordPress database. It does not load Google Analytics, Google Tag Manager, or any third-party analytics scripts.

## Features

- Lightweight deferred first-party tracker for page views, product/category views, search, scroll depth, outbound clicks, add/remove-to-cart clicks, checkout-button clicks, basic form events, and navigation timing.
- Admin dashboard for visitors, sessions, page views, events, purchases, revenue, popular pages, referrer hosts, devices, and daily summaries.
- WooCommerce server-side purchase events for paid orders, with deterministic event IDs to prevent duplicate purchase counts.
- Consent gate enabled by default, session-only browser identifiers by default, optional persistent visitor IDs, configurable retention, CSV export, and WordPress personal-data exporter/eraser hooks.
- Full IP addresses are not stored in the analytics events table; no third-party analytics network calls are made.

## Installation

1. Upload the `wp-analyz` folder to `/wp-content/plugins/`, or upload the ZIP from **WordPress Admin → Plugins → Add New Plugin → Upload Plugin**.
2. Activate **WP Analyz – Private Analytics**.
3. Open **WP Analyz** in the WordPress admin menu and review its settings.
4. Consent is required by default. Connect your existing consent banner by calling:

```js
window.wpAnalyzConsent('granted'); // after consent
window.wpAnalyzConsent('denied');  // rejection or revocation
```

This plugin does not display a consent banner itself.

## Privacy and limitations

- The default visitor ID is held in `sessionStorage`; it does not reliably identify returning visitors across browser sessions. Persistent IDs use `localStorage` and may require consent.
- Server-side WooCommerce purchases are recorded independently of the thank-you page, but are not automatically joined to a browser session.
- Search terms can contain personal information. Review your legal basis and avoid collecting sensitive or unnecessary search data.
- Query strings and full referrer URLs are discarded. Only the page path and external referrer hostname are stored.
- IP is used transiently for rate limiting and is not saved in event rows. Public collection endpoints can still receive fabricated traffic; use a WAF/rate limits and monitor anomalies in production.
- This is a technical tool, not legal advice or a guarantee of GDPR/ePrivacy compliance.
- Analytics history is preserved by default on uninstall. To delete the table during uninstall, define `WPA_DELETE_DATA_ON_UNINSTALL` as true in `wp-config.php` before deleting the plugin.

## Developer notes

- Event endpoint: `POST /wp-json/wp-analyz/v1/event`
- Allowed events: `page_view`, `product_view`, `category_view`, `search`, `add_to_cart`, `remove_from_cart`, `begin_checkout`, `form_submit`, `outbound_click`, `purchase`, `login`, `sign_up`, `scroll_depth`, `web_vital`, `js_error`.
- PHP lint: `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
- License: GPL-2.0-or-later.

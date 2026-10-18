=== WP Analyz – Private Analytics ===
Contributors: wp-analyz
Tags: analytics, privacy, woocommerce, statistics, self-hosted
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Self-hosted WordPress analytics. No Google Analytics, Google Tag Manager, or third-party analytics script.

== Features ==
* First-party deferred tracker: page views, product/category views, search, scroll depth, outbound clicks, cart clicks, checkout click, form submits, navigation timing, LCP/CLS and a clearly labeled interaction-latency estimate, LCP/CLS and a clearly labeled interaction-latency estimate.
* Dashboard for visitors, sessions, page views, event counts, purchases, revenue, popular pages, referrer hosts, devices, UTM campaigns, search terms, and performance metric summaries.
* WooCommerce server-side purchase events for paid orders, with deterministic event IDs to avoid duplicate purchase counts. After consent, first-party session cookies let classic and Store API checkout flows associate paid orders with the browser session where supported.
* Consent gate enabled by default; session-only visitor IDs by default; optional persistent visitor IDs. Consent is integrated through `window.wpAnalyzConsent('granted')` / `('denied')`; the plugin does not provide its own banner.
* Retention cleanup, administrator CSV export, privacy policy text and WordPress personal data exporter/eraser hooks.
* Does not persist full IP addresses in the analytics events table and makes no third-party analytics requests.

== Installation ==
1. Upload the `wp-analyz` folder to `/wp-content/plugins/` or upload the ZIP via Plugins > Add New > Upload Plugin.
2. Activate **WP Analyz – Private Analytics**.
3. Open **WP Analyz** in the WordPress admin menu and review Settings.
4. Consent is required by default. Connect your existing consent banner by calling `window.wpAnalyzConsent('granted')` after consent and `window.wpAnalyzConsent('denied')` after rejection/revocation.

== Privacy and limitations ==
* This plugin does not display a consent banner; integrate it with your actual consent-management system. While the consent gate is enabled, server-side purchase analytics are also suppressed. Only disable the consent gate if you have established an appropriate legal basis for collecting the relevant analytics.
* The default visitor ID lives in sessionStorage and does not reliably identify returning visitors across sessions. Persistent IDs use localStorage and may require consent.
* WooCommerce purchases are recorded server-side, but are not automatically joined to the browser session. They count paid orders without depending on the thank-you page.
* Search terms may contain personal information. Review your legal basis and avoid collecting sensitive or unnecessary search data.
* Query strings and full referrer URLs are discarded. Only page path and external referrer hostname are stored; the allowlisted UTM source/medium/campaign parameters and search terms are stored in event data. Search terms can contain personal information.\n* LCP and CLS are collected where browser APIs support them. Interaction latency is reported as a lightweight estimate, not a standards-complete INP metric.
* IP is used transiently for rate limiting, not saved in event rows. A public endpoint can still receive forged traffic; use WAF/rate limits and monitor anomalies in production.
* This is a technical tool, not legal advice or a guarantee of GDPR/ePrivacy compliance.
* Analytics history is preserved by default on uninstall. To delete the table during uninstall, define `WPA_DELETE_DATA_ON_UNINSTALL` as true in wp-config.php before deleting the plugin.

== Developer notes ==
Event endpoint: `POST /wp-json/wp-analyz/v1/event`
Browser endpoint events: page_view, product_view, category_view, search, add_to_cart, remove_from_cart, begin_checkout, form_submit, outbound_click, login, sign_up, scroll_depth, web_vital, js_error. Purchase is accepted only from server-side WooCommerce hooks, never from public browser submissions.

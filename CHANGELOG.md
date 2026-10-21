## 1.4.2

- Default configuration now starts in aggregate-only mode: consent-required advanced analytics is off unless an administrator explicitly enables it in settings. Existing saved administrator preferences are preserved.

## 1.4.1

- Deleting WP Analyz now removes its analytics tables, settings, database-backed transient caches, scheduled cleanup, and its WooCommerce analytics metadata from legacy and HPOS storage. Deactivation still preserves data.
- Removed the opt-in `WPA_DELETE_DATA_ON_UNINSTALL` requirement; plugin deletion now performs cleanup by default.
- Documented limits that WordPress cannot remotely erase browser-local storage, cookies on visitors' devices, host/server logs, backups, or cache-only entries held solely by an external object-cache backend.

## 1.4.0

- Added custom date-range filters and previous-period comparisons for event, page-view, and purchase counts.
- Added product-view versus add-to-cart reporting, WooCommerce product units sold when lookup tables are available, event-count funnel reporting, and cached WooCommerce order-status counts.
- Separated revenue by currency and added idempotent WooCommerce purchase markers to avoid counting one order multiple times across status hooks.
- Added full/partial refund events that reduce net revenue by currency, with duplicate-refund protection and retention cleanup of analytics metadata.
- Added a composite index for bounded event-data report scans and upper-bound date filtering for dashboard and CSV reports.
- Throttled scroll-depth measurement to animation frames and attributed cart interactions to product IDs without adding visitor IDs in aggregate-only mode.
- Bumped plugin version to 1.4.0 and expanded smoke tests.

- Added a consent-mode setting: enabled mode retains the consent-gated advanced tracker; disabled mode loads only the aggregate-only tracker.
- Aggregate-only mode never sets analytics cookies or uses local/session storage or visitor/session IDs, and omits page titles, referrers, search terms, and UTM values.
- Added aggregate counts for search-result pages and limited interaction events without per-visitor attribution.
- When consent mode is disabled, the advanced tracker and its consent API are not enqueued on the public front end; if aggregate analytics are also disabled, no analytics tracker is loaded.
- Expanded CI smoke tests for aggregate-only mode.

## 1.2.1

- Respect the global analytics-enabled switch in WooCommerce purchase recording.
- Apply configured retention to WooCommerce analytics-consent and visitor/session metadata.
- Include guest WooCommerce orders and linked purchase events in WordPress personal-data export/erasure by billing email.
- Remove the 5,000-row report cap for campaign, search-term, and performance summaries by processing rows in bounded keyset batches.

# Changelog

## 1.2.0 — 2026-10-18

- Added a dedicated daily aggregate table for baseline page/product/category views and performance metrics; no per-event timestamp or visitor/session ID is stored for baseline events.
- Restricted pre-consent browser events to page/product/category views and performance metrics; omitted page titles, referrers, searches, UTM campaigns, and interaction events.
- Added daily aggregate WooCommerce purchase counts and revenue without customer/order IDs or visitor/session attribution when no consent choice has been made.
- Explicit rejection persists in an essential consent-preference cookie and suppresses future browser and purchase analytics.
- Added schema version 3 migration, daily retention cleanup, aggregate dashboard reporting, and CSV export support.
- Clarified that no-cookie analytics still needs an appropriate legal basis and transparent disclosure and is not automatically exempt from GDPR/ePrivacy rules.
- Updated PHP and JavaScript smoke tests.
## 1.1.0 — 2026-10-18

- Added UTM campaign, search-term, and page-performance summaries to the admin dashboard.
- Added browser measurements for TTFB, LCP, CLS, and a clearly labeled interaction-latency estimate.
- Added consent-aware first-party session cookies for WooCommerce checkout attribution.
- Added support for classic checkout and WooCommerce Store API order-context capture.
- Added request body limits and CSV spreadsheet-formula mitigation.
- Improved inline configuration escaping and added paginated privacy export/erasure for analytics-linked WooCommerce order metadata.
- Added PHP and JavaScript smoke tests to GitHub Actions.
- Clarified that the plugin does not include its own consent banner and that legal compliance depends on site configuration and jurisdiction.

## 1.0.0 — Initial prototype

- Added self-hosted event collection, dashboard, retention cleanup, CSV export, basic WooCommerce purchase events, and WordPress privacy hooks.

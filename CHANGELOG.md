## 1.3.0

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

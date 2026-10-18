# Changelog

## 1.2.0 — 2026-10-18

- Added an identifier-free baseline mode without analytics cookies or browser storage before consent.
- Bumped the database schema version so existing installations migrate the event identifier columns safely.
- Restricted pre-consent events to page/product/category views and performance metrics; omitted titles, referrers, searches, campaigns, and interaction events.
- Added aggregate-only WooCommerce purchase reporting without customer/order IDs or visitor/session attribution when no choice has been made; explicit rejection suppresses future purchase analytics.
- Clarified that baseline records are event-level and still need an appropriate legal basis and transparent disclosure.
- Updated PHP/JavaScript smoke tests and privacy documentation.

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

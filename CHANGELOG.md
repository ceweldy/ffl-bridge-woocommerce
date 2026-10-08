# Changelog

All notable changes to FFL Bridge for WooCommerce are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Hybrid dealer selection, the default for new installs: verified network dealers first, then nearby ATF-listed dealers labeled "License not verified" or "Transfer not confirmed", all selectable. Dealers that declined transfers are never shown. A "Confirmed dealers only" mode remains. Existing installs keep their saved behavior and see a one-click offer to switch.
- Shopper follow-up instructions after choosing a dealer that is not fully verified, on checkout, the thank-you page, My Account, and customer emails, asking the dealer to send the store a license copy. New "License copy email" (defaults to the admin email) and optional "License copy fax" settings, and a `ffl_bridge_followup_instructions` filter.
- "Mark transfer confirmed" on the order screen with an optional private license file (PDF, JPG, or PNG, up to 5 MB). Records who and when, adds an order note, and reports to FFL Bridge `POST /api/v1/dealers/{id}/transfer-confirmations` when available, keeping the local confirmation if the endpoint is missing or fails.

### Fixed

- Order metadata and labels keep transfer acceptance, license verification, and the selection basis separate, so a dealer with confirmed transfers but no verified license copy is no longer shown as unconfirmed for transfers.
- The coverage-gap log stores daily buckets, so each event expires 30 days after it happened even when an area keeps getting new searches.

- Clear shopper messages when no dealer confirmed to accept transfers is found, with radius advice and wording that depends on whether a dealer is required.
- A coverage log and dismissible admin notice listing three-digit ZIP areas where shoppers found no confirmed transfer dealer, plus a coverage table on the settings page.
- A ZIP and radius coverage check in the settings connection test.
- Support for FFL Bridge search coverage counts, `emptyReason`, the `unconfirmedTier` fallback list, and per-dealer `transferStatus` when the API sends them. Older API responses keep working.
- A clear message when the API cannot locate a ZIP code.

- Checkout labels that separate the verified checkout network from public directory listings. Directory-only dealers are shown for reference without a select button and never receive a selection handle.
- A "Dealers shown at checkout" setting to show the verified network with labeled directory listings (default) or the verified network only.
- A "Store preferred dealers" setting. Dealers whose license numbers are on the list are labeled and listed first in search results. The list stays in WordPress.
- Network coverage in the settings connection test, from one sample search.
- A prospect gap review in `docs/prospect-gap-review.md`.

### Changed

- Settings copy explains the public directory and the verified checkout network, and warns that a required selection blocks shoppers with no verified dealer nearby.
- Removed the unused `FFL_Bridge_API_Client::test_connection()` helper.

## [1.1.0] - 2026-07-11

### Security

- Moved authenticated FFL Bridge requests to WordPress so the merchant API key is no longer sent to checkout browsers.
- Replaced trust in browser-submitted dealer details with server retrieval of the selected dealer record before it is stored on an order.

### Added

- WooCommerce Checkout Block integration alongside classic checkout support.
- High-Performance Order Storage compatibility declarations and order-list display support.
- Suggested WordPress privacy-policy text for the external FFL Bridge service.
- Unit coverage for API normalization, signed selections, checkout decisions, and order-metadata compatibility.
- Reproducible release packaging, coding-standard checks, dependency updates, and least-privilege GitHub Actions workflows.

### Changed

- Raised minimum versions to WordPress 6.9, PHP 8.3, and WooCommerce 10.8.
- Clarified that the plugin records dealer metadata for fulfillment and does not perform shipment, dealer contact, or legal compliance checks.

## [1.0.0] - 2026-02-06

### Added

- Initial proof-of-concept checkout selector and WooCommerce order metadata display.

[Unreleased]: https://github.com/ceweldy/ffl-bridge-woocommerce/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/ceweldy/ffl-bridge-woocommerce/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ceweldy/ffl-bridge-woocommerce/releases/tag/v1.0.0

# Changelog

All notable changes to FFL Bridge for WooCommerce are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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

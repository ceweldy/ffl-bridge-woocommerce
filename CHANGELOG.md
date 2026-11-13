# Changelog

All notable changes to FFL Bridge for WooCommerce are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases use [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- The dealer notice is recorded as sent only when FFL Bridge confirms it. The order shows whether FFL Bridge sent, queued, skipped or failed the dealer email, or "requested" when FFL Bridge could not confirm it. Before, any accepted order was recorded as sent, even when FFL Bridge reported that the dealer email failed.
- The admin connection test says why FFL Bridge rejected the request: an invalid or revoked key (which also covers an unverified account on the current API), an unverified account email, a server key with an allowed-domain list that leaves out this site, a key that is not allowed to search, the rate limit, or a server that cannot reach www.fflbridge.com. Shoppers see one short message instead of account details, and the specific reason is written to the WooCommerce log.
- Checkout Block and Store API orders without a required dealer fail with HTTP 400 `ffl_bridge_selection_required` and the message "Select and verify a transfer dealer before placing the order." instead of a 500 `woocommerce_rest_unknown_server_error`.
- A selected product category also covers its child categories, so a product in Firearms > Rifles uses the dealer workflow when only Firearms is selected.
- The order-received page shows the selected transfer dealer once instead of twice. The My Account order view is unchanged.

### Changed

- The readme and README say the plugin needs an account with a verified email and a server API key (starts with `ffl_live_`), and that no domain or site address is needed on that key.

### Added

- Optional "FFL Bridge API base URL" setting for testing against an FFL Bridge preview or staging server. It defaults to `https://www.fflbridge.com/api/v1` and accepts only HTTPS URLs without credentials, query strings, or fragments. Precedence: the `FFL_BRIDGE_API_BASE_URL` constant in `wp-config.php`, then the `ffl_bridge_api_base_url` filter, then the saved setting, then the default; an invalid value at any level is skipped. A new `ffl_bridge_api_request_headers` filter can add extra request headers (for example `x-vercel-protection-bypass` for a protected preview); it cannot replace the plugin's own headers, and header values are never logged.
- Optional setting "Email the selected dealer when an order is placed", off by default. When a store turns it on, after an order with a verified dealer is saved on classic or Block checkout, the plugin asks FFL Bridge (`POST /api/v1/orders` with `sendFflEmail: true` and `sendCustomerEmail: false`) to email the dealer, sending only the dealer identifier, the shopper's billing name and email address, and the order number. The request is sent once per order with a 6 second timeout; a failure is logged and noted on the order and never fails checkout. With the setting off, no shopper data is sent. The plugin still never emails dealers itself.
- Unit tests for each API error code, the admin connection test text, the shopper search message, the Store API exception, child categories and the order-received block, and integration tests for the Store API checkout, child categories, the order-received page and the connection test.

## [1.2.1] - 2026-10-10

### Security

- Settings saves through `options.php` require `manage_woocommerce` instead of the default `manage_options`.
- Removing the saved API key requires a valid settings nonce and the `manage_woocommerce` capability.
- The transfer confirmation and license file handlers check the capability before reading the order ID, and the hybrid offer handler rejects unknown choices.

### Changed

- The hybrid offer notice shows only on the plugin settings page. The coverage notice shows only on the settings page and WooCommerce order screens, and stays dismissible per user. The WooCommerce dependency notice shows only on the Plugins screen.

### Added

- Tests that requests without a nonce or capability are rejected and that notices stay on the plugin's screens.

## [1.2.0] - 2026-10-08

### Added

- Automatic updates from FFL Bridge. The plugin reads a manifest at `https://fflbridge.com/api/plugins/woocommerce/update` (HTTPS only, cached for 12 hours, silent when unreachable), shows "Update available" and a "View details" modal, supports WordPress auto-updates without turning them on, and checks every downloaded package against the manifest sha256 before installing. Installs older than 1.2.0 need one manual update to 1.2.0 to get the checker.

- Hybrid dealer selection, the default for new installs: verified network dealers first, then nearby ATF-listed dealers labeled "License not verified" or "Transfer not confirmed", all selectable. Dealers that declined transfers are never shown. A "Confirmed dealers only" mode remains. Existing installs keep their saved behavior and see a one-click offer to switch.
- Shopper follow-up instructions after choosing a dealer that is not fully verified, on checkout, the thank-you page, My Account, and customer emails, asking the dealer to send the store a license copy. New "License copy email" (defaults to the admin email) and optional "License copy fax" settings, and a `ffl_bridge_followup_instructions` filter.
- "Mark transfer confirmed" on the order screen with an optional private license file (PDF, JPG, or PNG, up to 5 MB). Records who and when, adds an order note, and reports to FFL Bridge `POST /api/v1/dealers/{id}/transfer-confirmations` when available, keeping the local confirmation if the endpoint is missing or fails.

### Fixed

- Order metadata and labels keep transfer acceptance, license verification, and the selection basis separate, so a dealer with confirmed transfers but no verified license copy is no longer shown as unconfirmed for transfers.
- The coverage-gap log stores daily buckets, so each day of searches expires 30 days after its last search, even when an area keeps getting new searches.

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

[1.2.1]: https://github.com/ceweldy/ffl-bridge-woocommerce/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/ceweldy/ffl-bridge-woocommerce/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/ceweldy/ffl-bridge-woocommerce/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/ceweldy/ffl-bridge-woocommerce/releases/tag/v1.0.0

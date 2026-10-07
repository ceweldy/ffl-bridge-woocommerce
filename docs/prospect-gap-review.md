# Prospect gap review: FFL Bridge for WooCommerce

Date: 2026-10-07
Plugin reviewed: `ceweldy/ffl-bridge-woocommerce` at 1.1.0 (`3b3a719`)
API reviewed: `ceweldy/ffl-bridge` at `2af2f6d` (main, 2026-10-03)

## Context and assumptions

A prospective merchant emailed support@fflbridge.com. We do not have the exact text yet. This review assumes the four likely asks below. Revisit it when the email is available.

1. **Merchant preferred dealers at checkout.** The merchant wants its own list of dealers (for example, shops it already works with) to appear at checkout. That includes dealers that are still pending verification.
2. **Directory vs verified network.** The merchant wants a clear line between the public directory (roughly 77k ATF-listed dealers) and the smaller verified network that can actually be selected at checkout.
3. **Order status updates and webhooks.** The merchant wants transfer status (for example, dealer received the license, ready to ship, shipped) to flow back into WooCommerce automatically.
4. **Secure FFL license copy sharing.** The merchant wants to send or receive FFL license copies securely with the dealer the shopper selected.

Other assumptions:

- "Preferred dealers" means a list owned by one merchant, not the global `ffls.is_preferred` flag in the API.
- "Pending verification" means a dealer whose license copy was uploaded or claimed but not yet approved by FFL Bridge.
- License sharing direction is not stated. Shippers usually need the receiving dealer's license copy before shipping. Some merchants also send their own license to the dealer. Both directions are covered below.
- The production numbers below come from the API repo's `backend-release-contract.md` audit dated early October 2026. They were not re-measured for this review.

## Headline findings

- **The verified checkout network is currently empty in production.** The API's own release audit reports 77,514 active listed dealers, "zero independently verified ever/current and zero eligible". Until FFL Bridge verifies dealers, any merchant with **Require a selection = Yes** cannot complete a checkout for applicable products. That matters more than any of the four asks and should be raised with the prospect before onboarding.
- **Before this change, the plugin showed directory dealers that could never be selected.** `/api/v1/search` returns every ATF-listed active dealer in the radius and marks the eligible ones with `checkoutEligible`. Plugin 1.1.0 ignored that flag. A shopper could pick any result and only then get "That dealer is not currently selectable." This PR fixes that on the plugin side.

## Ask-by-ask review

### 1. Merchant preferred dealers, including pending verification

**What works today**

- Server-side search, selection, and re-verification of a dealer before the order is saved.
- The API has a global `isPreferred` boolean on each dealer, returned by search and detail endpoints. It is one flag for the whole platform, not per merchant, and nothing in the API lets a merchant set it.

**Plugin changes (done in this PR)**

- New setting **Store preferred dealers**: a list of up to 100 FFL license numbers, stored only in WordPress. Matching dealers get a "Store preferred dealer" label and are listed first within their group when they appear in a shopper's search.
- A preferred dealer that is not in the verified network still shows as "Directory listing only" and cannot be selected. The plugin will not bypass the API's eligibility rule.

**Needs API work in `ceweldy/ffl-bridge`**

- **Per-merchant preferred dealers.** Needs a table keyed by API customer and dealer, plus endpoints for the merchant to manage it. Search should also be able to include preferred dealers outside the shopper's radius (the plugin list only labels dealers that already appear in results).
- **Selecting pending-verification dealers.** Not supported. Checkout eligibility (`eligibleFflConditions`, `deriveFflEligibility`) requires a current verified license, and `GET /ffls/{id}` returns `selectable: false` otherwise. `POST /orders` rejects them too. Allowing this needs a product and compliance decision first. Then it needs a distinct eligibility state (for example `selectable_pending`) that the plugin can opt into per merchant, and order metadata recording that the dealer was unverified at selection time.
- **Exposing "pending" in search.** Search only returns `isVerified` and `checkoutEligible`. It does not separate "license uploaded, awaiting review" from "no license on file". The detail endpoint does (`licenseOnFile: true`, `licenseVerified: false`), but calling it for every result would multiply API usage. A `verificationStatus` field on search results (`verified`, `pending`, `none`) would let the plugin label pending dealers accurately.

### 2. Public directory vs verified checkout network

**What works today**

- The API already makes the distinction. Search returns `isVerified` and `checkoutEligible`, and sorts eligible dealers ahead of others (after sponsored ones). The detail endpoint returns an `eligibility` object, and the plugin already requires `selectable` and `licenseVerified` before saving a selection.

**Plugin changes (done in this PR)**

- The plugin now reads `checkoutEligible` from search results. Each result is classified as verified network, directory-only, or unknown (the API did not send the flag, so 1.1.0 behavior is kept).
- Directory-only results get a "Directory listing only" label, a short explanation, and **no select button**. They never get a selection handle on the server, so they cannot be selected by tampering with the page.
- Verified results get a "Verified checkout network" label.
- If a search finds no selectable dealer, the shopper sees "No dealers in this area are in the verified checkout network yet" instead of a generic error after clicking.
- New setting **Dealers shown at checkout**: "Verified network first, plus labeled directory listings" (default) or "Verified checkout network only".
- The settings page explains the difference between the directory and the verified network. The **Require a selection** help text now warns that required checkout blocks shoppers with no verified dealer nearby.
- The connection test now reports coverage from one sample search, for example "25 directory listings, 0 in the verified checkout network". Merchants can see the gap before going live.
- The same shared script powers classic checkout and the Checkout Block, so both get the new behavior.

**Needs API work**

- The verified network needs dealers (operations work, not code). The release contract lists "New verified-network workflow and queues" as separate follow-up scope.
- Optional: a lightweight coverage endpoint (verified count near a ZIP or state) so the plugin can warn on the settings page without spending a search call.
- Optional: per-result sponsored disclosure. Search sorts paid promotions first and returns `isPromoted` and `promotionLabel`. The plugin does not show that label yet (see "Not changed" below).

### 3. Order status updates and webhooks

**What works today**

- The plugin stores the verified dealer on the WooCommerce order (HPOS compatible) and shows it in admin, emails, the thank-you page, and My Account.
- The API has `POST /api/v1/orders` (merchant API key). It creates an FFL Bridge order for an eligible dealer and can email the customer.
- The API has `GET` and `PUT /api/v1/orders/{id}/status` with statuses `pending_ffl`, `ffl_requested`, `ffl_received`, `ready_to_ship`, `shipped`, `cancelled`.

**Gaps**

- The status endpoints, and `GET /orders`, are **admin-key only**. The OpenAPI document says "Administrative read until orders are associated with an authenticated tenant." A merchant key cannot read or poll status.
- The only outbound webhook is one platform-wide `ORDER_WEBHOOK_URL` environment variable. It fires once, on order creation, to an internal endpoint. There is no per-merchant webhook registration, and no event on status change.
- The plugin does not call `POST /orders`. Doing so would send the shopper's name and email to FFL Bridge, and by default triggers an FFL Bridge customer email. That changes the privacy disclosure and the merchant's customer communications, so it should be opt-in and is not part of this PR.

**Plugin changes (not done, depend on the API)**

- Opt-in "register orders with FFL Bridge" setting that calls `POST /orders` after payment, with `externalOrderId` set to the WooCommerce order ID and `sendCustomerEmail: false` by default. The FFL Bridge order ID would be stored in order meta, and the privacy text updated.
- A signed webhook receiver (WordPress REST route) that verifies an HMAC signature, timestamp, and nonce, maps FFL Bridge statuses to WooCommerce order notes or custom statuses, and is idempotent.
- No receiver was built in this PR, because the API has no merchant-facing webhook or status contract to build against. A speculative receiver would be an unauthenticated endpoint with no sender.

**Needs API work**

- Scope orders to the merchant (`customerId` is already stored) and allow merchant API keys to read their own orders and status.
- Per-merchant webhook endpoints with a per-endpoint signing secret, events for `order.created` and `order.status_changed`, retries with backoff, and a delivery log. The existing `ts.nonce.body` HMAC scheme is a reasonable base.
- Decide who moves an order through statuses (merchant, dealer, or FFL Bridge staff) and expose that to the right role.
- Fix `sendFflEmail`, which is accepted by `POST /orders` but not acted on, and `needsFflLicense`, which is hard-coded.

### 4. Secure FFL license copy sharing with the selected dealer

**What works today**

- The plugin stores whether FFL Bridge has a license copy on file (`_ffl_bridge_license_on_file`) and whether it is verified, and shows both to store staff on the order.
- The API stores license copies in `registered_ffls.license_file_url`, and dealers can upload a copy through the claim flow.

**Gaps**

- `GET /api/v1/ffls/{id}/license` is admin-key only and returns the raw stored URL. There is no merchant access, no short-lived signed link, and no audit of who viewed it.
- There is no way to send the merchant's own license to the selected dealer.
- The plugin cannot show or send a license copy, and should not store license documents in WordPress.

**Needs API work first**

- A merchant-scoped endpoint, for example `POST /orders/{id}/license-link`, that checks the merchant has an order with that dealer and returns a short-lived signed URL (minutes, single use if possible). Each access should be logged.
- A matching flow for the merchant to upload its own license once and let FFL Bridge share it with the selected dealer, ideally tied to the order and the `ffl_requested` status.
- Retention and access rules for license documents in the privacy policy and terms.

**Plugin changes after that**

- An order admin action "Get dealer license copy" that requests a signed link server-side and opens it. The link would never be stored in order meta or emailed.
- Optional automatic send of the merchant license on order registration.

## Not changed in this PR

- **Sponsored labels.** Search sorts paid promotions first. The plugin keeps that order for non-preferred dealers but does not show the "Sponsored" label. Adding it is small and probably wise for disclosure, but it is outside the four asks.
- **Order metadata.** No new order meta keys. Whether a selected dealer was on the store preferred list is not recorded on the order.
- **The plugin copy inside `ceweldy/ffl-bridge`.** That repo carries `plugins/ffl-bridge-woocommerce/` and `public/downloads/ffl-bridge-woocommerce.zip`, checked by `scripts/verify-plugin-artifact.mjs` against version 1.1.0 and a fixed SHA-256. Releasing these changes means updating that copy, the ZIP, the digest, and the version check in that repo.

## Compatibility notes

- New options `ffl_bridge_result_scope` (default `all`) and `ffl_bridge_preferred_licenses` (default empty). Existing sites need no migration and keep working without saving settings.
- If the API omits `checkoutEligible`, every result stays selectable and is verified on selection, as in 1.1.0.
- Search results cached by 1.1.0 (up to five minutes) have no network flag. They are treated as unknown until they expire.
- The preferred list never leaves WordPress, so the privacy disclosure is unchanged.
- The unused `FFL_Bridge_API_Client::test_connection()` helper was removed. The settings connection test now calls `search()` directly with a larger sample.

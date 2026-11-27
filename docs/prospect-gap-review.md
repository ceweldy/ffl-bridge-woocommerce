# Prospect gap review: FFL Bridge for WooCommerce

Date: 2026-10-07
Plugin reviewed: `ceweldy/ffl-bridge-woocommerce` at 1.1.0 (`3b3a719`)
API reviewed: `ceweldy/ffl-bridge` at `2af2f6d` (main, 2026-10-03)

## Update 2026-10-08: hybrid dealer selection

Connor approved a hybrid flow that replaces the opt-in fallback described below.

- **Hybrid is the default for new installs.** Shoppers can select verified network dealers, dealers that confirmed transfers but have no verified license copy, and nearby ATF-listed dealers with no transfer information. Dealers that declined transfers are never listed. Checkout is never empty just because network coverage is zero.
- **Existing installs keep their saved behavior.** Sites with plugin settings but no saved mode become "Confirmed dealers only", or hybrid if the earlier fallback was on. Confirmed-only sites see an admin notice with "Switch to hybrid" and "Keep confirmed dealers only".
- **Follow-up, not blocking.** After choosing a dealer that is not fully verified, the shopper is asked to contact the dealer, confirm they will accept the transfer, and have them email (or fax) a copy of their current FFL to the store. The same text appears on the thank-you page, in My Account, and in customer emails.
- **Store confirmation.** "Mark transfer confirmed" on the order records who and when, stores an optional license file privately, adds an order note, and calls `POST /api/v1/dealers/{dealerId}/transfer-confirmations` when available.
- **Codex findings from #6.** Order meta now keeps transfer acceptance, license verification, and selection basis separate. The coverage log expires each event after 30 days.

## The prospect's actual request

The email arrived after the first draft of this review. The retailer searches near **ZIP 48047 (New Baltimore, Michigan)** with `acceptsTransfers=true` and gets **zero results, even at 100 miles**. They want only transfer-accepting dealers at checkout.

Priorities for this PR, in order:

1. Handle zero transfer-accepting results gracefully at checkout. Shoppers get a clear message, and merchants get an admin notice about coverage.
2. Add an optional, merchant-controlled fallback, off by default. It shows nearby dealers labeled "Transfer not confirmed, contact the dealer to confirm".
3. Keep the `checkoutEligible` fix from the first draft.
4. Read coverage counts or a zero-result reason from the API when present, and work without them when absent.

## Why the search returns zero

This comes from reading `ceweldy/ffl-bridge` at `2af2f6d`. It was not checked against production data.

- `/api/v1/search` with `acceptsTransfers=true` adds `f.accepts_transfers = true` to the query (`src/lib/ffl-search.ts`).
- `ffls.accepts_transfers` defaults to `false`. ATF data does not say whether a dealer accepts transfers, so the ATF import does not set it.
- Only two paths set it to `true`: an admin approving a dealer claim (`admin/ffl-claims/[id]/review`) and admin license verification (`admin/ffls/[id]/verification`). The seed script sets it only for sample Florida dealers.
- So the filter returns only dealers FFL Bridge staff have reviewed. That is close to zero nationwide, and zero near 48047. ZIP 48047 is in the API's ZIP centroid table, so this is not a location lookup failure.
- Even a transfer-accepting dealer is selectable at checkout only with a verified, unexpired license copy. The API's release audit reports zero such dealers in production.

**Bottom line:** no plugin change can produce confirmed transfer dealers in Michigan. The plugin can only fail clearly, tell the merchant where coverage is missing, and, if the merchant opts in, offer unconfirmed nearby dealers. Real coverage needs API data and operations work (below).

## What this PR does for the request

**Zero results at checkout (classic and block)**

- When no confirmed dealer is found, the shopper sees a specific message instead of an empty list or a generic error. For example: "No dealers within 100 miles of 48047 are confirmed to accept transfers."
- It suggests a larger radius when one is available.
- If selection is required, it says the order needs a dealer and to contact the store. If not, it says the order can still be placed and the store will arrange a dealer.
- Merchants can change the wording with the `ffl_bridge_search_notice` filter. The output is sanitized to plain text.
- An API `LOCATION_NOT_FOUND` error now shows "That ZIP code could not be located" instead of a generic failure.

**Merchant coverage notice**

- Each search without a confirmed dealer is logged by three-digit ZIP area (for example `480xx`), with count, largest radius, fallback count, last time, and any API reason. Entries are kept for 30 days, capped at 50 areas, and never linked to a shopper or order.
- Users who can manage WooCommerce see a warning notice on the dashboard, Plugins, and WooCommerce screens. It links to a coverage table on the settings page. Each admin can dismiss it for a week, and it returns only if new gaps are recorded.
- The settings connection test now takes any ZIP and radius. For 48047 at 100 miles it would report the count of transfer-accepting dealers (expected 0), the verified count, any API coverage count, and how many nearby listed dealers the fallback would offer.
- The suggested privacy policy text now discloses the coverage log.

**Optional fallback (off by default)**

- Setting: **When no confirmed dealer is found**: "Show a message only (default)" or "Also offer nearby dealers labeled 'Transfer not confirmed'".
- It applies only when the transfer-accepting search has no selectable dealer. Candidates are, in order:
  - transfer-accepting dealers whose license copy is not verified;
  - the API's `unconfirmedTier` (requested with `includeUnconfirmed=true` only while the fallback is on, so it needs no extra request).
- With an older API that has no tier, one unfiltered search (no `acceptsTransfers`) runs instead, and only when the transfer-accepting search returned nothing.
- Dealers whose `transferStatus` is `declined` are never offered.
- Fallback dealers are labeled "Transfer not confirmed" and tell the shopper to contact the dealer. A dealer that confirmed transfers but has no verified license copy is labeled "License not verified" instead.
- They can be selected, and with the fallback on, a fallback selection satisfies "Require a selection". Connor confirmed this behavior on 2026-10-08.
- The API describes its tier as "not checkout eligible and cannot be used to create an order". That rule is about `POST /api/v1/orders`, which the plugin does not call. The plugin records the selection on the WooCommerce order only, marked unconfirmed.
- The server still checks that the dealer exists, is active and ATF-listed, and matches the license number. A flag in the signed selection handle marks it as a fallback, so a shopper cannot turn an unconfirmed dealer into a confirmed one or the reverse.
- The order records `_ffl_bridge_transfer_confirmed = no`, adds an order note saying FFL Bridge has not confirmed the transfer, and shows "Transfer not confirmed" in admin, the order list, emails, the thank-you page, and My Account.
- If the merchant turns the fallback off while a shopper has a fallback dealer selected, checkout asks the shopper to choose a confirmed dealer.
- Orders saved before this change have no flag and are treated as confirmed.

**API coverage fields (ceweldy/ffl-bridge PR #71)**

The plugin reads the response shape from PR #71 (branch `claude/clever-sagan-p48bba`). It ignores missing or malformed values, so APIs without these fields keep working with plain wording.

- **`data.coverage`**: returned whenever `acceptsTransfers` is sent, which the plugin always does. It contains:
  - integer counts: `radiusMiles`, `directoryDealers`, `transferConfirmedDealers`, `verifiedCheckoutDealers`, `transferDeclinedDealers`, and `transferUnconfirmedDealers`;
  - `emptyReason`, either `{ code, message }` or `null` when results are not empty.
- **Reason codes**: the plugin has shopper wording for `NO_DEALERS_IN_RADIUS`, `NO_TRANSFER_CONFIRMED_DEALERS_IN_RADIUS`, and `NO_VERIFIED_CHECKOUT_DEALERS_IN_RADIUS`. Other codes are recorded in the coverage table but not shown to shoppers.
- **Reason message**: the API's `emptyReason.message` is written for integrators and is not translated, so it is shown only in the admin connection test.
- **`data.unconfirmedTier`** (`label`, `notice`, `total`, `results`): returned only with `includeUnconfirmed=true` and a transfer filter. It feeds the fallback.
- **Per-dealer `transferStatus`** (`confirmed`, `declined`, `unconfirmed`): used to exclude declined dealers and choose fallback labels. The per-dealer `network` field duplicates `checkoutEligible`, which the plugin already reads.
- **Older APIs**: they ignore `includeUnconfirmed`, because the search query schema is not strict, so sending it is safe.

## API work needed in `ceweldy/ffl-bridge` for this request

1. **Transfer acceptance data.** This is the real fix. Options include dealer outreach and self-service claims, importing acceptance data from partners, or a lighter "accepts transfers (unverified)" state separate from the verified checkout network.
2. **Coverage metadata on search.** Done in PR #71 and consumed by this plugin. PR #71 must merge before this PR.
3. **A merchant coverage endpoint** (counts by ZIP or state) so the settings page can show coverage without spending searches.
4. **Fallback support in the order API.** `POST /orders` rejects unconfirmed dealers, which is fine while the plugin does not call it (see ask 3 below). If orders are registered later, unconfirmed selections need an explicit state.

## Earlier assumed asks

The sections below were written before the email arrived, when the asks were inferred: merchant preferred dealers, directory vs verified network, order status webhooks, and license sharing. They remain accurate as a gap analysis but are lower priority than the request above.

Other assumptions from that draft:

- "Preferred dealers" means a list owned by one merchant, not the global `ffls.is_preferred` flag.
- "Pending verification" means a license copy uploaded or claimed but not yet approved.
- License sharing direction was unknown, so both directions are covered.
- Production numbers come from the API repo's `backend-release-contract.md` audit from early October 2026.

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
- Search results are cached under a new key, so results cached by 1.1.0 are not reused after the upgrade.
- New options `ffl_bridge_fallback` (default `no`) and `ffl_bridge_coverage_log`, plus per-user meta `ffl_bridge_coverage_dismissed`. Uninstall removes all three.
- New order meta `_ffl_bridge_transfer_confirmed`. Older orders without it are shown as confirmed.
- `FFL_Bridge_API_Client::get_dealer()` gained an optional `$allow_unconfirmed` argument (default `false`, so existing behavior is unchanged), and `search()` is now a wrapper around `search_with_meta()`.
- The preferred list never leaves WordPress. The coverage log is the only new stored shopper-derived data, and it is disclosed.
- The unused `FFL_Bridge_API_Client::test_connection()` helper was removed. The settings connection test now calls `search()` directly with a larger sample.

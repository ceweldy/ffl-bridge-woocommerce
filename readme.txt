=== FFL Bridge for WooCommerce ===
Contributors: apisdynamics
Tags: woocommerce, ffl, firearms, checkout, dealer
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
WC requires at least: 10.8
WC tested up to: 10.9
Stable tag: 1.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free FFL dealer selection for WooCommerce checkout. Shoppers search by ZIP, pick a licensed dealer, and the dealer is saved on the order.

== Description ==

FFL Bridge for WooCommerce adds a transfer dealer step to WooCommerce checkout. Shoppers search by ZIP code and choose the Federal Firearms Licensee (FFL) that will receive their order. The plugin looks dealers up on the FFL Bridge service from your server, checks the chosen dealer again before the order is created, and saves the dealer on the order for your fulfillment team.

**Free to start**

The plugin is free and GPLv2 licensed. It needs an FFL Bridge account and API key, and both are free on the Free plan: $0 a month, 100 API calls a day, a hard stop at the limit with no overage charges, and no credit card. Paid plans start at $15 a month for 1,000 calls a day. See [pricing](https://www.fflbridge.com/pricing). You do not need your own FFL to use FFL Bridge.

**Before you activate**

Create your free account at [fflbridge.com/signup](https://www.fflbridge.com/signup) first. Until an API key is saved, the dealer step cannot search, and a required selection stops checkout for every product. Set it up on a staging site, or have your key ready before you activate on a live store. The [step by step setup guide](https://www.fflbridge.com/blog/woocommerce-ffl-setup) walks through every screen.

**Hybrid dealer selection (default for new installs)**

* Dealers in the FFL Bridge verified checkout network are listed first and labeled "Confirmed transfer dealer".
* Nearby ATF-listed dealers that still need follow-up are also selectable, labeled "License not verified" or "Transfer not confirmed", so checkout is never empty where network coverage is thin.
* Dealers that have told FFL Bridge they do not accept transfers are never shown.
* A "Confirmed dealers only" mode is available for stores that want only verified dealers.

**License follow-up without blocking checkout**

* After choosing a dealer that is not fully verified, the shopper is asked to contact the dealer, confirm they will accept the transfer, and have them email (or fax) a copy of their current FFL to your store.
* The same instructions appear on the order confirmation page, in My Account, and in customer order emails.
* You choose the license copy email address (the site admin email by default) and an optional fax number.

**Store tools**

* The order screen shows the selected dealer, the license number, and separate transfer and license status.
* One click on "Mark transfer confirmed" records who confirmed it and when, with an optional license file stored privately on the order.
* An admin notice and settings table show ZIP areas where shoppers found no confirmed dealer.
* A connection test reports dealer coverage for any ZIP code and radius.

**Also included**

* Classic checkout and the WooCommerce Checkout Block
* Required or optional selection, for every product or chosen categories
* Store preferred dealers, listed first when they appear in a search
* High-Performance Order Storage (HPOS) compatibility
* The FFL Bridge API key stays on your server and is never sent to the browser

This plugin records dealer details for your fulfillment workflow. It does not change the order's shipping address, create shipping labels, contact dealers itself (the optional dealer notice is sent by FFL Bridge), send orders or documents to dealers, arrange transfers, decide which products require a transfer, or guarantee legal compliance.

== External services ==

This plugin connects to the FFL Bridge service operated by FFL Bridge (https://www.fflbridge.com). An FFL Bridge account and API key are required. Dealer search and verification do not work when the service is unavailable.

Data is sent to `https://www.fflbridge.com/api/v1` only in these cases:

* **Dealer search at checkout**: the shopper-entered ZIP code and search radius, the store's site address (origin), the store's API key, and standard request headers.
* **Dealer selection and order placement**: the selected dealer's FFL Bridge identifier, to retrieve and re-check the current dealer record.
* **Connection and coverage test in settings**: the ZIP code and radius entered by the store administrator.
* **"Mark transfer confirmed" by store staff**: the dealer identifier, license number, order number, an optional staff note, and an optional copy of the dealer license.
* **Dealer notice, only if the store turns on "Email the selected dealer when an order is placed" (off by default)**: after an order with a verified dealer is placed, the dealer identifier, the shopper's billing name and email address, and the order number, so FFL Bridge can email the dealer. FFL Bridge does not email the shopper.

Unless the store turns on the dealer notice, the plugin does not send shopper names or email addresses to FFL Bridge. It never sends billing or shipping addresses or payment details.

<!-- self-hosted-only:start -->
Copies downloaded from fflbridge.com, not the copy from WordPress.org, also check `https://fflbridge.com/api/plugins/woocommerce/update` for new versions when WordPress checks for plugin updates (at most every 12 hours, or every hour after a failed check), and download the update package from fflbridge.com when an administrator installs an update. That check sends only the plugin version in its user agent and standard request headers; it sends no API key, store data, or shopper data.
<!-- self-hosted-only:end -->

Stored in WordPress: search results are cached for up to five minutes; the current selection is kept in the WooCommerce session; dealer details are saved on the order; and when a search finds no confirmed dealer, the first three digits of the ZIP code, the radius, and counts are kept for up to 31 days (30 days per search, kept by day) so administrators can see coverage gaps.

FFL Bridge [Terms of Service](https://www.fflbridge.com/terms) and [Privacy Policy](https://www.fflbridge.com/privacy).

= Legal and fulfillment notice =

Dealer results and status labels support your workflow. They are not legal advice, a warranty, or a guarantee of current license status, dealer acceptance, transfer eligibility, or transaction compliance. Confirm the receiving dealer and meet every applicable requirement before shipment.

== Installation ==

1. Create a free FFL Bridge account at https://www.fflbridge.com/signup. Copy the server API key (it starts with `ffl_live_`) from the page shown right after signup. It is shown only once. A browser key (starts with `ffl_pub_`) does not work with the plugin.
2. Verify your email with the link FFL Bridge sends. The key does not work until your email is verified.
3. In WordPress, open Plugins > Add New Plugin, search for "FFL Bridge for WooCommerce", then install and activate it. WooCommerce 10.8 or newer must be active.
4. Open WooCommerce > FFL Bridge, paste the key into "API key" and save. For stronger separation, define `FFL_BRIDGE_API_KEY` in `wp-config.php` instead.
5. Under "Applicable product categories", tick every category that ships to a dealer. If none are ticked, every product in the cart needs a dealer.
6. Check the dealer selection mode (new installs start in Hybrid), whether a selection is required, and the license copy email and fax.
7. Under "Connection and coverage check", enter a ZIP code your customers use and click "Test connection and coverage". You should see "Connection successful."
8. Place a test order on a staging site before going live.

== Frequently Asked Questions ==

= Do I need an FFL Bridge account? =

Yes. Dealer search and verification use the FFL Bridge service, which needs an account with a verified email address and a server API key (starts with `ffl_live_`). No domain or site address is needed on the key.

= Is FFL Bridge free? =

Yes. The plugin is free and GPLv2 licensed. The Free plan is $0 a month with 100 API calls a day, stops at that limit instead of charging, and needs no credit card. Paid plans start at $15 a month for 1,000 calls a day.

= Do I need my own FFL? =

No. FFL Bridge is built for online firearms retailers. Your shoppers use it to find a licensed dealer near them.

= Why does checkout say "Dealer selection is unavailable because the store connection is not configured. Contact the store before ordering."? =

No API key is saved yet. Open WooCommerce > FFL Bridge, paste your server key, and save.

= Why is "Test connection and coverage" grayed out, or why does the test report a problem with the key? =

The button works only after a key is saved. If the test says the key was rejected or the connection needs attention, the key is not accepted yet. The most common cause is an email address that has not been verified. The test message says what to fix.

= Why does the dealer step show on accessories? =

No product categories are ticked, so every product needs a dealer. Tick your firearm categories under "Applicable product categories".

= Can shoppers check out when no confirmed dealer is nearby? =

Yes, in hybrid mode. Confirmed dealers are listed first, and nearby ATF-listed dealers are offered with a "License not verified" or "Transfer not confirmed" label. After choosing one, the shopper sees follow-up instructions. In "Confirmed dealers only" mode the shopper sees a message instead, and administrators see a coverage notice.

= What do the labels mean? =

"Confirmed transfer dealer" means the dealer is in the FFL Bridge verified checkout network. "License not verified" means the dealer confirmed transfers with FFL Bridge but its current license copy has not been verified. "Transfer not confirmed" means FFL Bridge has no transfer acceptance on record for the dealer.

= How does my store confirm a transfer? =

Open the order. The dealer panel shows the license number and status. Click "Mark transfer confirmed", optionally attaching the license copy (PDF, JPG, or PNG, up to 5 MB). The plugin records who confirmed it and when, adds an order note, and stores the file privately. The plugin never contacts dealers on its own.

= Does the plugin support the Checkout Block? =

Yes. Both classic checkout and the WooCommerce Checkout Block are supported.

= Does selecting a dealer change the shipping address? =

No. The dealer is saved as order details. Your staff follow your own shipping and dealer coordination process.

= Does a selectable dealer guarantee I can ship the order? =

No. Confirm the dealer, license, willingness to accept the transfer, destination, and all applicable requirements before shipment.

= What must I do when upgrading from 1.0.x? =

Version 1.0.x sent the API key to the checkout browser. Revoke any key that was used with 1.0.x, committed to a repository, or included in a distributed build, then create a replacement and enter it under WooCommerce > FFL Bridge (or in `FFL_BRIDGE_API_KEY`). An upgrade cannot invalidate an already exposed credential.

= What happens if FFL Bridge is unavailable? =

New searches and dealer checks cannot complete until the service is back. The plugin does not bypass a required dealer selection.

= What happens when I uninstall the plugin? =

Uninstalling removes the plugin settings, including an API key saved in WordPress. Dealer details already saved on orders, and license files attached to orders, are kept as part of your order records.

== Screenshots ==

1. Checkout dealer search in hybrid mode, with the confirmed dealer first and dealers that need follow-up labeled.
2. Follow-up instructions shown after the shopper chooses a dealer that is not confirmed.
3. The order screen with dealer status and the "Mark transfer confirmed" action.
4. Settings for dealer selection mode and the license copy email and fax.

== Changelog ==

= 1.2.1 =

* Security: Every admin action and AJAX request checks both a nonce and the user's capability before reading request data. Settings saves now require manage_woocommerce, and removing the saved API key needs a valid settings nonce.
* Admin notices appear only on the plugin's settings page and the WooCommerce order screens, and the coverage notice can be dismissed per user. The WooCommerce dependency notice appears only on the Plugins screen.
* Added tests that requests without a nonce or capability are rejected.

= 1.2.0 =

* Hybrid dealer selection, the default for new installs: confirmed dealers first, plus nearby licensed dealers labeled "License not verified" or "Transfer not confirmed". Existing installs keep their setting and see an offer to switch.
* Shopper follow-up instructions on checkout, the thank-you page, and customer emails, with license copy email and fax settings.
* "Mark transfer confirmed" on the order screen with an optional private license file, reported to FFL Bridge when available.
* Clearer messages and an admin coverage notice when no transfer-accepting dealer is nearby.
* Order records keep transfer acceptance, license verification, and selection basis separate.
* Copies downloaded from fflbridge.com can update themselves with sha256 package verification. Copies installed from WordPress.org update through WordPress.org.

= 1.1.0 =

* Security: Move authenticated FFL Bridge requests to WordPress so the API key is not sent to checkout browsers.
* Security: Retrieve the selected dealer record on the server instead of trusting browser-submitted dealer details.
* Add classic and Checkout Block integrations.
* Add High-Performance Order Storage compatibility.
* Add suggested privacy-policy text and clarify external-service data flow.
* Add unit coverage for core security and compatibility decisions.
* Require WordPress 6.9+, PHP 8.3+, and WooCommerce 10.8+.

= 1.0.0 =

* Initial proof-of-concept release.

== Upgrade Notice ==

= 1.2.1 =

Security hardening for admin requests and quieter admin notices. Recommended for all stores.

= 1.2.0 =

Adds hybrid dealer selection, license follow-up instructions, and one-click transfer confirmation. Existing stores keep their current dealer setting. Upgrading from 1.0.x: also revoke and replace every API key used with 1.0.x, because updating the plugin does not invalidate it.

= 1.1.0 =

Security update. Revoke and replace every API key previously used with 1.0.x before upgrading.

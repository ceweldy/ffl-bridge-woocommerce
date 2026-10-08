=== FFL Bridge for WooCommerce ===
Contributors: fflbridge
Tags: woocommerce, ffl, firearms, checkout, dealer
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
WC requires at least: 10.8
WC tested up to: 10.9
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let shoppers choose an FFL transfer dealer at WooCommerce checkout, with confirmed dealers first and license follow-up for the rest.

== Description ==

FFL Bridge for WooCommerce adds a transfer dealer step to WooCommerce checkout. Shoppers search by ZIP code and choose the Federal Firearms Licensee (FFL) that will receive their order. The plugin looks dealers up on the FFL Bridge service from your server, checks the chosen dealer again before the order is created, and saves the dealer on the order for your fulfillment team.

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

This plugin records dealer details for your fulfillment workflow. It does not change the order's shipping address, create shipping labels, contact dealers, send orders or documents to dealers, arrange transfers, decide which products require a transfer, or guarantee legal compliance.

== External services ==

This plugin connects to the FFL Bridge service operated by FFL Bridge (https://www.fflbridge.com). An FFL Bridge account and API key are required. Dealer search and verification do not work when the service is unavailable.

Data is sent to `https://www.fflbridge.com/api/v1` only in these cases:

* **Dealer search at checkout**: the shopper-entered ZIP code and search radius, the store's site address (origin), the store's API key, and standard request headers.
* **Dealer selection and order placement**: the selected dealer's FFL Bridge identifier, to retrieve and re-check the current dealer record.
* **Connection and coverage test in settings**: the ZIP code and radius entered by the store administrator.
* **"Mark transfer confirmed" by store staff**: the dealer identifier, license number, order number, an optional staff note, and an optional copy of the dealer license.

The plugin does not send shopper names, email addresses, billing or shipping addresses, or payment details to FFL Bridge.

Stored in WordPress: search results are cached for up to five minutes; the current selection is kept in the WooCommerce session; dealer details are saved on the order; and when a search finds no confirmed dealer, the first three digits of the ZIP code, the radius, and counts are kept for up to 31 days (30 days per search, kept by day) so administrators can see coverage gaps.

FFL Bridge [Terms of Service](https://www.fflbridge.com/terms) and [Privacy Policy](https://www.fflbridge.com/privacy).

= Legal and fulfillment notice =

Dealer results and status labels support your workflow. They are not legal advice, a warranty, or a guarantee of current license status, dealer acceptance, transfer eligibility, or transaction compliance. Confirm the receiving dealer and meet every applicable requirement before shipment.

== Installation ==

1. In WordPress, open Plugins > Add New Plugin, search for "FFL Bridge for WooCommerce", then install and activate it. WooCommerce 10.8 or newer must be active.
2. Open WooCommerce > FFL Bridge and enter your FFL Bridge API key. For stronger separation, define `FFL_BRIDGE_API_KEY` in `wp-config.php` instead.
3. Choose the dealer selection mode, the license copy email and fax, the product categories that need a dealer, and whether a selection is required.
4. Use the connection and coverage test with a ZIP code your customers use.
5. Place a test order in a staging site before going live.

== Frequently Asked Questions ==

= Do I need an FFL Bridge account? =

Yes. Dealer search and verification use the FFL Bridge service, which needs an account, an API key, and your store's site address added to that key.

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

= 1.2.0 =

Adds hybrid dealer selection, license follow-up instructions, and one-click transfer confirmation. Existing stores keep their current dealer setting.

= 1.1.0 =

Security update. Revoke and replace every API key previously used with 1.0.x before upgrading.

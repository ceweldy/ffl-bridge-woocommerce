=== FFL Bridge for WooCommerce ===
Contributors: apisdynamics
Tags: woocommerce, ffl, firearms, checkout, dealer
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
WC requires at least: 10.8
WC tested up to: 10.9
Stable tag: 1.3.0
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

**Ship to the chosen dealer (default for new installs)**

* When a shopper selects a dealer for an order that needs one, the shipping address becomes the dealer's premises address and name, on classic checkout and the Checkout Block, and the shipping fields stay locked to it.
* When the order is placed, the shipping address is set again from the dealer record FFL Bridge returns at that moment, so an address changed in the browser is not used. Shipping rates use the dealer address. The shipping first and last name stay the shopper's, and billing is never changed.
* Existing installs keep the shopper's shipping address until an administrator turns this on from the admin notice or the settings page.

**Dealer picker**

* An optional "Dealer name" box narrows the ZIP code search to dealers whose name or license name contains the text.
* A logged-in customer's dealer from their last order is listed first, labeled "Your last dealer", when it is in the search results.
* A store blocklist of license numbers hides dealers your store does not ship to. A blocked dealer chosen earlier is refused again at selection and when the order is placed.
* In-store pickup: enter your store's own FFL license, and when a shopper picks local pickup for an order that needs a dealer, your store is the transfer dealer.

**Store tools**

* The order screen shows the selected dealer, the license number, and separate transfer and license status, with a link to check the license with ATF eZ Check in a new tab.
* "Change dealer" on the order screen searches FFL Bridge and replaces the dealer, or adds one to a manually created order. Each change adds an order note.
* One click on "Mark transfer confirmed" records who confirmed it and when, with an optional license file stored privately on the order.
* An admin notice and settings table show ZIP areas where shoppers found no confirmed dealer.
* A connection test reports dealer coverage for any ZIP code and radius.

**Also included**

* Classic checkout and the WooCommerce Checkout Block
* Required or optional selection, for every product, chosen categories, or products and variations marked "Requires FFL transfer" (also as a bulk action)
* Store preferred dealers, listed first when they appear in a search
* High-Performance Order Storage (HPOS) compatibility
* The FFL Bridge API key stays on your server and is never sent to the browser

This plugin records dealer details for your fulfillment workflow and, when "Use the chosen dealer as the shipping address" is on, sets the order's shipping address to that dealer. It does not create shipping labels, contact dealers itself (the optional dealer notice is sent by FFL Bridge), send orders or documents to dealers, arrange transfers, decide which products legally require a transfer, or guarantee legal compliance. The eZ Check link opens ATF's site; the plugin does not check licenses with ATF itself.

== External services ==

This plugin connects to the FFL Bridge service operated by FFL Bridge (https://www.fflbridge.com). An FFL Bridge account and API key are required. Dealer search and verification do not work when the service is unavailable.

Data is sent to `https://www.fflbridge.com/api/v1` only in these cases:

* **Dealer search at checkout**: the shopper-entered ZIP code and search radius, the dealer name the shopper typed in the optional "Dealer name" box (if any), the store's site address (origin), the store's API key, and standard request headers.
* **Dealer selection and order placement**: the selected dealer's FFL Bridge identifier, to retrieve and re-check the current dealer record.
* **Connection and coverage test in settings**: the ZIP code and radius entered by the store administrator.
* **Store FFL for in-store pickup, when an administrator saves a store license number**: one dealer search with the store ZIP code and a 10-mile radius, to find the store's FFL Bridge listing. At checkout with local pickup, the store's FFL Bridge identifier, to re-check that listing.
* **"Change dealer" on the order screen**: the ZIP code, radius and optional dealer name entered by store staff, then the chosen dealer's FFL Bridge identifier, to retrieve the dealer record. No shopper data is sent, and no dealer is emailed.
* **"Mark transfer confirmed" by store staff**: the dealer identifier, license number, order number, an optional staff note, and an optional copy of the dealer license.
* **Dealer notice, only if the store turns on "Email the selected dealer when an order is placed" (off by default)**: after an order with a verified dealer is placed, the dealer identifier, the shopper's billing name and email address, the order number, and an order key (a hash of the site address, order ID and order creation time that contains no shopper data), so FFL Bridge can email the dealer. FFL Bridge does not email the shopper. The order shows whether FFL Bridge sent, queued, skipped or failed the dealer email, or "requested" when FFL Bridge could not confirm it, including when FFL Bridge did not answer in time. A dealer notice FFL Bridge skipped because it already emailed the dealer about an earlier order with the same number is recorded as skipped, not sent. FFL Bridge emails only dealers that have an email address on file and have not opted out, and each dealer email carries Stop and Hide links: Stop ends these emails to that dealer, and Hide removes the dealer's listing from FFL Bridge.

Unless the store turns on the dealer notice, the plugin does not send shopper names or email addresses to FFL Bridge. It never sends billing or shipping addresses or payment details. "Use the chosen dealer as the shipping address" sends nothing extra: the dealer address comes from FFL Bridge to your site. The store blocklist, the "Requires FFL transfer" flags, and a customer's last-used dealer stay on your site. The ATF eZ Check link opens ATF's website in the staff member's browser; the plugin sends nothing to ATF.

<!-- self-hosted-only:start -->
Copies downloaded from fflbridge.com, not the copy from WordPress.org, also check `https://fflbridge.com/api/plugins/woocommerce/update` for new versions when WordPress checks for plugin updates (at most every 12 hours, or every hour after a failed check), and download the update package from fflbridge.com when an administrator installs an update. That check sends only the plugin version in its user agent and standard request headers; it sends no API key, store data, or shopper data.
<!-- self-hosted-only:end -->

Stored in WordPress: search results are cached for up to five minutes; the current selection is kept in the WooCommerce session, with the shopper's own shipping address while a dealer address replaces it; dealer details are saved on the order, and with "Use the chosen dealer as the shipping address" the order's shipping address is the dealer's; with "List a returning customer's last dealer first" on, a logged-in customer's last dealer's FFL Bridge identifier is saved in their user account (included in the WordPress personal data export and erase tools); and when a search finds no confirmed dealer, the first three digits of the ZIP code, the radius, and counts are kept for up to 31 days (30 days per search, kept by day) so administrators can see coverage gaps.

FFL Bridge [Terms of Service](https://www.fflbridge.com/terms) and [Privacy Policy](https://www.fflbridge.com/privacy).

= Legal and fulfillment notice =

Dealer results and status labels support your workflow. They are not legal advice, a warranty, or a guarantee of current license status, dealer acceptance, transfer eligibility, or transaction compliance. Confirm the receiving dealer and meet every applicable requirement before shipment.

== Installation ==

1. Create a free FFL Bridge account at https://www.fflbridge.com/signup. Copy the server API key (it starts with `ffl_live_`) from the page shown right after signup. It is shown only once. A browser key (starts with `ffl_pub_`) does not work with the plugin.
2. Verify your email with the link FFL Bridge sends. The key does not work until your email is verified.
3. In WordPress, open Plugins > Add New Plugin, search for "FFL Bridge for WooCommerce", then install and activate it. If you downloaded the plugin ZIP from fflbridge.com, choose Upload Plugin and upload it instead. WooCommerce 10.8 or newer must be active.
4. Open WooCommerce > FFL Bridge, paste the key into "API key" and save. For stronger separation, define `FFL_BRIDGE_API_KEY` in `wp-config.php` instead.
5. Under "Applicable product categories", tick every category that ships to a dealer, or mark products and variations "Requires FFL transfer". If no category is ticked and no product is marked, every product in the cart needs a dealer.
6. Check the dealer selection mode (new installs start in Hybrid), whether the chosen dealer is the shipping address, whether a selection is required, and the license copy email and fax.
7. Under "Connection and coverage check", enter a ZIP code your customers use and click "Test connection and coverage". You should see "Connection successful."
8. Place a test order on a staging site before going live.

== Frequently Asked Questions ==

= Do I need an FFL Bridge account? =

Yes. Dealer search and verification use the FFL Bridge service, which needs an account with a verified email address and a server API key (starts with `ffl_live_`). No domain or site address is needed on the key.

= Is FFL Bridge free? =

Yes. You can install the plugin from the WordPress.org plugin directory, and it is free and GPLv2 licensed. The FFL Bridge Free plan is $0 a month with 100 API calls a day, stops at that limit instead of charging, and needs no credit card. Paid plans start at $15 a month for 1,000 calls a day and are listed at [fflbridge.com/pricing](https://www.fflbridge.com/pricing).

= Do I need my own FFL? =

No. FFL Bridge is built for online firearms retailers. Your shoppers use it to find a licensed dealer near them. If your store does hold an FFL, you can also offer in-store pickup with it (see below).

= Why does checkout say "Dealer selection is unavailable because the store connection is not configured. Contact the store before ordering."? =

No API key is saved yet. Open WooCommerce > FFL Bridge, paste your server key, and save.

= Why is "Test connection and coverage" grayed out, or why does the test report a problem with the key? =

The button works only after a key is saved. If the test says the key was rejected or the connection needs attention, the key is not accepted yet. The most common cause is an email address that has not been verified. The test message says what to fix.

= Why does the dealer step show on accessories? =

No product categories are ticked and no product is marked "Requires FFL transfer", so every product needs a dealer. Tick your firearm categories under "Applicable product categories", or mark the products that need a dealer.

= Can shoppers check out when no confirmed dealer is nearby? =

Yes, in hybrid mode. Confirmed dealers are listed first, and nearby ATF-listed dealers are offered with a "License not verified" or "Transfer not confirmed" label. After choosing one, the shopper sees follow-up instructions. In "Confirmed dealers only" mode the shopper sees a message instead, and administrators see a coverage notice.

= What do the labels mean? =

"Confirmed transfer dealer" means the dealer is in the FFL Bridge verified checkout network. "License not verified" means the dealer confirmed transfers with FFL Bridge but its current license copy has not been verified. "Transfer not confirmed" means FFL Bridge has no transfer acceptance on record for the dealer.

= How does my store confirm a transfer? =

Open the order. The dealer panel shows the license number and status. Click "Mark transfer confirmed", optionally attaching the license copy (PDF, JPG, or PNG, up to 5 MB). The plugin records who confirmed it and when, adds an order note, and stores the file privately. The plugin never contacts dealers on its own.

= Does the plugin support the Checkout Block? =

Yes. Both classic checkout and the WooCommerce Checkout Block are supported.

= Does the plugin replace WooCommerce checkout? =

No. It adds a dealer-selection step to the checkout you already have and saves the selected dealer on the order.

= Can I use FFL Bridge on a site that is not WooCommerce? =

The plugin itself is for WooCommerce. Custom storefronts can use the REST API, or the browser widget where the platform allows custom JavaScript. The widget embed is documented at [fflbridge.com/docs](https://www.fflbridge.com/docs).

= Which products need a transfer dealer? =

You choose. Check "Requires FFL transfer" on a product (General tab) or on a variation, use the "Mark as requiring an FFL transfer" bulk action on the Products list, or select product categories in WooCommerce > FFL Bridge. Precedence:

1. If no category is selected and no product or variation is flagged anywhere, every product needs a dealer. This is the safe default for a new store.
2. Otherwise a cart needs a dealer when any item's product is flagged, its variation is flagged, or the product is in a selected category or a child of one. A flagged parent product covers all of its variations.

With "Require a selection" set to "Yes, block checkout", an order with one of these products cannot be placed without a dealer, and other products check out without one.

The plugin does not decide which products legally require a transfer; your store sets this.

= Does selecting a dealer change the shipping address? =

Yes, when "Use the chosen dealer as the shipping address" is on. It is on for new installs; stores that installed an earlier version keep the shopper's address and see an admin notice offering it. When on, selecting a dealer fills the shipping address with the dealer's premises address and name and locks those fields, on classic checkout and the Checkout Block. When the order is placed, the shipping address is set again from the dealer record FFL Bridge returns, so an address typed or changed in the browser is not used. The shipping first and last name stay the shopper's and billing is not changed. Carts without an item that needs a dealer keep the shopper's address. In a cart with both kinds of items, the whole order ships to the dealer. In-store pickup orders are not changed. When the setting is off, the dealer is saved as order details only.

= Can I change the dealer after the order is placed? =

Yes. On the order screen, "Change dealer" searches FFL Bridge by ZIP code and optional name, and saves the dealer you choose from the record FFL Bridge returns. It can also add a dealer to an order created by hand. Each change adds an order note with who made it and the old and new dealer, and clears a store transfer confirmation made for the previous dealer. With "Use the chosen dealer as the shipping address" on, the shipping address follows the new dealer. No dealer is emailed.

= How do I keep a dealer out of my checkout? =

Add its license number to "Dealers to hide (blocklist)" in WooCommerce > FFL Bridge. Blocked dealers are not shown in search results. If a shopper chose one before you blocked it, selecting it and placing the order are both refused, so the shopper chooses another dealer.

= Does in-store pickup need a dealer? =

If your store holds an FFL, enter its license number under "Store FFL for in-store pickup". When a shopper chooses a local pickup shipping method for an order that needs a dealer, your store is saved as the transfer dealer and the shopper does not choose one. If FFL Bridge cannot re-check your store's listing at checkout, the order is not placed, so the shopper can choose another shipping method.

= Does a selectable dealer guarantee I can ship the order? =

No. Confirm the dealer, license, willingness to accept the transfer, destination, and all applicable requirements before shipment.

= What must I do when upgrading from 1.0.x? =

Version 1.0.x sent the API key to the checkout browser. Revoke any key that was used with 1.0.x, committed to a repository, or included in a distributed build, then create a replacement and enter it under WooCommerce > FFL Bridge (or in `FFL_BRIDGE_API_KEY`). An upgrade cannot invalidate an already exposed credential.

= What happens if FFL Bridge is unavailable? =

New searches and dealer checks cannot complete until the service is back. The plugin does not bypass a required dealer selection.

= What happens when I uninstall the plugin? =

Uninstalling removes the plugin settings, including an API key saved in WordPress, the store blocklist, the "Requires FFL transfer" product flags, and customers' saved last-used dealers. Dealer details already saved on orders, order shipping addresses, and license files attached to orders are kept as part of your order records.

== Screenshots ==

1. Checkout dealer search in hybrid mode, with the confirmed dealer first and dealers that need follow-up labeled.
2. Follow-up instructions shown after the shopper chooses a dealer that is not confirmed.
3. The order screen with dealer status and the "Mark transfer confirmed" action.
4. Settings for dealer selection mode and the license copy email and fax.

== Changelog ==

= 1.3.0 =

* New: "Use the chosen dealer as the shipping address". Selecting a dealer writes the dealer's premises address and name into the shipping fields and locks them on classic checkout and the Checkout Block. At order creation the shipping address is set again from the dealer record FFL Bridge returns, never from browser input. Shipping rates use the dealer address. On for new installs; existing installs see an admin notice offering it.
* New: "Requires FFL transfer" checkbox on simple products and variations, and a Products list bulk action. Works with the category setting. With no categories and no flagged product, every product still needs a dealer.
* New: ATF eZ Check link on the order screen's dealer panel, with the license digits eZ Check asks for. eZ Check is a quick license status check, not a substitute for the license copy.
* New: store blocklist of dealer license numbers, hidden from search and refused again at selection and order creation.
* New: a logged-in customer's last-used dealer is listed first when it is in the search results. On for new installs; offered to existing installs.
* New: optional "Dealer name" box in the dealer picker. On for new installs; offered to existing installs.
* New: "Change dealer" on the order screen replaces the dealer or adds one to a manually created order, from the record FFL Bridge returns. Each change adds an order note.
* New: in-store pickup. With the store's own FFL license saved, choosing local pickup makes the store the transfer dealer.
* Fix: a selected dealer is no longer cleared when the cart total changes, for example when shipping is recalculated after a new address or shipping method. Changing the cart's items still asks for the dealer again.
* Fix: classic checkout order notes about the dealer are now saved.
* Readme: a Free plan and setup section, installation steps that start with the account and email verification, more FAQ answers, and a note that FFL Bridge emails only dealers with an address on file who have not opted out, with Stop and Hide links in every dealer email.
* Also new since 1.2.1: the optional "Email the selected dealer when an order is placed" setting (off by default), sent by FFL Bridge, with its delivery status on the order; an "FFL Bridge API base URL" setting for testing against a staging server; clearer connection test errors; Checkout Block orders without a required dealer fail with a 400 message instead of a 500; a selected category covers its child categories; the order-received page shows the dealer once.

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

= 1.3.0 =

Adds ship-to-dealer, per-product transfer flags, a dealer blocklist, dealer name search, change dealer on orders, in-store pickup, and an ATF eZ Check link. Your checkout is unchanged until you turn the new checkout options on from the admin notice or the settings page.

= 1.2.1 =

Security hardening for admin requests and quieter admin notices. Recommended for all stores.

= 1.2.0 =

Adds hybrid dealer selection, license follow-up instructions, and one-click transfer confirmation. Existing stores keep their current dealer setting. Upgrading from 1.0.x: also revoke and replace every API key used with 1.0.x, because updating the plugin does not invalidate it.

= 1.1.0 =

Security update. Revoke and replace every API key previously used with 1.0.x before upgrading.

=== FFL Bridge for WooCommerce ===
Tags: woocommerce, ffl, checkout, firearms, dealer
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
WC requires at least: 10.8
WC tested up to: 10.9
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add server-verified FFL dealer selection to WooCommerce checkout and save the selected dealer to the order.

== Description ==

FFL Bridge for WooCommerce lets a shopper search for an FFL dealer during checkout. The plugin sends authenticated dealer requests from WordPress, retrieves the selected dealer record from FFL Bridge, and stores an allowlisted set of dealer fields as WooCommerce order metadata.

Features:

* Classic checkout and WooCommerce Checkout Block support
* Server-side use of the FFL Bridge API key
* Search by five-digit ZIP code and supported radius
* Required or optional selection for all products or configured categories
* Dealer details in order administration, order emails, confirmation pages, and customer order details
* WooCommerce High-Performance Order Storage compatibility

Requirements:

* WordPress 6.9 or newer
* PHP 8.3 or newer
* WooCommerce 10.8 or newer
* An FFL Bridge account, API key, and authorized site origin

This plugin records dealer metadata for the retailer's fulfillment workflow. It does not change the order's shipping address, create a shipping label, contact a dealer, transmit an order or documents to a dealer, arrange the transfer, decide which products require a transfer, or guarantee legal compliance.

= External service =

Dealer search and verification depend on the hosted FFL Bridge service at `https://www.fflbridge.com/api/v1`. These features do not work when the service is unavailable.

When a shopper searches, the plugin sends the shopper-entered ZIP code and radius, the site's public origin, the merchant API key, and standard server request metadata. When a shopper selects a dealer, the plugin sends the dealer UUID to retrieve the current record. The plugin does not intentionally send the shopper's name, email address, billing/shipping address, or payment information to FFL Bridge. Search results may be cached in WordPress for up to five minutes. The current selection is held in the WooCommerce session, and the selected dealer fields are stored by the merchant in the WooCommerce order.

Use of the service is governed by the [FFL Bridge Terms of Service](https://www.fflbridge.com/terms) and [Privacy Policy](https://www.fflbridge.com/privacy).

= Legal and fulfillment notice =

Dealer-directory results and selection status are provided for workflow purposes. They are not legal advice, a warranty, or a guarantee of current license status, dealer acceptance, transfer eligibility, or transaction compliance. The retailer must independently confirm the receiving dealer and satisfy all applicable fulfillment and legal requirements before shipment.

== Installation ==

1. Download the versioned plugin ZIP from the GitHub Releases page.
2. In WordPress administration, open Plugins > Add Plugin > Upload Plugin.
3. Upload the ZIP, install it, and activate it.
4. Open WooCommerce > FFL Bridge.
5. Configure the API key, product categories, and whether selection is required.
6. Test search and order placement in a staging environment before production use.

For better credential isolation, define `FFL_BRIDGE_API_KEY` in `wp-config.php` instead of saving the key in the WordPress options table.

== Frequently Asked Questions ==

= What must I do when upgrading from 1.0.x? =

Version 1.0.x sent the API key to the checkout browser. Revoke any key that was used with 1.0.x, committed to a repository, or included in a distributed build, then create a replacement before using 1.1.0. An upgrade cannot invalidate an already exposed credential.

= Does the plugin support the Checkout Block? =

Yes. Version 1.1.0 supports both classic WooCommerce checkout and the WooCommerce Checkout Block.

= Does selecting a dealer change the shipping destination? =

No. The plugin stores the dealer as order metadata. Store staff must follow their own verified shipping and dealer-coordination process.

= Does a selectable result guarantee that I can ship the order? =

No. Confirm the dealer, license information, willingness to accept the transfer, destination, and all applicable requirements before shipment.

= What shopper information is sent to FFL Bridge? =

The shopper-entered search ZIP code and radius are sent for dealer search, and the dealer UUID is sent for selection verification. The plugin does not intentionally send shopper names, email addresses, billing/shipping addresses, or payment data to FFL Bridge. See the External service section for the complete disclosure.

= What happens if FFL Bridge is unavailable? =

New searches and dealer verification cannot complete until the service is available. The plugin does not bypass a required dealer selection.

= What happens when I uninstall the plugin? =

The uninstall process removes the plugin settings, including an API key saved in WordPress. Dealer metadata already attached to orders is retained as part of the merchant's order record.

== Changelog ==

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

= 1.1.0 =

Security update. Revoke and replace every API key previously used with 1.0.x before upgrading.

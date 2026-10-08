# FFL Bridge for WooCommerce

FFL Bridge for WooCommerce adds server-verified FFL dealer selection to WooCommerce checkout and records the selected dealer as order metadata for fulfillment review.

## What it does

- Supports both classic WooCommerce checkout and the WooCommerce Checkout Block.
- Searches the FFL Bridge service by ZIP code and radius without exposing the merchant API key to the shopper's browser.
- Labels each result as part of the verified checkout network or as a public directory listing. Only verified network dealers can be selected. Merchants can hide directory listings and mark their own preferred dealers.
- Explains to shoppers when no transfer-accepting dealer is nearby, warns store administrators about coverage gaps, and can optionally offer nearby dealers labeled "Transfer not confirmed" (off by default).
- Retrieves the selected dealer from FFL Bridge on the server before saving a canonical, allowlisted record to the order.
- Can require selection for all products or only configured WooCommerce product categories.
- Displays stored dealer details in WooCommerce order administration, order emails, confirmation pages, and customer order details.
- Uses WooCommerce order CRUD APIs and declares High-Performance Order Storage compatibility.

The plugin is a dealer-selection and metadata tool. It does **not** change the shipping address, create labels, send orders or documents to a dealer, arrange a transfer, determine which products legally require a transfer, or guarantee that a transaction complies with applicable law. Retailers remain responsible for fulfillment procedures, dealer coordination, license review, and legal compliance.

## Requirements

- WordPress 6.9 or newer
- PHP 8.3 or newer
- WooCommerce 10.8 or newer
- An FFL Bridge account, API key, and authorized site origin
- HTTPS for a production store is strongly recommended

## Install

1. Download the versioned ZIP from [GitHub Releases](https://github.com/ceweldy/ffl-bridge-woocommerce/releases). Do not upload a GitHub source-code archive.
2. In WordPress administration, open **Plugins > Add Plugin > Upload Plugin**.
3. Upload the `ffl-bridge-for-woocommerce-VERSION.zip` file, install it, and activate it.
4. Open **WooCommerce > FFL Bridge** and configure the connection and checkout behavior.
5. Test both dealer search and order placement in a staging environment before enabling the plugin on a production checkout.

Release assets include a `.sha256` file that can be used to verify the downloaded ZIP.

## Configure the credential

The preferred configuration keeps the key out of the WordPress options table. Add it to `wp-config.php` above the line that stops editing:

```php
define( 'FFL_BRIDGE_API_KEY', 'ffl_live_replace_with_your_key' );
```

Alternatively, enter the key on **WooCommerce > FFL Bridge**. Never commit a real key to source control or include it in a support request.

### Required upgrade action from 1.0.x

Version 1.0.x sent the API key to the checkout browser. If a key was ever used with 1.0.x, committed to a repository, or included in a previously distributed build, revoke it in FFL Bridge and create a replacement before using 1.1.0. Upgrading prevents the new key from being exposed by the plugin, but cannot make an old exposed key safe.

## Updates

From 1.2.0 the plugin updates itself from FFL Bridge. Installs older than 1.2.0 need one manual update to 1.2.0; after that, new versions appear under **Dashboard > Updates** and on the Plugins screen, where "View details" shows the changelog. WordPress auto-updates stay off until a store turns them on for this plugin.

The update checker reads `https://fflbridge.com/api/plugins/woocommerce/update` over HTTPS, caches the answer for 12 hours (one hour after a failure), and does nothing visible when the server is unreachable. Expected manifest:

```json
{
  "version": "1.2.0",
  "download_url": "https://fflbridge.com/downloads/ffl-bridge-for-woocommerce-1.2.0.zip",
  "sha256": "64 lowercase hex characters",
  "requires": "6.9",
  "requires_php": "8.3",
  "tested": "7.0",
  "requires_plugins": ["woocommerce"],
  "wc_requires": "10.8",
  "changelog_html": "<h4>1.2.0</h4><ul><li>...</li></ul>",
  "last_updated": "2026-10-08"
}
```

`download_url` must be HTTPS on fflbridge.com or a subdomain. Before WordPress installs the package, the plugin downloads it and compares its sha256 with the manifest. A mismatch deletes the file and stops the update with an error. The plugin header sets `Update URI` to the manifest address so WordPress.org never offers a different package under the same slug.

## External service and privacy

Dealer search and verification depend on the hosted FFL Bridge service at `https://www.fflbridge.com/api/v1`; the plugin cannot provide those functions when that service is unavailable.

For a search, the plugin sends the shopper-entered ZIP code and search radius, the site's public origin, the merchant API key, and standard server request metadata. When a shopper selects a result, the plugin sends the dealer UUID to retrieve the current dealer record. It does not intentionally send the shopper's name, email address, billing/shipping address, or payment information to FFL Bridge. Search results may be cached in WordPress for up to five minutes. Searches that find no confirmed transfer dealer are logged by three-digit ZIP area, radius, and count for up to 30 days so administrators can see coverage gaps. The current selection is held in the WooCommerce session, and selected dealer details are stored in the merchant's WooCommerce order and may appear in order emails and customer-facing order views.

Review the [FFL Bridge Privacy Policy](https://www.fflbridge.com/privacy) and [Terms of Service](https://www.fflbridge.com/terms). WordPress also offers suggested privacy-policy text for the plugin under **Settings > Privacy**. Merchants are responsible for adapting that text to their actual store configuration and legal obligations.

## Fulfillment and legal limits

FFL Bridge returns dealer-directory data and selection status for workflow purposes. A successful search or selection is not a legal opinion, warranty, or guarantee of current license status, transfer eligibility, dealer acceptance, or transaction compliance. Before shipment, independently confirm the receiving dealer, destination, license information, willingness to accept the transfer, and all procedures required by applicable federal, state, and local law.

Uninstalling the plugin removes its settings, including a key saved in WordPress. It intentionally retains dealer metadata already attached to orders as part of the merchant's order record.

## Development

```bash
composer install
composer check
bin/build-release.sh
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the review and verification expectations. Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

FFL Bridge for WooCommerce is licensed under [GPL-2.0-or-later](LICENSE).

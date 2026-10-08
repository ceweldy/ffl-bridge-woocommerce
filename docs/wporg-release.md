# WordPress.org directory release

This is the checklist for listing FFL Bridge for WooCommerce in the WordPress.org plugin directory and for each release after that. Nothing here runs on its own: submission, SVN credentials, and tags are human steps.

## Two packages from one source

| | Self-hosted ZIP | Directory ZIP |
| --- | --- | --- |
| Build | `composer build` (or `bin/build-release.sh`) | `composer build:wporg` (or `bin/build-release.sh --target=wporg`) |
| File | `dist/ffl-bridge-for-woocommerce-X.Y.Z.zip` | `dist/ffl-bridge-for-woocommerce-X.Y.Z-wporg.zip` |
| Update checker (`includes/class-ffl-bridge-updater.php`) | Included | Removed |
| `Update URI` header | `https://fflbridge.com/api/plugins/woocommerce/update` | Removed |
| Updates come from | fflbridge.com manifest, sha256 checked | WordPress.org |

The directory build removes the updater file, the block in `ffl-bridge.php` between `// ffl-bridge:self-hosted-updater:start` and `:end`, and the `Update URI` line. It then fails if any update hook or updater reference is left. Both builds are reproducible: the same commit gives the same sha256. The directory build also leaves an unpacked copy in `dist/wporg-build/ffl-bridge-for-woocommerce` for Plugin Check and the SVN deploy.

## Names

* Plugin name: **FFL Bridge for WooCommerce**. It leads with our own brand and uses "for WooCommerce" as a suffix, which is the form WooCommerce trademark guidance allows. It does not start with "WooCommerce", "Woo", or "WordPress".
* Slug: `ffl-bridge-for-woocommerce`. Request this exact slug when submitting. It cannot be changed after approval.
* Text domain: `ffl-bridge-for-woocommerce`, matching the slug, set in the plugin header and used in every translation call.
* Main file: `ffl-bridge.php`. The directory does not require it to match the slug.

## Before submitting (Connor)

1. **WordPress.org account.** Create or pick the account that will own the plugin. Replace the `Contributors: fflbridge` placeholder in `readme.txt` with that username (comma separated, usernames only). The account email must be one that receives mail, because review mail comes from `plugins@wordpress.org`.
2. **Tested versions.** `Tested up to: 7.1` was smoke tested on WordPress 7.1.3. `WC tested up to: 10.9` is unchanged: test checkout, the order screen, and settings on WooCommerce 11.x before raising it.
3. **Assets.** Everything in `.wordpress-org/` is a draft. Approve or replace the banners and icons (sources in `docs/wporg-assets/`). Replace `screenshot-3.png` with a capture from a real WooCommerce order screen; the current one uses a local stand-in. Captions live under `== Screenshots ==` in `readme.txt` and must stay in the same order.
4. **Readme check.** Paste `readme.txt` into the official validator at https://wordpress.org/plugins/developers/readme-validator/ and fix anything it reports.
5. **Build and check.**
   ```bash
   composer install
   composer check
   composer build:wporg
   ```
   Then run Plugin Check (the "Plugin Check" plugin, or `wp plugin check ffl-bridge-for-woocommerce`) against the unpacked `dist/wporg-build/ffl-bridge-for-woocommerce` on a current WordPress. It must report no errors. Every pull request also runs it in the `WordPress.org` workflow.

## Submitting (Connor)

1. Sign in at https://wordpress.org/plugins/developers/add/ with the owner account.
2. Upload `dist/ffl-bridge-for-woocommerce-X.Y.Z-wporg.zip`. Never upload the self-hosted ZIP: the directory rejects plugins that update from elsewhere.
3. Confirm the slug shown is `ffl-bridge-for-woocommerce`.
4. Answer review mail by replying to it. Reviewers often ask about the external service; the answer is the "External services" section of `readme.txt`. Fix requested changes in this repository, rebuild, and upload the new directory ZIP in the reply as they instruct.
5. Approval mail includes the SVN address `https://plugins.svn.wordpress.org/ffl-bridge-for-woocommerce/`.

## After approval: automatic deploys

1. In WordPress.org, open the profile's **Account & Security** page and create the **SVN password** (it is not the login password).
2. In GitHub, open **Settings > Environments**, create (or open) the `wordpress-org` environment, and add two secrets:
   * `SVN_USERNAME`: the WordPress.org username (case sensitive)
   * `SVN_PASSWORD`: the SVN password from step 1
   Optionally add yourself as a required reviewer on that environment so every deploy waits for a click.
3. From then on, pushing a tag `vX.Y.Z` does this:
   * `Release` workflow: checks the source, builds both ZIPs, and creates the GitHub release with both ZIPs and their `.sha256` files.
   * `WordPress.org` workflow, `deploy` job: checks that the tag matches the plugin header version and `Stable tag`, builds the directory package, and commits it to SVN `trunk`, `tags/X.Y.Z`, and `assets/` (from `.wordpress-org/`) using 10up/action-wordpress-plugin-deploy. Without the two secrets the job prints a notice and does nothing.

Until the secrets exist, tags only make GitHub releases. Once they exist, every `v*` tag publishes to WordPress.org, so tag only commits that are ready to ship.

## Each release

1. Bump `Version` and `FFL_BRIDGE_VERSION` in `ffl-bridge.php`, `Stable tag` in `readme.txt`, and add entries to `== Changelog ==`, `== Upgrade Notice ==` (when useful), and `CHANGELOG.md`.
2. Raise `Tested up to` and `WC tested up to` only after testing on those versions.
3. Merge to `main`, wait for CI, then push the tag `vX.Y.Z` from that commit.
4. Check the GitHub release assets and the plugin page on WordPress.org.
5. Publish the self-hosted manifest on fflbridge.com for that version only if self-hosted installs still exist (see below).

## Moving self-hosted installs to WordPress.org updates

Installs from fflbridge.com carry `Update URI: https://fflbridge.com/...`. WordPress never offers a WordPress.org update to a plugin whose `Update URI` points elsewhere, so those stores keep using the fflbridge.com checker until they install a build without it. The plan is one final self-hosted release:

1. Ship version N to WordPress.org (first directory release, or any later one).
2. Copy that exact directory ZIP (`ffl-bridge-for-woocommerce-N-wporg.zip`) to `https://fflbridge.com/downloads/` and point the manifest at it: `version` N, `download_url` to that file, `sha256` from its `.sha256`. The checker only accepts HTTPS downloads on fflbridge.com, which is why the file must be hosted there.
3. Self-hosted stores see N as a normal update. The checker verifies the sha256 and WordPress installs it. N has no `Update URI` and no checker, so the store now gets updates from WordPress.org under the same slug, and settings and order data stay as they are.
4. Leave the manifest at N. Stores still on 1.2.0 to N-1 will reach N when they next update and then switch over. Installs older than 1.2.0 have no checker and need one manual update, either from fflbridge.com or by installing from the directory.
5. Stop building self-hosted ZIPs after N unless a store needs one; the build target stays available.

## What the directory build must keep passing

* Plugin Check with no errors (experimental checks included).
* No minified-only code: `assets/js` and `assets/css` ship as readable source.
* No tracking or calls home other than the FFL Bridge API calls listed in `readme.txt`.
* All output escaped, all input sanitized, nonces and capability checks on every admin and AJAX action, every global prefixed `ffl_bridge` or `FFL_Bridge`, and every string translatable with the plugin text domain.

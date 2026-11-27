# Contributing

Thank you for improving FFL Bridge for WooCommerce.

## Before opening a change

- Use a GitHub issue for a reproducible bug or a focused feature proposal.
- Use the private process in [SECURITY.md](SECURITY.md) for suspected vulnerabilities.
- Keep changes compatible with WordPress 6.9+, PHP 8.3+, and WooCommerce 10.8+.
- Do not add real API keys, customer information, dealer records, or other sensitive data to fixtures or logs.

## Local checks

Install development dependencies and run the quality suite:

```bash
composer install
composer check
bash -n bin/build-release.sh
bin/build-release.sh
```

The release build packages only Git-tracked files and applies `.distignore`. Inspect the resulting ZIP under `dist/` before proposing a release-related change.

The unit suite covers API response normalization, signed selection handles, cart/category decisions, and order-metadata compatibility with WordPress test doubles.

The integration suite runs against a real WordPress and WooCommerce install. It needs WP-CLI and a MySQL or MariaDB server (see `bin/install-wp-tests.sh` for the database settings):

```bash
bin/install-wp-tests.sh 6.9 10.8.0   # or: bin/install-wp-tests.sh latest latest
composer test:integration
```

CI runs the unit suite on PHP 8.3 to 8.5, the integration suite on PHP 8.3 and 8.4 with WordPress 6.9 plus WooCommerce 10.8 and with the latest WordPress and WooCommerce, PHPCS, the build test, and Plugin Check on the WordPress.org build.

Neither suite runs a complete browser checkout. Changes to checkout, order storage, or external-service behavior should therefore include a clear manual test record covering both classic checkout and the WooCommerce Checkout Block.

## Pull requests

Keep each pull request scoped to one change. Explain the user-visible behavior, security or privacy impact, compatibility considerations, and verification performed. Update `CHANGELOG.md`, `README.md`, and `readme.txt` when behavior or requirements change.

All contributions are licensed under GPL-2.0-or-later, the repository's license.

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

The unit suite covers API response normalization, signed selection handles, cart/category decisions, and order-metadata compatibility. It does not boot a complete WordPress and WooCommerce checkout. Changes to checkout, order storage, or external-service behavior should therefore include a clear manual test record covering both classic checkout and the WooCommerce Checkout Block.

## Pull requests

Keep each pull request scoped to one change. Explain the user-visible behavior, security or privacy impact, compatibility considerations, and verification performed. Update `CHANGELOG.md`, `README.md`, and `readme.txt` when behavior or requirements change.

All contributions are licensed under GPL-2.0-or-later, the repository's license.

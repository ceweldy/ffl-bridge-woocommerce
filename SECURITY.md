# Security Policy

## Supported versions

Security fixes are provided for the current release line.

| Version | Supported |
| --- | --- |
| 1.1.x | Yes |
| 1.0.x and earlier | No |

Version 1.0.x exposed the FFL Bridge API credential to the checkout browser. If a key was ever used with that version, included in a public repository, or distributed in an old build, revoke it in FFL Bridge and create a replacement before using version 1.1.0. Upgrading the plugin does not invalidate a previously exposed key.

## Report a vulnerability

Please use [GitHub private vulnerability reporting](https://github.com/ceweldy/ffl-bridge-woocommerce/security/advisories/new). Do not disclose a suspected vulnerability in a public issue.

Include, when possible:

- the affected plugin version and WordPress/WooCommerce versions;
- clear reproduction steps and the security impact;
- relevant logs or screenshots with credentials and personal data removed; and
- any suggested mitigation.

Security reports will be investigated before public disclosure. Please allow time for a fix and coordinated release.

## Credential handling

Never include a real `ffl_live_...` key in an issue, pull request, log, screenshot, test fixture, or release archive. Revoke any credential that may have been disclosed; redaction alone does not make an exposed credential safe again.

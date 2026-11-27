#!/usr/bin/env bash
#
# Installs WordPress and WooCommerce for the integration test suite and
# activates this plugin from the working tree.
#
# Usage: bin/install-wp-tests.sh [wordpress-version] [woocommerce-version]
#
# Both versions default to "latest". Requires WP-CLI and a MySQL or MariaDB
# server. Settings come from the environment:
#
#   WP_CORE_DIR  WordPress install directory (default: $TMPDIR/ffl-bridge-wp)
#   DB_NAME      Database name, dropped and recreated (default: ffl_bridge_tests)
#   DB_USER      Database user (default: root)
#   DB_PASS      Database password (default: root)
#   DB_HOST      Database host (default: 127.0.0.1)
#   WP_SOURCE    Optional WordPress directory to copy instead of downloading
#   WC_SOURCE    Optional WooCommerce plugin directory to link instead of downloading

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SLUG="ffl-bridge-for-woocommerce"
readonly WP_VERSION="${1:-latest}"
readonly WC_VERSION="${2:-latest}"
readonly WP_CORE_DIR="${WP_CORE_DIR:-${TMPDIR:-/tmp}/ffl-bridge-wp}"
readonly DB_NAME="${DB_NAME:-ffl_bridge_tests}"
readonly DB_USER="${DB_USER:-root}"
readonly DB_PASS="${DB_PASS:-root}"
readonly DB_HOST="${DB_HOST:-127.0.0.1}"

wp() {
	command wp --path="${WP_CORE_DIR}" ${WP_CLI_EXTRA_ARGS:-} "$@"
}

rm -rf "${WP_CORE_DIR}"
mkdir -p "${WP_CORE_DIR}"

if [[ -n "${WP_SOURCE:-}" ]]; then
	cp -R "${WP_SOURCE}/." "${WP_CORE_DIR}/"
	rm -rf "${WP_CORE_DIR}/.git"
elif [[ "${WP_VERSION}" == 'latest' ]]; then
	wp core download --force
else
	wp core download --version="${WP_VERSION}" --force
fi

wp config create \
	--dbname="${DB_NAME}" \
	--dbuser="${DB_USER}" \
	--dbpass="${DB_PASS}" \
	--dbhost="${DB_HOST}" \
	--skip-check \
	--force \
	--extra-php <<'PHP'
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_DEBUG_LOG', true );
define( 'DISABLE_WP_CRON', true );
PHP

wp db drop --yes >/dev/null 2>&1 || true
wp db create
wp core install \
	--url='http://ffl-bridge.test' \
	--title='FFL Bridge tests' \
	--admin_user='admin' \
	--admin_password='admin' \
	--admin_email='admin@ffl-bridge.test' \
	--skip-email

mkdir -p "${WP_CORE_DIR}/wp-content/plugins"
if [[ -n "${WC_SOURCE:-}" ]]; then
	ln -sfn "${WC_SOURCE}" "${WP_CORE_DIR}/wp-content/plugins/woocommerce"
elif [[ "${WC_VERSION}" == 'latest' ]]; then
	wp plugin install woocommerce
else
	wp plugin install woocommerce --version="${WC_VERSION}"
fi
wp plugin activate woocommerce

ln -sfn "${ROOT_DIR}" "${WP_CORE_DIR}/wp-content/plugins/${SLUG}"
wp plugin activate "${SLUG}"

# Downloads are done. Tests must never reach a real external service.
wp config set WP_HTTP_BLOCK_EXTERNAL true --raw

printf 'WordPress %s, WooCommerce %s, PHP %s\n' \
	"$(wp core version)" \
	"$(wp plugin get woocommerce --field=version)" \
	"$(php -r 'echo PHP_VERSION;')"

#!/usr/bin/env bash
#
# Builds both release targets and checks the readme.txt self-hosted-only
# block and the self-hosted update URL in each output.

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly SLUG="ffl-bridge-for-woocommerce"
readonly UPDATE_URL="api/plugins/woocommerce/update"
readonly PARAGRAPH="Copies downloaded from fflbridge.com, not the copy from WordPress.org, also check"

work_dir="$(mktemp -d "${TMPDIR:-/tmp}/ffl-bridge-build-test.XXXXXX")"
trap 'rm -rf "${work_dir}"' EXIT

failures=0
fail() {
	printf 'FAIL: %s\n' "$1" >&2
	failures=$((failures + 1))
}

version="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "${ROOT_DIR}/ffl-bridge.php" | head -n 1 | tr -d '\r')"

if ! grep -q '^<!-- self-hosted-only:start -->$' "${ROOT_DIR}/readme.txt"; then
	fail 'readme.txt source has no self-hosted-only block to test'
fi

# WordPress.org build: no markers, no self-hosted paragraph, no update URL,
# no updater code, no Update URI.
"${ROOT_DIR}/bin/build-release.sh" --target=wporg >/dev/null
mkdir -p "${work_dir}/wporg"
unzip -q "${ROOT_DIR}/dist/${SLUG}-${version}-wporg.zip" -d "${work_dir}/wporg"
wporg="${work_dir}/wporg/${SLUG}"

grep -q 'self-hosted-only' "${wporg}/readme.txt" && fail 'wporg readme.txt still contains marker text'
grep -qF "${PARAGRAPH}" "${wporg}/readme.txt" && fail 'wporg readme.txt still contains the self-hosted paragraph'
grep -RIqF "${UPDATE_URL}" "${wporg}" && fail "wporg build still contains ${UPDATE_URL}"
grep -RIq 'Update URI' "${wporg}" && fail 'wporg build still contains an Update URI header'
[[ -e "${wporg}/includes/class-ffl-bridge-updater.php" ]] && fail 'wporg build still contains the updater file'
grep -RIq 'FFL_Bridge_Updater' "${wporg}" && fail 'wporg build still references FFL_Bridge_Updater'
awk 'NR > 1 && prev == "" && $0 == "" { found = 1 } { prev = $0 } END { exit !found }' "${wporg}/readme.txt" && fail 'wporg readme.txt has a doubled blank line where the block was'

# Self-hosted build: paragraph kept, markers removed, updater kept.
"${ROOT_DIR}/bin/build-release.sh" --target=self-hosted >/dev/null
mkdir -p "${work_dir}/self-hosted"
unzip -q "${ROOT_DIR}/dist/${SLUG}-${version}.zip" -d "${work_dir}/self-hosted"
hosted="${work_dir}/self-hosted/${SLUG}"

grep -qF "${PARAGRAPH}" "${hosted}/readme.txt" || fail 'self-hosted readme.txt lost the self-hosted paragraph'
grep -q 'self-hosted-only' "${hosted}/readme.txt" && fail 'self-hosted readme.txt still contains marker text'
[[ -f "${hosted}/includes/class-ffl-bridge-updater.php" ]] || fail 'self-hosted build lost the updater file'
grep -q '^ \* Update URI:' "${hosted}/ffl-bridge.php" || fail 'self-hosted build lost the Update URI header'

if (( failures > 0 )); then
	printf '%d build check(s) failed.\n' "${failures}" >&2
	exit 1
fi

printf 'Build checks passed for wporg and self-hosted targets.\n'

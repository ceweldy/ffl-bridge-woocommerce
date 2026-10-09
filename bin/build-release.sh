#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly PLUGIN_SLUG="ffl-bridge-for-woocommerce"
readonly PLUGIN_FILE="${ROOT_DIR}/ffl-bridge.php"
readonly DIST_DIR="${ROOT_DIR}/dist"

# Build targets:
# - self-hosted (default): the ZIP served from fflbridge.com, with the update
#   checker and the Update URI header.
# - wporg: the WordPress.org directory ZIP, without the update checker or the
#   Update URI header, because the directory serves its own updates.
build_target="${BUILD_TARGET:-self-hosted}"
for argument in "$@"; do
	case "${argument}" in
		--target=*) build_target="${argument#--target=}" ;;
		*)
			printf 'Unknown argument: %s\n' "${argument}" >&2
			exit 1
			;;
	esac
done

if [[ "${build_target}" != "self-hosted" && "${build_target}" != "wporg" ]]; then
	printf 'Unknown build target: %s (use self-hosted or wporg)\n' "${build_target}" >&2
	exit 1
fi

if [[ ! -f "${PLUGIN_FILE}" ]]; then
	printf 'Plugin entry point not found: %s\n' "${PLUGIN_FILE}" >&2
	exit 1
fi

if ! command -v git >/dev/null 2>&1 || ! command -v python3 >/dev/null 2>&1 || ! command -v rsync >/dev/null 2>&1; then
	printf 'Building requires git, python3, and rsync.\n' >&2
	exit 1
fi

header_version="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "${PLUGIN_FILE}" | head -n 1 | tr -d '\r')"
constant_version="$(sed -n "s/.*define( 'FFL_BRIDGE_VERSION', '\([^']*\)' ).*/\1/p" "${PLUGIN_FILE}" | head -n 1)"

if [[ -z "${header_version}" || ! "${header_version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
	printf 'Invalid or missing plugin header version: %s\n' "${header_version:-<missing>}" >&2
	exit 1
fi

if [[ "${header_version}" != "${constant_version}" ]]; then
	printf 'Version mismatch: plugin header is %s but FFL_BRIDGE_VERSION is %s.\n' "${header_version}" "${constant_version:-<missing>}" >&2
	exit 1
fi

release_tag="${RELEASE_TAG:-}"
if [[ -z "${release_tag}" && "${GITHUB_REF_TYPE:-}" == "tag" ]]; then
	release_tag="${GITHUB_REF_NAME:-}"
fi

if [[ -n "${release_tag}" && "${release_tag#v}" != "${header_version}" ]]; then
	printf 'Release tag %s does not match plugin version %s.\n' "${release_tag}" "${header_version}" >&2
	exit 1
fi

stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${ROOT_DIR}/readme.txt" | head -n 1 | tr -d '\r')"
if [[ "${stable_tag}" != "${header_version}" ]]; then
	printf 'Version mismatch: readme.txt Stable tag is %s but the plugin header is %s.\n' "${stable_tag:-<missing>}" "${header_version}" >&2
	exit 1
fi

source_date_epoch="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT_DIR}" log -1 --format=%ct)}"
if [[ ! "${source_date_epoch}" =~ ^[0-9]+$ ]]; then
	printf 'SOURCE_DATE_EPOCH must be an integer.\n' >&2
	exit 1
fi

temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/ffl-bridge-release.XXXXXX")"
trap 'rm -rf "${temporary_dir}"' EXIT

package_dir="${temporary_dir}/${PLUGIN_SLUG}"
if [[ "${build_target}" == "wporg" ]]; then
	artifact="${DIST_DIR}/${PLUGIN_SLUG}-${header_version}-wporg.zip"
else
	artifact="${DIST_DIR}/${PLUGIN_SLUG}-${header_version}.zip"
fi
checksum="${artifact}.sha256"

mkdir -p "${package_dir}" "${DIST_DIR}"
rm -f "${artifact}" "${checksum}"

# Package only files tracked by Git, then apply the release exclusion list.
# This prevents local credentials and other untracked development files from
# entering a release artifact.
exclude_arguments=()
while IFS= read -r pattern || [[ -n "${pattern}" ]]; do
	if [[ -z "${pattern}" || "${pattern}" == \#* ]]; then
		continue
	fi
	exclude_arguments+=( "--exclude=${pattern}" )
done < "${ROOT_DIR}/.distignore"

git -C "${ROOT_DIR}" ls-files -z \
	| rsync -aq --from0 --files-from=- "${exclude_arguments[@]}" "${ROOT_DIR}/" "${package_dir}/"

if [[ "${build_target}" == "wporg" ]]; then
	rm -f "${package_dir}/includes/class-ffl-bridge-updater.php"
	PACKAGE_DIR="${package_dir}" python3 <<'PY'
import os
import pathlib
import re

main = pathlib.Path(os.environ["PACKAGE_DIR"]) / "ffl-bridge.php"
source = main.read_text(encoding="utf-8")
block = re.compile(r"// ffl-bridge:self-hosted-updater:start[^\n]*\n.*?// ffl-bridge:self-hosted-updater:end[^\n]*\n\n?", re.S)
if len(block.findall(source)) != 1:
    raise SystemExit("Expected exactly one self-hosted updater block in ffl-bridge.php.")
source = block.sub("", source)
header = re.compile(r"^ \* Update URI:.*\n", re.M)
if len(header.findall(source)) != 1:
    raise SystemExit("Expected exactly one Update URI header in ffl-bridge.php.")
main.write_text(header.sub("", source), encoding="utf-8")
PY

	forbidden='Update URI|class-ffl-bridge-updater|FFL_Bridge_Updater|pre_set_site_transient_update_plugins|upgrader_pre_download|plugins_api|auto_update_plugin'
	if grep -RInE "${forbidden}" "${package_dir}" --include='*.php'; then
		printf 'The WordPress.org build still contains self-hosted update code.\n' >&2
		exit 1
	fi
fi

# readme.txt blocks between <!-- self-hosted-only:start --> and
# <!-- self-hosted-only:end --> describe only the self-hosted build. The
# WordPress.org build drops each block; the self-hosted build drops only the
# marker lines and keeps the text.
BUILD_TARGET="${build_target}" PACKAGE_DIR="${package_dir}" python3 <<'PY'
import os
import pathlib
import re

readme = pathlib.Path(os.environ["PACKAGE_DIR"]) / "readme.txt"
source = readme.read_text(encoding="utf-8")
start = re.compile(r"^<!-- self-hosted-only:start -->[ \t]*$", re.M)
end = re.compile(r"^<!-- self-hosted-only:end -->[ \t]*$", re.M)
if len(start.findall(source)) != len(end.findall(source)):
    raise SystemExit("readme.txt has unbalanced self-hosted-only markers.")

if os.environ["BUILD_TARGET"] == "wporg":
    block = re.compile(r"^<!-- self-hosted-only:start -->[ \t]*\n.*?^<!-- self-hosted-only:end -->[ \t]*\n\n?", re.M | re.S)
    source = block.sub("", source)
else:
    marker = re.compile(r"^<!-- self-hosted-only:(?:start|end) -->[ \t]*\n", re.M)
    source = marker.sub("", source)

if "self-hosted-only" in source:
    raise SystemExit("readme.txt still contains a self-hosted-only marker.")
readme.write_text(source, encoding="utf-8")
PY

if [[ "${build_target}" == "wporg" ]]; then
	if grep -RIn 'api/plugins/woocommerce/update' "${package_dir}"; then
		printf 'The WordPress.org build still references the self-hosted update URL (api/plugins/woocommerce/update).\n' >&2
		exit 1
	fi
fi

for required_file in ffl-bridge.php readme.txt LICENSE; do
	if [[ ! -f "${package_dir}/${required_file}" ]]; then
		printf 'Required release file is missing or untracked: %s\n' "${required_file}" >&2
		exit 1
	fi
done

while IFS= read -r required_file; do
	if [[ -n "${required_file}" && ! -f "${package_dir}/${required_file}" ]]; then
		printf 'Runtime dependency is missing or untracked: %s\n' "${required_file}" >&2
		exit 1
	fi
done < <(sed -n "s/.*FFL_BRIDGE_PLUGIN_DIR \. '\([^']*\)'.*/\1/p" "${package_dir}/ffl-bridge.php" | sort -u)

PACKAGE_DIR="${package_dir}" python3 <<'PY'
import os
import pathlib
import re

package_dir = pathlib.Path(os.environ["PACKAGE_DIR"])
credential_patterns = (
    re.compile(rb"ffl_live_[A-Za-z0-9]{32,}"),
    re.compile(rb"-----BEGIN (?:EC |OPENSSH |RSA )?PRIVATE KEY-----"),
)

for path in package_dir.rglob("*"):
    if not path.is_file():
        continue
    data = path.read_bytes()
    if any(pattern.search(data) for pattern in credential_patterns):
        relative = path.relative_to(package_dir)
        raise SystemExit(f"Potential credential detected in release file: {relative}")
PY

SOURCE_DATE_EPOCH="${source_date_epoch}" PACKAGE_DIR="${package_dir}" ARTIFACT="${artifact}" python3 <<'PY'
import os
import pathlib
import stat
import time
import zipfile

package_dir = pathlib.Path(os.environ["PACKAGE_DIR"])
artifact = pathlib.Path(os.environ["ARTIFACT"])
epoch = max(315532800, min(int(os.environ["SOURCE_DATE_EPOCH"]), 4354819199))
timestamp = time.gmtime(epoch)[:6]

with zipfile.ZipFile(artifact, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for path in sorted(package_dir.rglob("*"), key=lambda item: item.as_posix()):
        if path.is_symlink():
            raise SystemExit(f"Refusing to package symbolic link: {path}")
        if not path.is_file():
            continue

        relative = path.relative_to(package_dir)
        archive_name = pathlib.PurePosixPath(package_dir.name, relative.as_posix()).as_posix()
        mode = 0o755 if path.stat().st_mode & stat.S_IXUSR else 0o644
        info = zipfile.ZipInfo(archive_name, timestamp)
        info.create_system = 3
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = ((stat.S_IFREG | mode) & 0xFFFF) << 16
        info.flag_bits |= 0x800
        archive.writestr(info, path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
PY

ARTIFACT="${artifact}" python3 <<'PY' > "${checksum}"
import hashlib
import os
import pathlib

artifact = pathlib.Path(os.environ["ARTIFACT"])
digest = hashlib.sha256(artifact.read_bytes()).hexdigest()
print(f"{digest}  {artifact.name}")
PY

if [[ "${build_target}" == "wporg" ]]; then
	# Unpacked copy for the WordPress.org SVN deploy and Plugin Check.
	build_dir="${DIST_DIR}/wporg-build"
	rm -rf "${build_dir}"
	mkdir -p "${build_dir}"
	cp -a "${package_dir}" "${build_dir}/"
	printf 'Created %s\n' "${build_dir}/${PLUGIN_SLUG}"
fi

printf 'Created %s\n' "${artifact}"
printf 'Created %s\n' "${checksum}"

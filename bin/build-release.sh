#!/usr/bin/env bash

set -euo pipefail

readonly ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
readonly PLUGIN_SLUG="ffl-bridge-for-woocommerce"
readonly PLUGIN_FILE="${ROOT_DIR}/ffl-bridge.php"
readonly DIST_DIR="${ROOT_DIR}/dist"

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

source_date_epoch="${SOURCE_DATE_EPOCH:-$(git -C "${ROOT_DIR}" log -1 --format=%ct)}"
if [[ ! "${source_date_epoch}" =~ ^[0-9]+$ ]]; then
	printf 'SOURCE_DATE_EPOCH must be an integer.\n' >&2
	exit 1
fi

temporary_dir="$(mktemp -d "${TMPDIR:-/tmp}/ffl-bridge-release.XXXXXX")"
trap 'rm -rf "${temporary_dir}"' EXIT

package_dir="${temporary_dir}/${PLUGIN_SLUG}"
artifact="${DIST_DIR}/${PLUGIN_SLUG}-${header_version}.zip"
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
done < <(sed -n "s/.*FFL_BRIDGE_PLUGIN_DIR \. '\([^']*\)'.*/\1/p" "${PLUGIN_FILE}" | sort -u)

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

printf 'Created %s\n' "${artifact}"
printf 'Created %s\n' "${checksum}"

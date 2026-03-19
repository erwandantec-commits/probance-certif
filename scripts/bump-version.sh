#!/bin/bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
VERSION_FILE="${1:-${REPO_ROOT}/app/version.txt}"

if [[ ! -f "${VERSION_FILE}" ]]; then
  echo "Version file not found: ${VERSION_FILE}" >&2
  exit 1
fi

raw_version="$(tr -d '\r' < "${VERSION_FILE}" | sed -n '1p' | xargs)"
if [[ -z "${raw_version}" ]]; then
  echo "Version file is empty: ${VERSION_FILE}" >&2
  exit 1
fi

if [[ "${raw_version}" =~ ^([0-9]+)$ ]]; then
  major="${BASH_REMATCH[1]}"
  minor=0
elif [[ "${raw_version}" =~ ^([0-9]+)\.([0-9]+)$ ]]; then
  major="${BASH_REMATCH[1]}"
  minor=$((10#${BASH_REMATCH[2]}))
else
  echo "Unsupported version format '${raw_version}'. Expected 'N' or 'N.N'." >&2
  exit 1
fi

next_version="${major}.$((minor + 1))"
printf '%s' "${next_version}" > "${VERSION_FILE}"

echo "${next_version}"

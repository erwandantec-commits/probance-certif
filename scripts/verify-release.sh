#!/usr/bin/env bash
set -euo pipefail

EXPECTED_APP_VERSION="${1:-}"
DB_NAME="${MARIADB_DATABASE:-certif}"
ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-/db_schema}"
APP_VERSION_FILE="${APP_VERSION_FILE:-/opt/certif/app/version.txt}"
BASELINE_VERSION=2

usage() {
  cat <<'EOF'
Usage:
  verify-release.sh [expected_app_version]

Run this only from inside the db container after the web and db containers have been restarted.
EOF
}

if [[ "${EXPECTED_APP_VERSION}" == "-h" || "${EXPECTED_APP_VERSION}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ -z "${ROOT_PASSWORD}" ]]; then
  echo "[verify-release] MARIADB_ROOT_PASSWORD is required" >&2
  exit 1
fi

if [[ ! -f "${APP_VERSION_FILE}" ]]; then
  echo "[verify-release] App version file '${APP_VERSION_FILE}' not found" >&2
  exit 1
fi

mysql_exec() {
  mariadb --protocol=TCP -h 127.0.0.1 -u root "-p${ROOT_PASSWORD}" "$@"
}

echo "[verify-release] Waiting for MariaDB to accept connections..."
until mysql_exec -e "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done
echo "[verify-release] MariaDB is reachable."

app_version="$(tr -d '\r' < "${APP_VERSION_FILE}" | sed -n '1p' | xargs)"
if [[ -z "${app_version}" ]]; then
  echo "[verify-release] App version file is empty" >&2
  exit 1
fi

if [[ -n "${EXPECTED_APP_VERSION}" && "${EXPECTED_APP_VERSION}" != "${app_version}" ]]; then
  echo "[verify-release] Expected app version '${EXPECTED_APP_VERSION}', found '${app_version}'" >&2
  exit 1
fi

expected_schema_version="${BASELINE_VERSION}"
while IFS= read -r file; do
  base="$(basename "${file}")"
  if [[ "${base}" =~ ^([0-9]+)_ ]]; then
    expected_schema_version=$((10#${BASH_REMATCH[1]}))
  fi
done < <(find "${MIGRATIONS_DIR}" -maxdepth 1 -type f -name '*.sql' | sort -V)

actual_schema_version="$(mysql_exec "${DB_NAME}" -Nse "SELECT version FROM schema_version WHERE id = 1 LIMIT 1;")"
if [[ -z "${actual_schema_version}" ]]; then
  echo "[verify-release] Unable to read schema_version" >&2
  exit 1
fi

if [[ "${actual_schema_version}" != "${expected_schema_version}" ]]; then
  echo "[verify-release] Schema version mismatch. Expected v${expected_schema_version}, got v${actual_schema_version}" >&2
  exit 1
fi

echo "[verify-release] App version: ${app_version}"
echo "[verify-release] Schema version OK: v${actual_schema_version}"

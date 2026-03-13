#!/bin/bash
set -euo pipefail

DB_NAME="${MARIADB_DATABASE:-certif}"
ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-/db_schema}"
APP_VERSION_FILE="${APP_VERSION_FILE:-/opt/certif/app/version.txt}"
STARTUP_TIMEOUT_SECONDS="${STARTUP_TIMEOUT_SECONDS:-120}"
DB_START_PID="${DB_START_PID:-}"
BASELINE_VERSION=2
LOCK_NAME="certif_schema_migrations_lock"

if [[ -z "$ROOT_PASSWORD" ]]; then
  echo "[migrate] MARIADB_ROOT_PASSWORD is required" >&2
  exit 1
fi

if [[ ! -d "$MIGRATIONS_DIR" ]]; then
  echo "[migrate] Migrations directory '$MIGRATIONS_DIR' not found" >&2
  exit 1
fi

if [[ ! -f "$APP_VERSION_FILE" ]]; then
  echo "[migrate] App version file '$APP_VERSION_FILE' not found" >&2
  exit 1
fi

mysql_exec() {
  mariadb --protocol=TCP -h 127.0.0.1 -u root "-p${ROOT_PASSWORD}" "$@"
}

latest_migration_version() {
  local latest_version expected_version filename version_num

  latest_version="$BASELINE_VERSION"
  expected_version="$BASELINE_VERSION"

  mapfile -t migration_files < <(find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name '*.sql' | sort -V)

  for file in "${migration_files[@]}"; do
    filename="$(basename "$file")"

    if [[ ! "$filename" =~ ^([0-9]+)_.+\.sql$ ]]; then
      echo "[migrate] Skipping '$filename' (invalid naming, expected NNN_description.sql)."
      continue
    fi

    version_num=$((10#${BASH_REMATCH[1]}))
    if (( version_num > expected_version + 1 )); then
      echo "[migrate] Missing migration between v${expected_version} and v${version_num}." >&2
      exit 1
    fi

    expected_version="$version_num"
    latest_version="$version_num"
  done

  printf '%s\n' "$latest_version"
}

echo "[migrate] Waiting for MariaDB to accept connections..."
start_time="$(date +%s)"
until mysql_exec -e "SELECT 1" >/dev/null 2>&1; do
  if [[ -n "$DB_START_PID" ]] && ! kill -0 "$DB_START_PID" 2>/dev/null; then
    echo "[migrate] MariaDB exited before startup checks completed." >&2
    exit 1
  fi
  now="$(date +%s)"
  if (( now - start_time >= STARTUP_TIMEOUT_SECONDS )); then
    echo "[migrate] MariaDB did not become reachable within ${STARTUP_TIMEOUT_SECONDS}s." >&2
    exit 1
  fi
  sleep 1
done
echo "[migrate] MariaDB is reachable."

if ! mysql_exec -Nse "SELECT GET_LOCK('${LOCK_NAME}', 60);" | grep -qx "1"; then
  echo "[migrate] Unable to acquire migration lock '${LOCK_NAME}'" >&2
  exit 1
fi

release_lock() {
  mysql_exec -Nse "SELECT RELEASE_LOCK('${LOCK_NAME}');" >/dev/null 2>&1 || true
}
trap release_lock EXIT

EXPECTED_VERSION="$(latest_migration_version)"

mysql_exec "$DB_NAME" -e "
CREATE TABLE IF NOT EXISTS schema_version (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  version INT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_schema_version_id CHECK (id = 1)
);

CREATE TABLE IF NOT EXISTS schema_migrations (
  version INT NOT NULL PRIMARY KEY,
  script_name VARCHAR(255) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
"

CURRENT_VERSION="$(mysql_exec "$DB_NAME" -Nse "SELECT version FROM schema_version WHERE id = 1 LIMIT 1;" || true)"

if [[ -z "$CURRENT_VERSION" ]]; then
  MAX_APPLIED_VERSION="$(mysql_exec "$DB_NAME" -Nse "SELECT COALESCE(MAX(version), ${BASELINE_VERSION}) FROM schema_migrations;" || true)"
  if [[ -z "$MAX_APPLIED_VERSION" ]]; then
    MAX_APPLIED_VERSION="$BASELINE_VERSION"
  fi

  mysql_exec "$DB_NAME" -e "
INSERT INTO schema_version (id, version)
VALUES (1, ${BASELINE_VERSION})
ON DUPLICATE KEY UPDATE version = VALUES(version);
"
  mysql_exec "$DB_NAME" -e "UPDATE schema_version SET version = ${MAX_APPLIED_VERSION} WHERE id = 1;"
  CURRENT_VERSION="$MAX_APPLIED_VERSION"
  echo "[migrate] Initialized schema version baseline at v${CURRENT_VERSION}."
fi

LAST_VERSION="$CURRENT_VERSION"

for file in "${migration_files[@]}"; do
  filename="$(basename "$file")"

  if [[ ! "$filename" =~ ^([0-9]+)_.+\.sql$ ]]; then
    echo "[migrate] Skipping '$filename' (invalid naming, expected NNN_description.sql)."
    continue
  fi

  version_num=$((10#${BASH_REMATCH[1]}))

  if (( version_num <= CURRENT_VERSION )); then
    continue
  fi

  if (( version_num > LAST_VERSION + 1 )); then
    echo "[migrate] Missing migration between v${LAST_VERSION} and v${version_num} (stopping)." >&2
    exit 1
  fi

  already_applied="$(mysql_exec "$DB_NAME" -Nse "SELECT COUNT(*) FROM schema_migrations WHERE version = ${version_num};")"
  if [[ "$already_applied" == "1" ]]; then
    mysql_exec "$DB_NAME" -e "UPDATE schema_version SET version = GREATEST(version, ${version_num}) WHERE id = 1;"
    CURRENT_VERSION="$version_num"
    LAST_VERSION="$version_num"
    continue
  fi

  echo "[migrate] Applying v${version_num} (${filename})..."
  mysql_exec "$DB_NAME" < "$file"
  mysql_exec "$DB_NAME" -e "
INSERT INTO schema_migrations (version, script_name)
VALUES (${version_num}, '$(printf "%s" "$filename" | sed "s/'/''/g")');
UPDATE schema_version
SET version = ${version_num}
WHERE id = 1;
"
  CURRENT_VERSION="$version_num"
  LAST_VERSION="$version_num"
done

ACTUAL_VERSION="$(mysql_exec "$DB_NAME" -Nse "SELECT version FROM schema_version WHERE id = 1 LIMIT 1;")"
if [[ -z "$ACTUAL_VERSION" ]]; then
  echo "[migrate] Unable to read schema_version after migration." >&2
  exit 1
fi

if [[ "$ACTUAL_VERSION" != "$EXPECTED_VERSION" ]]; then
  echo "[migrate] Schema version mismatch after startup. Expected v${EXPECTED_VERSION}, got v${ACTUAL_VERSION}." >&2
  exit 1
fi

APP_VERSION="$(tr -d '\r' < "$APP_VERSION_FILE" | sed -n '1p' | xargs)"
if [[ -z "$APP_VERSION" ]]; then
  echo "[migrate] App version file '$APP_VERSION_FILE' is empty." >&2
  exit 1
fi

echo "[migrate] App version: ${APP_VERSION}"
echo "[migrate] Schema is up to date at v${ACTUAL_VERSION}."

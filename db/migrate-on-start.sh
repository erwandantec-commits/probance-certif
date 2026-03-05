#!/bin/bash
set -euo pipefail

DB_NAME="${MARIADB_DATABASE:-certif}"
ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-/db_schema}"
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

mysql_exec() {
  mariadb --protocol=TCP -h 127.0.0.1 -u root "-p${ROOT_PASSWORD}" "$@"
}

echo "[migrate] Waiting for MariaDB to accept connections..."
until mysql_exec -e "SELECT 1" >/dev/null 2>&1; do
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

mapfile -t migration_files < <(find "$MIGRATIONS_DIR" -maxdepth 1 -type f -name '*.sql' | sort -V)
LAST_VERSION="$CURRENT_VERSION"

for file in "${migration_files[@]}"; do
  filename="$(basename "$file")"

  if [[ ! "$filename" =~ ^([0-9]+)_.+\.sql$ ]]; then
    echo "[migrate] Skipping '$filename' (invalid naming, expected NNN_description.sql)."
    continue
  fi

  version_prefix="${BASH_REMATCH[1]}"
  version_num=$((10#$version_prefix))

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

echo "[migrate] Schema is up to date at v${CURRENT_VERSION}."

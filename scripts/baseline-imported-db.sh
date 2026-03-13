#!/usr/bin/env bash
set -euo pipefail

TARGET_VERSION="${1:-}"
FORCE="${FORCE:-0}"
DB_NAME="${MARIADB_DATABASE:-certif}"
ROOT_PASSWORD="${MARIADB_ROOT_PASSWORD:-}"
MIGRATIONS_DIR="${MIGRATIONS_DIR:-/db_schema}"
BASELINE_VERSION=2

usage() {
  cat <<'EOF'
Usage:
  baseline-imported-db.sh [target_version]

Environment:
  FORCE=1   Restamp schema metadata even if schema_version/schema_migrations already contain rows.

Run this only from inside the db container, after importing an existing database that already matches the target schema.
EOF
}

if [[ "${TARGET_VERSION}" == "-h" || "${TARGET_VERSION}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ -z "${ROOT_PASSWORD}" ]]; then
  echo "[baseline] MARIADB_ROOT_PASSWORD is required" >&2
  exit 1
fi

if [[ ! -d "${MIGRATIONS_DIR}" ]]; then
  echo "[baseline] Migrations directory '${MIGRATIONS_DIR}' not found" >&2
  exit 1
fi

mysql_exec() {
  mariadb --protocol=TCP -h 127.0.0.1 -u root "-p${ROOT_PASSWORD}" "$@"
}

echo "[baseline] Waiting for MariaDB to accept connections..."
until mysql_exec -e "SELECT 1" >/dev/null 2>&1; do
  sleep 1
done
echo "[baseline] MariaDB is reachable."

mapfile -t migration_files < <(find "${MIGRATIONS_DIR}" -maxdepth 1 -type f -name '*.sql' | sort -V)

latest_version="${BASELINE_VERSION}"
expected_version="${BASELINE_VERSION}"
version_rows=()

for file in "${migration_files[@]}"; do
  filename="$(basename "$file")"
  if [[ ! "$filename" =~ ^([0-9]+)_.+\.sql$ ]]; then
    continue
  fi

  version_num=$((10#${BASH_REMATCH[1]}))
  if (( version_num != expected_version + 1 )); then
    echo "[baseline] Missing migration between v${expected_version} and v${version_num}" >&2
    exit 1
  fi
  expected_version="${version_num}"
  latest_version="${version_num}"
  safe_filename="$(printf "%s" "$filename" | sed "s/'/''/g")"
  version_rows+=("(${version_num}, '${safe_filename}')")
done

if [[ -z "${TARGET_VERSION}" ]]; then
  TARGET_VERSION="${latest_version}"
fi

if ! [[ "${TARGET_VERSION}" =~ ^[0-9]+$ ]]; then
  echo "[baseline] target_version must be an integer" >&2
  exit 1
fi

target_version=$((10#${TARGET_VERSION}))

if (( target_version < BASELINE_VERSION )); then
  echo "[baseline] target_version must be >= ${BASELINE_VERSION}" >&2
  exit 1
fi

if (( target_version > latest_version )); then
  echo "[baseline] target_version v${target_version} is greater than latest migration v${latest_version}" >&2
  exit 1
fi

schema_version_count="$(mysql_exec "${DB_NAME}" -Nse "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_version';")"
schema_migrations_count="$(mysql_exec "${DB_NAME}" -Nse "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations';")"

existing_schema_version="0"
existing_migration_rows="0"

if [[ "${schema_version_count}" == "1" ]]; then
  existing_schema_version="$(mysql_exec "${DB_NAME}" -Nse "SELECT COALESCE(MAX(version), 0) FROM schema_version;")"
fi
if [[ "${schema_migrations_count}" == "1" ]]; then
  existing_migration_rows="$(mysql_exec "${DB_NAME}" -Nse "SELECT COUNT(*) FROM schema_migrations;")"
fi

if [[ "${FORCE}" != "1" ]] && { [[ "${existing_schema_version}" != "0" ]] || [[ "${existing_migration_rows}" != "0" ]]; }; then
  echo "[baseline] Schema metadata already exists (schema_version=${existing_schema_version}, schema_migrations rows=${existing_migration_rows})." >&2
  echo "[baseline] Set FORCE=1 only if you intentionally want to restamp migration metadata." >&2
  exit 1
fi

filtered_rows=()
for row in "${version_rows[@]}"; do
  if [[ "$row" =~ ^\(([0-9]+), ]]; then
    row_version=$((10#${BASH_REMATCH[1]}))
    if (( row_version <= target_version )); then
      filtered_rows+=("$row")
    fi
  fi
done

insert_sql=""
if (( ${#filtered_rows[@]} > 0 )); then
  insert_sql="INSERT INTO schema_migrations (version, script_name) VALUES $(IFS=,; echo "${filtered_rows[*]}");"
fi

mysql_exec "${DB_NAME}" <<SQL
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

DELETE FROM schema_migrations;
DELETE FROM schema_version WHERE id = 1;
${insert_sql}
INSERT INTO schema_version (id, version) VALUES (1, ${target_version});
SQL

echo "[baseline] Imported DB is now stamped at schema version v${target_version}."
echo "[baseline] Future releases can rely on the normal startup migration flow."

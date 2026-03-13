#!/usr/bin/env bash
set -euo pipefail

NAME="${1:-}"
DB_SCHEMA_DIR="${DB_SCHEMA_DIR:-/opt/certif/db_schema}"
BASELINE_VERSION=2

usage() {
  cat <<'EOF'
Usage:
  new-migration.sh migration_name

Run this only from inside the web container.
EOF
}

if [[ -z "${NAME}" || "${NAME}" == "-h" || "${NAME}" == "--help" ]]; then
  usage
  [[ -z "${NAME}" ]] && exit 1 || exit 0
fi

if [[ ! -d "${DB_SCHEMA_DIR}" ]]; then
  echo "[new-migration] Migration directory '${DB_SCHEMA_DIR}' not found" >&2
  exit 1
fi

safe_name="$(printf '%s' "${NAME}" | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//')"
if [[ -z "${safe_name}" ]]; then
  echo "[new-migration] Name must contain at least one alphanumeric character" >&2
  exit 1
fi

max_version="${BASELINE_VERSION}"
while IFS= read -r file; do
  base="$(basename "${file}")"
  if [[ "${base}" =~ ^([0-9]+)_ ]]; then
    version=$((10#${BASH_REMATCH[1]}))
    if (( version > max_version )); then
      max_version="${version}"
    fi
  fi
done < <(find "${DB_SCHEMA_DIR}" -maxdepth 1 -type f -name '*.sql' | sort -V)

next_version=$((max_version + 1))
printf -v filename "%02d_%s.sql" "${next_version}" "${safe_name}"
path="${DB_SCHEMA_DIR}/${filename}"

if [[ -e "${path}" ]]; then
  echo "[new-migration] Migration already exists: ${path}" >&2
  exit 1
fi

cat > "${path}" <<EOF
-- Migration v${next_version}
-- Release:
-- Purpose:

-- Write an idempotent migration here.
EOF

echo "[new-migration] Created migration: ${path}"

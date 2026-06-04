#!/bin/bash
set -euo pipefail

# Start MariaDB in the background, then run startup checks and migrations.
/usr/local/bin/docker-entrypoint.sh "$@" &
db_pid="$!"
export DB_START_PID="$db_pid"

on_exit() {
  if kill -0 "$db_pid" 2>/dev/null; then
    kill "$db_pid" 2>/dev/null || true
  fi
}

trap on_exit SIGINT SIGTERM

trap on_exit EXIT

if ! /bin/bash /usr/local/bin/migrate-on-start.sh; then
  echo "[entrypoint] Startup checks failed, stopping MariaDB." >&2
  exit 1
fi

wait "$db_pid"

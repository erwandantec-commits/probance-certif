#!/bin/bash
set -euo pipefail

# Start the official MariaDB entrypoint in background, then run schema migrations.
/usr/local/bin/docker-entrypoint.sh "$@" &
db_pid="$!"

on_exit() {
  if kill -0 "$db_pid" 2>/dev/null; then
    kill "$db_pid" 2>/dev/null || true
  fi
}

trap on_exit SIGINT SIGTERM

trap on_exit EXIT

/bin/bash /usr/local/bin/migrate-on-start.sh

wait "$db_pid"

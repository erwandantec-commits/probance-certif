#!/bin/bash
set -euo pipefail

RSYNC_PARAM="-avz --itemize-changes --dry-run --delete --delete-excluded --exclude='.*' --exclude='*.md' --exclude='deploy.sh' --exclude='docker-compose.yml' --exclude='Dockerfile' --exclude='README.md' --exclude='docs' --exclude='data'"

echo "Debug:"
echo "rsync ${RSYNC_PARAM} . \"${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}\""
rsync ${RSYNC_PARAM} . "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

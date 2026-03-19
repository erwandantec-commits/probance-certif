#!/bin/bash
set -euo pipefail

RSYNC_PARAM="-avz --dry-run --exclude='.*' --exclude='deploy.sh' --exclude='docker-compose.yml' --exclude='Dockerfile' --exclude='README.md' --exclude='docs'"

echo "Debug:"
echo "rsync ${RSYNC_PARAM} . \"${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}\""
rsync ${RSYNC_PARAM} . "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"

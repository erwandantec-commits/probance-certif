#!/bin/bash
set -euo pipefail

RSYNC_PARAM=(
  -avz
  --itemize-changes
#  --dry-run
  --delete
  --exclude='.*'
  --exclude='*.md'
  --exclude='deploy.sh'
  --exclude='docker-compose.yml'
  --exclude='Dockerfile'
  --exclude='README.md'
  --exclude='docs'
  --exclude='data'
)


echo "Debug:"
echo "rsync ${RSYNC_PARAM[@]} . \"${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}\""
rsync ${RSYNC_PARAM[@]} . "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"


# Run remote command
echo "Recreate containers"
ssh "${REMOTE_USER}@${REMOTE_HOST}" << 'EOF'
set -e
cd "${REMOTE_PATH}"
docker compose up -d --force-recreate
EOF

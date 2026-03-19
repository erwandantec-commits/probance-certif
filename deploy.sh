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
ssh "${REMOTE_USER}@${REMOTE_HOST}" << EOF
set -e
cd "${REMOTE_PATH}"
echo "Working dir: \$(pwd)"

# Directories: 755
find . -type d -exec chmod 755 {} +

# Files: 644
find . -type f -exec chmod 644 {} +

docker compose up -d --force-recreate
EOF

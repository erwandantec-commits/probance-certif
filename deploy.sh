#!/bin/bash
set -euo pipefail


REMOTE_USER="probance_certif"
REMOTE_HOST="10.4.32.3"
REMOTE_PATH="/home/probance_certif/docker/certif-app"


RSYNC_PARAM=(
  -avz
  --itemize-changes
  --delete
  --exclude='.*'
  --exclude='*.md'
  --exclude='deploy.sh'
  --exclude='docker-compose.yml'
  --exclude='Dockerfile'
  --exclude='README.md'
  --exclude='docs'
  --exclude='data'
  --exclude='resources'
)


echo "Debug:"
echo "rsync --dry-run ${RSYNC_PARAM[@]} . \"${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}\""
rsync --dry-run ${RSYNC_PARAM[@]} . "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_PATH}"


read -rp "Proceed with rsync? [y/N] " answer
[[ "$answer" =~ ^([Yy]|[Yy][Ee][Ss])$ ]] || exit 1


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

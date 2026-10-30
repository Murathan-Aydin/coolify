#!/bin/bash
## Installs (or updates) Coolify from the Murathan-Aydin/coolify fork.
## curl -fsSL https://install-coolify.m-aydin.fr | sudo bash
## (redirects to https://raw.githubusercontent.com/Murathan-Aydin/coolify/main/scripts/install-fork.sh)
##
## It runs the official installer, but first writes docker-compose.custom.yml so the
## coolify service uses the fork image. The official upgrade script keeps that file,
## so Coolify never switches back to the official image. Auto updates are turned off.
## Environment variables:
## COOLIFY_FORK_IMAGE - Image to run (default: ghcr.io/murathan-aydin/coolify:latest)

set -e

FORK_IMAGE="${COOLIFY_FORK_IMAGE:-ghcr.io/murathan-aydin/coolify:latest}"
SOURCE_DIR=/data/coolify/source

if [ "$EUID" -ne 0 ]; then
    echo "Run this script as root: curl ... | sudo bash"
    exit 1
fi

echo "Using the fork image: ${FORK_IMAGE}"
mkdir -p "$SOURCE_DIR"
cat >"$SOURCE_DIR/docker-compose.custom.yml" <<EOF
# Written by install-fork.sh: run the Murathan-Aydin/coolify fork instead of the official image.
services:
  coolify:
    image: ${FORK_IMAGE}
    pull_policy: always
EOF

# Pull now so an unreachable or private image fails here, before the installer starts.
if command -v docker >/dev/null 2>&1; then
    docker pull "$FORK_IMAGE"
fi

curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash

# Official auto updates would replace the fork's version number and restart on official releases.
DB_USERNAME=$(grep '^DB_USERNAME=' "$SOURCE_DIR/.env" | cut -d '=' -f2)
for _ in $(seq 1 30); do
    if docker exec coolify-db psql -U "${DB_USERNAME:-coolify}" -d coolify -c \
        "UPDATE instance_settings SET is_auto_update_enabled = false;" >/dev/null 2>&1; then
        echo "Auto updates disabled."
        break
    fi
    sleep 2
done

echo "Coolify now runs $(docker inspect -f '{{.Config.Image}}' coolify)."
echo "To update to the latest fork build later, run this same command again."

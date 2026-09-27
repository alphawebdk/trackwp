#!/bin/sh
# Installs JS deps (cached in the e2e-node-modules volume, see
# tests/docker/compose.yml) then execs whatever command was given, so
# `docker compose run --rm e2e npx playwright test <spec>` keeps working
# without baking package.json into the image (package.json is owned by W8
# and changes after this image is built).
set -eu
cd /workspace
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi
exec "$@"

#!/bin/sh
# Run isolated backend checks and all frontend gates without starting application processes.
set -eu

# Resolve the repository from this script, without invoking Git or changing user configuration.
cd "$(dirname "$0")/.."
docker compose config --quiet
docker compose run --rm php-test composer verify
docker compose run --rm node npm run verify

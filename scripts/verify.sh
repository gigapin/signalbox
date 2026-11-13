#!/bin/sh
# Run isolated backend checks and all frontend gates without starting application processes.
set -eu

# Resolve the repository from this script, without invoking Git or changing user configuration.
cd "$(dirname "$0")/.."
docker compose config --quiet
# Fail on published advisories for locked PHP packages; needs Packagist access but no database or Redis.
docker compose run --rm --no-deps php-test composer audit:locked
docker compose run --rm php-test composer verify
docker compose run --rm node npm run verify

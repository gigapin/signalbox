#!/bin/sh
# Run the backend suite as if today were each given date, to find tests that depend on the real month.
#
# Usage: ./scripts/verify-dates.sh [YYYY-MM-DD[ HH:MM:SS]]...
#
# Only the PHP clock moves: PostgreSQL keeps its own, because lock_timeout never fires on a server under libfaketime and
# the tests relying on it would block forever. libfaketime is installed in a disposable container, so no image changes,
# but the run needs network access. It uses the isolated test services and never runs a Git command.
# Each date stops after 20 minutes, so a blocked test is reported as a failure (exit status 124) instead of hanging.
set -eu

# Resolve the repository from this script.
cd "$(dirname "$0")/.."

if [ "$#" -eq 0 ]; then
    # First day of a month, a month end, a year change during the run, a leap day and one year ahead of the fixed dates.
    set -- '2026-12-01' '2027-01-31' '2026-12-31 23:55:00' '2027-02-28' '2028-02-29' '2027-12-01'
fi

docker compose run --rm -u root --entrypoint sh php-test -c '
    set -u
    apt-get update -qq >/dev/null 2>&1
    apt-get install -y -qq faketime >/dev/null 2>&1
    failed=0

    for date in "$@"; do
        case "$date" in
            *:*) instant="$date" ;;
            *) instant="$date 10:00:00" ;;
        esac

        echo "== Simulated date: $instant"

        if ! timeout 1200 faketime -f "@$instant" vendor/bin/pest --colors=never --fail-on-skipped --fail-on-warning --compact; then
            failed=1
        fi
    done

    exit "$failed"
' sh "$@"

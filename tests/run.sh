#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
# Select either Docker's Compose plugin or the standalone Homebrew command.
if docker compose version >/dev/null 2>&1; then
    compose() { docker compose -f compose.test.yml "$@"; }
else
    compose() { docker-compose -f compose.test.yml "$@"; }
fi
compose up -d --build
compose exec -T php composer install --no-interaction
compose exec -T php composer validate --strict
compose exec -T php php tests/lint.php
compose exec -T php php tests/init_database.php
compose exec -T php php scripts/migrate_school_console.php
compose exec -T php php tests/integration.php
compose exec -T php php scripts/test_helpers.php

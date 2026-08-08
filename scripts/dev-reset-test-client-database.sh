#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

NON_INTERACTIVE=false

if [[ "${1:-}" == "--force" ]]; then
    NON_INTERACTIVE=true
elif [[ $# -gt 0 ]]; then
    echo "Usage: ./scripts/dev-reset-test-client-database.sh [--force]"
    exit 1
fi

if [[ ! -f artisan || ! -f bootstrap/app.php ]]; then
    echo "Run this script from an Engage Core checkout with artisan and bootstrap/app.php present."
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "PHP is required."
    exit 1
fi

#
# Safety gate 1:
# Determine the application environment through Artisan itself before running
# any cache-clearing or destructive operation.
#
APP_ENV_VALUE="$(
    php artisan env --no-ansi 2>/dev/null \
        | awk -F'[][]' '/The application environment is/ { print $2; exit }'
)"

if [[ "$APP_ENV_VALUE" != "local" ]]; then
    echo "Refusing database reset: APP_ENV must resolve to local, got '$APP_ENV_VALUE'."
    exit 1
fi

#
# Scenario config changes must be visible before resolving the selected client
# and database. At this point we have already proven the application is local.
#
php artisan optimize:clear >/dev/null

#
# Resolve the remaining safety context through a fully booted Artisan command.
# Do not manually require bootstrap/app.php here: the selected-client config and
# client environment are part of the application's normal Artisan bootstrap.
#
CONTEXT_OUTPUT="$(
    php artisan tinker --execute='
$connection = trim((string) config("database.default"));

fwrite(
    STDOUT,
    "__CLIENT_KEY__=" . trim((string) config("client.key")) . PHP_EOL
);

fwrite(
    STDOUT,
    "__DB_CONNECTION__=" . $connection . PHP_EOL
);

fwrite(
    STDOUT,
    "__DB_DATABASE__=" . trim(
        (string) config("database.connections." . $connection . ".database")
    ) . PHP_EOL
);
' 2>/dev/null
)"

CLIENT_KEY_VALUE="$(
    printf '%s\n' "$CONTEXT_OUTPUT" \
        | sed -n 's/^__CLIENT_KEY__=//p' \
        | tail -n 1
)"

DB_CONNECTION_VALUE="$(
    printf '%s\n' "$CONTEXT_OUTPUT" \
        | sed -n 's/^__DB_CONNECTION__=//p' \
        | tail -n 1
)"

DB_DATABASE_VALUE="$(
    printf '%s\n' "$CONTEXT_OUTPUT" \
        | sed -n 's/^__DB_DATABASE__=//p' \
        | tail -n 1
)"

if [[ "$CLIENT_KEY_VALUE" != "test-client" ]]; then
    echo "Refusing database reset: selected client must resolve to test-client, got '$CLIENT_KEY_VALUE'."
    exit 1
fi

if [[ -z "$DB_CONNECTION_VALUE" ]]; then
    echo "Refusing database reset: the effective database connection is empty."
    exit 1
fi

if [[ -z "$DB_DATABASE_VALUE" ]]; then
    echo "Refusing database reset: the effective database name is empty."
    exit 1
fi

echo "Engage Core dev reset"
echo "  Environment: $APP_ENV_VALUE"
echo "  Client:      $CLIENT_KEY_VALUE"
echo "  Connection:  $DB_CONNECTION_VALUE"
echo "  Database:    $DB_DATABASE_VALUE"
echo
echo "This drops every table, view, and Laravel migration-history row in the selected database."
echo "It does not drop/recreate the MySQL database itself and does not clear Redis."

if [[ "$NON_INTERACTIVE" != true ]]; then
    read -r -p "Type RESET TEST CLIENT to continue: " CONFIRMATION

    if [[ "$CONFIRMATION" != "RESET TEST CLIENT" ]]; then
        echo "Reset cancelled."
        exit 1
    fi
fi

php artisan db:wipe --force

echo
echo "Test-client database is empty."
echo "Next: php artisan engage:install"
#!/usr/bin/env bash

set -euo pipefail

PACKAGE_NAME="${1:-}"
LOCAL_PACKAGE_INPUT="${2:-}"
REQUESTED_CLIENT_KEY="${3:-${CLIENT_KEY:-}}"

if [[ -z "$PACKAGE_NAME" || -z "$LOCAL_PACKAGE_INPUT" ]]; then
    echo "Usage: ./scripts/dev-link-package.sh vendor/package /absolute/path/to/local/package [client-key]"
    echo "Example: ./scripts/dev-link-package.sh imagine-social/engage-integration-shopify /var/www/engage-integration-shopify"
    exit 1
fi

if [[ ! "$PACKAGE_NAME" =~ ^[a-z0-9_.-]+/[a-z0-9_.-]+$ ]]; then
    echo "Package name must use Composer vendor/package format."
    exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f artisan || ! -f composer.json || ! -f vendor/autoload.php ]]; then
    echo "Run this script from an installed Engage Core checkout."
    exit 1
fi

if ! command -v php >/dev/null 2>&1; then
    echo "PHP is required."
    exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
    echo "Composer is required."
    exit 1
fi

mapfile -t ROOT_CONTEXT < <(
    php -r '
require $argv[1]."/vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable($argv[1]);
$dotenv->safeLoad();

$read = static function (string $key): string {
    $candidates = [
        getenv($key),
        $_ENV[$key] ?? null,
        $_SERVER[$key] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && trim($candidate) !== "") {
            return trim($candidate);
        }
    }

    return "";
};

echo $read("APP_ENV"), PHP_EOL;
echo $read("CLIENT_KEY"), PHP_EOL;
' "$ROOT_DIR"
)

APP_ENV_VALUE="${ROOT_CONTEXT[0]:-}"
SELECTED_CLIENT_KEY="${ROOT_CONTEXT[1]:-}"
CLIENT_KEY_VALUE="$REQUESTED_CLIENT_KEY"

if [[ -z "$CLIENT_KEY_VALUE" ]]; then
    CLIENT_KEY_VALUE="$SELECTED_CLIENT_KEY"
fi

if [[ "$APP_ENV_VALUE" != "local" ]]; then
    echo "Refusing package link: APP_ENV must resolve to local, got '${APP_ENV_VALUE:-blank}'."
    exit 1
fi

if [[ -z "$CLIENT_KEY_VALUE" ]]; then
    echo "Refusing package link: no client key was supplied and root CLIENT_KEY is blank."
    exit 1
fi

if [[ ! "$CLIENT_KEY_VALUE" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "Refusing package link: invalid client key '$CLIENT_KEY_VALUE'."
    exit 1
fi

if [[ -n "$SELECTED_CLIENT_KEY" && "$CLIENT_KEY_VALUE" != "$SELECTED_CLIENT_KEY" ]]; then
    echo "Refusing package link: requested client '$CLIENT_KEY_VALUE' does not match selected root CLIENT_KEY '$SELECTED_CLIENT_KEY'."
    exit 1
fi

CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY_VALUE"
CLIENT_COMPOSER="$CLIENT_DIR/composer.json"
VENDOR_DIR="$CLIENT_DIR/vendor"
AUTOLOAD_PATH="$VENDOR_DIR/autoload.php"
PACKAGE_TARGET="$VENDOR_DIR/$PACKAGE_NAME"
PACKAGE_PARENT="$(dirname "$PACKAGE_TARGET")"

if [[ ! -d "$CLIENT_DIR" || ! -f "$CLIENT_COMPOSER" ]]; then
    echo "Selected client '$CLIENT_KEY_VALUE' does not declare Composer packages."
    exit 1
fi

if [[ ! -d "$LOCAL_PACKAGE_INPUT" ]]; then
    echo "Local package directory does not exist: $LOCAL_PACKAGE_INPUT"
    exit 1
fi

LOCAL_PACKAGE_DIR="$(cd "$LOCAL_PACKAGE_INPUT" && pwd -P)"
LOCAL_COMPOSER="$LOCAL_PACKAGE_DIR/composer.json"

if [[ ! -f "$LOCAL_COMPOSER" ]]; then
    echo "Local package has no composer.json: $LOCAL_PACKAGE_DIR"
    exit 1
fi

LOCAL_PACKAGE_NAME="$(
    php -r '
try {
    $document = json_decode(
        file_get_contents($argv[1]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid local package composer.json: ".$exception->getMessage().PHP_EOL);
    exit(2);
}

$name = $document["name"] ?? null;

if (! is_string($name) || trim($name) === "") {
    fwrite(STDERR, "Local package composer.json must define a non-blank name.".PHP_EOL);
    exit(2);
}

echo trim($name);
' "$LOCAL_COMPOSER"
)"

if [[ "$LOCAL_PACKAGE_NAME" != "$PACKAGE_NAME" ]]; then
    echo "Local package name mismatch: expected '$PACKAGE_NAME', found '$LOCAL_PACKAGE_NAME'."
    exit 1
fi

if ! php -r '
try {
    $document = json_decode(
        file_get_contents($argv[1]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid client composer.json: ".$exception->getMessage().PHP_EOL);
    exit(2);
}

$requirements = array_merge(
    is_array($document["require"] ?? null) ? $document["require"] : [],
    is_array($document["require-dev"] ?? null) ? $document["require-dev"] : [],
);

exit(array_key_exists($argv[2], $requirements) ? 0 : 3);
' "$CLIENT_COMPOSER" "$PACKAGE_NAME"; then
    echo "Client '$CLIENT_KEY_VALUE' does not require package '$PACKAGE_NAME'."
    exit 1
fi

VENDOR_DIR_VALUE="$(
    php -r '
try {
    $document = json_decode(
        file_get_contents($argv[1]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid client composer.json: ".$exception->getMessage().PHP_EOL);
    exit(2);
}

$vendorDir = $document["config"]["vendor-dir"] ?? "vendor";

if (! is_string($vendorDir) || trim($vendorDir) === "") {
    fwrite(STDERR, "Client Composer vendor-dir must be a non-blank string.".PHP_EOL);
    exit(2);
}

echo trim($vendorDir);
' "$CLIENT_COMPOSER"
)"

if [[ "$VENDOR_DIR_VALUE" != "vendor" ]]; then
    echo "Refusing package link: Engage Core client package bootstrap currently requires Composer vendor-dir to remain 'vendor'."
    exit 1
fi

if [[ ! -f "$AUTOLOAD_PATH" ]]; then
    echo "Selected client packages are not installed. Run composer install inside client/$CLIENT_KEY_VALUE first."
    exit 1
fi

if [[ -L "$PACKAGE_TARGET" ]]; then
    CURRENT_TARGET="$(readlink -f "$PACKAGE_TARGET" || true)"

    if [[ "$CURRENT_TARGET" == "$LOCAL_PACKAGE_DIR" ]]; then
        echo "Package already linked: $PACKAGE_NAME"
        echo "  Client: $CLIENT_KEY_VALUE"
        echo "  Local:  $LOCAL_PACKAGE_DIR"
        exit 0
    fi

    echo "Package target is already a symlink to another location: $PACKAGE_TARGET"
    echo "Run ./scripts/dev-unlink-package.sh $PACKAGE_NAME first."
    exit 1
fi

if [[ ! -d "$PACKAGE_TARGET" ]]; then
    echo "Installed package directory is missing: $PACKAGE_TARGET"
    echo "Run composer install inside client/$CLIENT_KEY_VALUE before linking."
    exit 1
fi

if ! (
    cd "$CLIENT_DIR"
    composer show "$PACKAGE_NAME" --no-interaction >/dev/null 2>&1
); then
    echo "Composer does not report '$PACKAGE_NAME' as installed for client '$CLIENT_KEY_VALUE'."
    exit 1
fi

mkdir -p "$PACKAGE_PARENT"
BACKUP_TARGET="$PACKAGE_TARGET.engage-link-backup.$$"

mv "$PACKAGE_TARGET" "$BACKUP_TARGET"

if ! ln -s "$LOCAL_PACKAGE_DIR" "$PACKAGE_TARGET"; then
    mv "$BACKUP_TARGET" "$PACKAGE_TARGET"
    echo "Unable to create local package symlink. Original Composer package was restored."
    exit 1
fi

rm -rf "$BACKUP_TARGET"

php artisan view:clear >/dev/null

echo "Linked local Engage Core package."
echo "  Client:  $CLIENT_KEY_VALUE"
echo "  Package: $PACKAGE_NAME"
echo "  Local:   $LOCAL_PACKAGE_DIR"
echo "  Runtime: $PACKAGE_TARGET -> $LOCAL_PACKAGE_DIR"
echo
echo "Ordinary source edits under the local package checkout now appear in this client's runtime without a Git push or Composer update."
echo "For package composer.json dependency/autoload contract changes, restore the Composer install, update dependencies normally, then link again."
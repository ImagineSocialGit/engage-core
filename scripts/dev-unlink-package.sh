#!/usr/bin/env bash

set -euo pipefail

PACKAGE_NAME="${1:-}"
REQUESTED_CLIENT_KEY="${2:-${CLIENT_KEY:-}}"

if [[ -z "$PACKAGE_NAME" ]]; then
    echo "Usage: ./scripts/dev-unlink-package.sh vendor/package [client-key]"
    echo "Example: ./scripts/dev-unlink-package.sh imagine-social/engage-integration-shopify"
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

if ! command -v git >/dev/null 2>&1; then
    echo "Git is required to preflight the locked private package source."
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
    echo "Refusing package unlink: APP_ENV must resolve to local, got '${APP_ENV_VALUE:-blank}'."
    exit 1
fi

if [[ -z "$CLIENT_KEY_VALUE" ]]; then
    echo "Refusing package unlink: no client key was supplied and root CLIENT_KEY is blank."
    exit 1
fi

if [[ ! "$CLIENT_KEY_VALUE" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
    echo "Refusing package unlink: invalid client key '$CLIENT_KEY_VALUE'."
    exit 1
fi

if [[ -n "$SELECTED_CLIENT_KEY" && "$CLIENT_KEY_VALUE" != "$SELECTED_CLIENT_KEY" ]]; then
    echo "Refusing package unlink: requested client '$CLIENT_KEY_VALUE' does not match selected root CLIENT_KEY '$SELECTED_CLIENT_KEY'."
    exit 1
fi

CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY_VALUE"
CLIENT_COMPOSER="$CLIENT_DIR/composer.json"
CLIENT_LOCK="$CLIENT_DIR/composer.lock"
VENDOR_DIR="$CLIENT_DIR/vendor"
PACKAGE_TARGET="$VENDOR_DIR/$PACKAGE_NAME"

if [[ ! -f "$CLIENT_COMPOSER" || ! -f "$CLIENT_LOCK" ]]; then
    echo "Selected client '$CLIENT_KEY_VALUE' needs both composer.json and composer.lock to restore a package safely."
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
    echo "Refusing package unlink: Engage Core client package bootstrap currently requires Composer vendor-dir to remain 'vendor'."
    exit 1
fi

if [[ ! -L "$PACKAGE_TARGET" ]]; then
    echo "Package is not locally linked: $PACKAGE_NAME"
    echo "Runtime path: $PACKAGE_TARGET"
    exit 0
fi

LOCAL_PACKAGE_DIR="$(readlink -f "$PACKAGE_TARGET" || true)"

if [[ -z "$LOCAL_PACKAGE_DIR" || ! -d "$LOCAL_PACKAGE_DIR" ]]; then
    echo "The package symlink target is missing or unreadable: $PACKAGE_TARGET"
    exit 1
fi

mapfile -t LOCK_SOURCE < <(
    php -r '
try {
    $document = json_decode(
        file_get_contents($argv[1]),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Invalid client composer.lock: ".$exception->getMessage().PHP_EOL);
    exit(2);
}

$packages = array_merge(
    is_array($document["packages"] ?? null) ? $document["packages"] : [],
    is_array($document["packages-dev"] ?? null) ? $document["packages-dev"] : [],
);

foreach ($packages as $package) {
    if (($package["name"] ?? null) !== $argv[2]) {
        continue;
    }

    $url = $package["source"]["url"] ?? "";
    $reference = $package["source"]["reference"] ?? "";

    echo is_string($url) ? trim($url) : "", PHP_EOL;
    echo is_string($reference) ? trim($reference) : "", PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Package is not present in composer.lock: ".$argv[2].PHP_EOL);
exit(3);
' "$CLIENT_LOCK" "$PACKAGE_NAME"
)

SOURCE_URL="${LOCK_SOURCE[0]:-}"
SOURCE_REFERENCE="${LOCK_SOURCE[1]:-}"

if [[ -z "$SOURCE_URL" ]]; then
    echo "Locked package '$PACKAGE_NAME' has no source URL; refusing to remove the working local link."
    exit 1
fi

if ! git ls-remote "$SOURCE_URL" >/dev/null 2>&1; then
    echo "Cannot read the locked package source before restore: $SOURCE_URL"
    echo "The local link has been left untouched. Check GitHub authentication and try again."
    exit 1
fi

LOCK_HASH_BEFORE="$(
    php -r 'echo hash_file("sha256", $argv[1]);' "$CLIENT_LOCK"
)"

if ! (
    cd "$CLIENT_DIR"
    composer reinstall "$PACKAGE_NAME" --no-interaction
); then
    if [[ ! -e "$PACKAGE_TARGET" ]]; then
        ln -s "$LOCAL_PACKAGE_DIR" "$PACKAGE_TARGET" || true
    fi

    echo "Composer could not restore the locked package."
    echo "The script attempted to preserve the local development link at: $PACKAGE_TARGET"
    exit 1
fi

if [[ -L "$PACKAGE_TARGET" ]]; then
    echo "Composer reinstall completed but the package path is still a symlink; refusing to report a successful restore."
    exit 1
fi

LOCK_HASH_AFTER="$(
    php -r 'echo hash_file("sha256", $argv[1]);' "$CLIENT_LOCK"
)"

if [[ "$LOCK_HASH_BEFORE" != "$LOCK_HASH_AFTER" ]]; then
    echo "composer.lock changed during package restore, which is not expected from composer reinstall."
    echo "Review client/$CLIENT_KEY_VALUE/composer.lock before committing."
    exit 1
fi

php artisan view:clear >/dev/null

echo "Restored Composer-managed Engage Core package."
echo "  Client:    $CLIENT_KEY_VALUE"
echo "  Package:   $PACKAGE_NAME"
echo "  Reference: ${SOURCE_REFERENCE:-locked revision}"
echo "  Runtime:   $PACKAGE_TARGET"
echo
echo "The client is no longer using the local package checkout."
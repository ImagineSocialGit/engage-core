#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    printf 'ERROR: package workflow requires an Engage Core checkout.\n' >&2
    exit 1
fi

if [[ $# -lt 1 ]]; then
    echo 'Usage: ./scripts/package.sh create|install|update|repair|adopt|link|unlink|verify|audit ...' >&2
    exit 2
fi

ACTION="$1"
shift

case "$ACTION" in
    create|install|adopt|verify|audit)
        exec python3 "$ROOT_DIR/scripts/operations/lib/package_workflow.py" "$ACTION" "$@"
        ;;
    update|repair)
        exec python3 "$ROOT_DIR/scripts/operations/lib/package_revision_workflow.py" "$ACTION" "$@"
        ;;
    link)
        if [[ $# -ne 2 ]]; then
            echo 'Usage: ./scripts/package.sh link CLIENT_KEY /absolute/path/to/local/package' >&2
            exit 2
        fi
        CLIENT_KEY="$1"
        PACKAGE_DIR="$2"
        [[ -f "$PACKAGE_DIR/composer.json" ]] || { echo "Missing package composer.json: $PACKAGE_DIR" >&2; exit 1; }
        PACKAGE_NAME="$(php -r '$v=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);echo $v["name"]??"";' "$PACKAGE_DIR/composer.json")"
        exec "$ROOT_DIR/scripts/dev-link-package.sh" "$PACKAGE_NAME" "$PACKAGE_DIR" "$CLIENT_KEY"
        ;;
    unlink)
        if [[ $# -ne 2 ]]; then
            echo 'Usage: ./scripts/package.sh unlink CLIENT_KEY vendor/package' >&2
            exit 2
        fi
        exec "$ROOT_DIR/scripts/dev-unlink-package.sh" "$2" "$1"
        ;;
    *)
        echo "Unknown package action: $ACTION" >&2
        echo 'Usage: ./scripts/package.sh create|install|update|repair|adopt|link|unlink|verify|audit ...' >&2
        exit 2
        ;;
esac
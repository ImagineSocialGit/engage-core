#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: $0 --env-file PATH --app-path PATH [--level info] [--days 14] [--apply]"
}

ENV_FILE=""
APP_PATH=""
LEVEL="info"
DAYS="14"
APPLY=false
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
HELPER="$SCRIPT_DIR/lib/launch_client_environment.py"

while (($#)); do
    case "$1" in
        --env-file) ENV_FILE=${2:?}; shift 2 ;;
        --app-path) APP_PATH=${2:?}; shift 2 ;;
        --level) LEVEL=${2:?}; shift 2 ;;
        --days) DAYS=${2:?}; shift 2 ;;
        --apply) APPLY=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

[[ -f "$ENV_FILE" && ! -L "$ENV_FILE" ]] || { echo "Environment file missing or symlinked: $ENV_FILE" >&2; exit 1; }
[[ -f "$APP_PATH/artisan" ]] || { echo "Laravel app not found: $APP_PATH" >&2; exit 1; }
[[ "$DAYS" =~ ^[0-9]+$ ]] || { echo "--days must be numeric" >&2; exit 1; }
[[ "$LEVEL" =~ ^[a-z]+$ ]] || { echo "--level must be a lowercase logging level" >&2; exit 1; }
[[ -f "$HELPER" ]] || { echo "Secure environment writer not found: $HELPER" >&2; exit 1; }

if [[ "$APPLY" != true ]]; then
    # Never print a diff of a secret-bearing .env, including unchanged values.
    echo "DRY RUN — would set LOG_CHANNEL, LOG_STACK, LOG_LEVEL, and LOG_DAILY_DAYS. No environment contents displayed."
    exit 0
fi

# Do not leave a copied secret-bearing .env backup on disk. The central writer
# preserves owner/group/mode/ACL before each atomic replacement, or fails closed.
python3 "$HELPER" env-set --file "$ENV_FILE" --key LOG_CHANNEL --value stack
python3 "$HELPER" env-set --file "$ENV_FILE" --key LOG_STACK --value daily_json
python3 "$HELPER" env-set --file "$ENV_FILE" --key LOG_LEVEL --value "$LEVEL"
python3 "$HELPER" env-set --file "$ENV_FILE" --key LOG_DAILY_DAYS --value "$DAYS"

(
    cd "$APP_PATH"
    php artisan optimize:clear
)

echo "Client logging environment updated without creating a secret-file backup."
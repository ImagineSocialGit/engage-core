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

[[ -f "$ENV_FILE" ]] || { echo "Environment file not found: $ENV_FILE" >&2; exit 1; }
[[ -f "$APP_PATH/artisan" ]] || { echo "Laravel app not found: $APP_PATH" >&2; exit 1; }
[[ "$DAYS" =~ ^[0-9]+$ ]] || { echo "--days must be numeric" >&2; exit 1; }

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT

python3 - "$ENV_FILE" "$TMP" "$LEVEL" "$DAYS" <<'PY'
from pathlib import Path
import sys

source, output, level, days = sys.argv[1:]
text = Path(source).read_text()
values = {
    'LOG_CHANNEL': 'stack',
    'LOG_STACK': 'daily_json',
    'LOG_LEVEL': level,
    'LOG_DAILY_DAYS': days,
}
lines = text.splitlines()
seen = set()
out = []
for line in lines:
    stripped = line.strip()
    replaced = False
    for key, value in values.items():
        if stripped.startswith(key + '='):
            out.append(f'{key}={value}')
            seen.add(key)
            replaced = True
            break
    if not replaced:
        out.append(line)
for key, value in values.items():
    if key not in seen:
        out.append(f'{key}={value}')
Path(output).write_text('\n'.join(out) + '\n')
PY

if [[ "$APPLY" != true ]]; then
    echo "DRY RUN — no files changed"
    diff -u "$ENV_FILE" "$TMP" || true
    exit 0
fi

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP="$ENV_FILE.$STAMP.bak"
cp -a "$ENV_FILE" "$BACKUP"
install -m "$(stat -c '%a' "$ENV_FILE")" "$TMP" "$ENV_FILE"

(
    cd "$APP_PATH"
    php artisan optimize:clear
)

echo "Client logging environment updated. Backup: $BACKUP"

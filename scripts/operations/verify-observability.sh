#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: verify-observability.sh \
  --app-path PATH \
  --public-url URL \
  --access-log PATH \
  --php-version VERSION
EOF
}

APP_PATH=""
PUBLIC_URL=""
ACCESS_LOG=""
PHP_VERSION=""

while (($#)); do
    case "$1" in
        --app-path) APP_PATH=${2:?}; shift 2 ;;
        --public-url) PUBLIC_URL=${2:?}; shift 2 ;;
        --access-log) ACCESS_LOG=${2:?}; shift 2 ;;
        --php-version) PHP_VERSION=${2:?}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

[[ -f "$APP_PATH/artisan" ]] || { echo "Laravel app not found: $APP_PATH" >&2; exit 1; }
[[ -f "$ACCESS_LOG" ]] || { echo "Access log not found: $ACCESS_LOG" >&2; exit 1; }

nginx -t
"php-fpm$PHP_VERSION" -tt >/dev/null

grep -q 'log_format engage_core_json' /etc/nginx/conf.d/00-engage-core-observability.conf
grep -q 'engage_core_json' "$ACCESS_LOG" 2>/dev/null || true

HEADERS=$(mktemp)
trap 'rm -f "$HEADERS"' EXIT
curl --silent --show-error --location --max-time 15 --dump-header "$HEADERS" --output /dev/null "$PUBLIC_URL"

REQUEST_ID=$(awk 'BEGIN{IGNORECASE=1} /^X-Request-ID:/ {gsub("\\r", "", $2); print $2; exit}' "$HEADERS")
[[ -n "$REQUEST_ID" ]] || { echo "X-Request-ID response header missing" >&2; exit 1; }
echo "Response request ID: $REQUEST_ID"

sleep 1
LOG_LINE=$(grep -F "\"request_id\":\"$REQUEST_ID\"" "$ACCESS_LOG" | tail -n 1 || true)
[[ -n "$LOG_LINE" ]] || { echo "Matching access-log entry not found" >&2; exit 1; }

python3 - "$LOG_LINE" <<'PY'
import json
import sys
record = json.loads(sys.argv[1])
required = {
    'timestamp', 'request_id', 'host', 'method', 'path', 'status',
    'request_time', 'upstream_response_time', 'client_class'
}
missing = sorted(required - record.keys())
if missing:
    raise SystemExit(f'Missing JSON log fields: {missing}')
if '?' in record['path']:
    raise SystemExit('Safe path unexpectedly contains a query string.')
print(json.dumps(record, indent=2, sort_keys=True))
PY

(
    cd "$APP_PATH"
    php artisan tinker --execute="dump([\
        'log_channel' => config('logging.default'), \
        'log_stack' => config('logging.channels.stack.channels'), \
        'daily_days' => config('logging.channels.daily_json.days'), \
    ]);"
)

echo "Observability verification passed."

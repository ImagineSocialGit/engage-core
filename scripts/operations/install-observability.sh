#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage:
  install-observability.sh \
    --client-key KEY \
    --app-path PATH \
    --nginx-site PATH \
    --access-log PATH \
    --php-version VERSION \
    --php-pool-config PATH \
    --deploy-user USER \
    [--php-pool-name NAME] \
    [--apply]

Without --apply, the script performs a dry run and prints proposed diffs.
EOF
}

CLIENT_KEY=""
APP_PATH=""
NGINX_SITE=""
ACCESS_LOG=""
PHP_VERSION=""
PHP_POOL_CONFIG=""
PHP_POOL_NAME="www"
DEPLOY_USER=""
APPLY=false

while (($#)); do
    case "$1" in
        --client-key) CLIENT_KEY=${2:?}; shift 2 ;;
        --app-path) APP_PATH=${2:?}; shift 2 ;;
        --nginx-site) NGINX_SITE=${2:?}; shift 2 ;;
        --access-log) ACCESS_LOG=${2:?}; shift 2 ;;
        --php-version) PHP_VERSION=${2:?}; shift 2 ;;
        --php-pool-config) PHP_POOL_CONFIG=${2:?}; shift 2 ;;
        --php-pool-name) PHP_POOL_NAME=${2:?}; shift 2 ;;
        --deploy-user) DEPLOY_USER=${2:?}; shift 2 ;;
        --apply) APPLY=true; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; usage >&2; exit 2 ;;
    esac
done

for value in CLIENT_KEY APP_PATH NGINX_SITE ACCESS_LOG PHP_VERSION PHP_POOL_CONFIG DEPLOY_USER; do
    if [[ -z ${!value} ]]; then
        echo "Missing required input: $value" >&2
        usage >&2
        exit 2
    fi
done

[[ -d "$APP_PATH" ]] || { echo "App path not found: $APP_PATH" >&2; exit 1; }
NGINX_SITE=$(readlink -f "$NGINX_SITE")
PHP_POOL_CONFIG=$(readlink -f "$PHP_POOL_CONFIG")
[[ -f "$NGINX_SITE" ]] || { echo "Nginx site not found: $NGINX_SITE" >&2; exit 1; }
[[ -f "$PHP_POOL_CONFIG" ]] || { echo "PHP-FPM pool config not found: $PHP_POOL_CONFIG" >&2; exit 1; }
id "$DEPLOY_USER" >/dev/null 2>&1 || { echo "Deploy user not found: $DEPLOY_USER" >&2; exit 1; }

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
BUNDLE_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)
TMP_DIR=$(mktemp -d)
trap 'rm -rf "$TMP_DIR"' EXIT

PATCHED_SITE="$TMP_DIR/nginx-site.conf"
PATCHED_POOL="$TMP_DIR/php-fpm-pool.conf"
GLOBAL_NGINX="$BUNDLE_ROOT/ops/observability/nginx/engage-core-observability-http.conf"
FASTCGI_SNIPPET="$BUNDLE_ROOT/ops/observability/nginx/engage-core-request-id-fastcgi.conf"

python3 "$SCRIPT_DIR/lib/patch_nginx_site.py" \
    --input "$NGINX_SITE" \
    --output "$PATCHED_SITE" \
    --access-log "$ACCESS_LOG"

python3 "$SCRIPT_DIR/lib/patch_php_fpm_pool.py" \
    --input "$PHP_POOL_CONFIG" \
    --output "$PATCHED_POOL" \
    --pool-name "$PHP_POOL_NAME" \
    --php-version "$PHP_VERSION"

CLIENT_LOGROTATE="$TMP_DIR/engage-core-$CLIENT_KEY"
sed \
    -e "s|__APP_PATH__|$APP_PATH|g" \
    -e "s|__DEPLOY_USER__|$DEPLOY_USER|g" \
    "$BUNDLE_ROOT/ops/observability/logrotate/engage-core-client.template" \
    > "$CLIENT_LOGROTATE"

PHP_LOGROTATE="$TMP_DIR/engage-core-php-fpm-slow"
sed "s|__PHP_VERSION__|$PHP_VERSION|g" \
    "$BUNDLE_ROOT/ops/observability/logrotate/engage-core-php-fpm-slow.template" \
    > "$PHP_LOGROTATE"

if [[ "$APPLY" != true ]]; then
    echo "DRY RUN — no files changed"
    echo
    echo "--- Nginx site diff ---"
    diff -u "$NGINX_SITE" "$PATCHED_SITE" || true
    echo
    echo "--- PHP-FPM pool diff ---"
    diff -u "$PHP_POOL_CONFIG" "$PATCHED_POOL" || true
    echo
    echo "Would install:"
    printf '  %s\n' \
        /etc/nginx/conf.d/00-engage-core-observability.conf \
        /etc/nginx/snippets/engage-core-request-id-fastcgi.conf \
        "/etc/logrotate.d/engage-core-$CLIENT_KEY" \
        /etc/logrotate.d/engage-core-php-fpm-slow
    echo
    echo "Re-run with --apply under sudo after reviewing the diff."
    exit 0
fi

if [[ $EUID -ne 0 ]]; then
    echo "--apply must run as root (use sudo)." >&2
    exit 1
fi

STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKUP_DIR="/var/backups/engage-core-observability/$CLIENT_KEY/$STAMP"
MANIFEST="$BACKUP_DIR/manifest.txt"
mkdir -p "$BACKUP_DIR"
: > "$MANIFEST"

backup_target() {
    local target=$1
    if [[ -e "$target" ]]; then
        echo "EXISTING|$target" >> "$MANIFEST"
        mkdir -p "$BACKUP_DIR$(dirname "$target")"
        cp -a "$target" "$BACKUP_DIR$target"
    else
        echo "NEW|$target" >> "$MANIFEST"
    fi
}

restore_now() {
    echo "Validation failed; restoring files from $BACKUP_DIR" >&2
    while IFS='|' read -r state target; do
        if [[ "$state" == EXISTING ]]; then
            cp -a "$BACKUP_DIR$target" "$target"
        else
            rm -f "$target"
        fi
    done < "$MANIFEST"
}

TARGET_GLOBAL=/etc/nginx/conf.d/00-engage-core-observability.conf
TARGET_FASTCGI=/etc/nginx/snippets/engage-core-request-id-fastcgi.conf
TARGET_CLIENT_ROTATE="/etc/logrotate.d/engage-core-$CLIENT_KEY"
TARGET_PHP_ROTATE=/etc/logrotate.d/engage-core-php-fpm-slow

for target in \
    "$TARGET_GLOBAL" \
    "$TARGET_FASTCGI" \
    "$NGINX_SITE" \
    "$PHP_POOL_CONFIG" \
    "$TARGET_CLIENT_ROTATE" \
    "$TARGET_PHP_ROTATE"; do
    backup_target "$target"
done

install -o root -g root -m 0644 "$GLOBAL_NGINX" "$TARGET_GLOBAL"
install -o root -g root -m 0644 "$FASTCGI_SNIPPET" "$TARGET_FASTCGI"
install -o root -g root -m 0644 "$PATCHED_SITE" "$NGINX_SITE"
install -o root -g root -m 0644 "$PATCHED_POOL" "$PHP_POOL_CONFIG"
install -o root -g root -m 0644 "$CLIENT_LOGROTATE" "$TARGET_CLIENT_ROTATE"
install -o root -g root -m 0644 "$PHP_LOGROTATE" "$TARGET_PHP_ROTATE"
install -d -o www-data -g adm -m 0750 /var/log/php

if ! nginx -t; then restore_now; exit 1; fi

PHP_FPM_BIN="php-fpm$PHP_VERSION"
if ! command -v "$PHP_FPM_BIN" >/dev/null 2>&1; then
    echo "PHP-FPM validation binary not found: $PHP_FPM_BIN" >&2
    restore_now
    exit 1
fi

if ! "$PHP_FPM_BIN" -tt >/dev/null; then restore_now; exit 1; fi
if ! logrotate -d "$TARGET_CLIENT_ROTATE" >/dev/null 2>&1; then restore_now; exit 1; fi
if ! logrotate -d "$TARGET_PHP_ROTATE" >/dev/null 2>&1; then restore_now; exit 1; fi

systemctl reload nginx
systemctl reload "php$PHP_VERSION-fpm"

echo "Observability server configuration installed."
echo "Backup/rollback source: $BACKUP_DIR"
echo "Next: configure the client environment, deploy RequestCorrelation, then run verify-observability.sh."

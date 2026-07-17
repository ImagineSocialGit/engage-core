#!/usr/bin/env bash
set -euo pipefail

usage() {
    echo "Usage: sudo $0 --backup-dir PATH --php-version VERSION"
}

BACKUP_DIR=""
PHP_VERSION=""
while (($#)); do
    case "$1" in
        --backup-dir) BACKUP_DIR=${2:?}; shift 2 ;;
        --php-version) PHP_VERSION=${2:?}; shift 2 ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

[[ $EUID -eq 0 ]] || { echo "Rollback must run as root." >&2; exit 1; }
[[ -f "$BACKUP_DIR/manifest.txt" ]] || { echo "Backup manifest not found." >&2; exit 1; }

while IFS='|' read -r state target; do
    if [[ "$state" == EXISTING ]]; then
        [[ -e "$BACKUP_DIR$target" ]] || { echo "Missing backup: $target" >&2; exit 1; }
        cp -a "$BACKUP_DIR$target" "$target"
    elif [[ "$state" == NEW ]]; then
        rm -f "$target"
    else
        echo "Unknown manifest state: $state" >&2
        exit 1
    fi
done < "$BACKUP_DIR/manifest.txt"

nginx -t
"php-fpm$PHP_VERSION" -tt >/dev/null
systemctl reload nginx
systemctl reload "php$PHP_VERSION-fpm"

echo "Observability server configuration rolled back from $BACKUP_DIR"

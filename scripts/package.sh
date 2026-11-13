#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    printf 'ERROR: package workflow requires an Engage Core checkout.\n' >&2
    exit 1
fi

revision_operation() {
    local action="$1"
    shift
    if [[ $# -lt 3 ]]; then
        printf 'Usage: ./scripts/package.sh %s CLIENT_KEY vendor/package (--plan | --apply --confirm-plan FINGERPRINT) [--json]\n' "$action" >&2
        return 2
    fi
    local client_key="$1" package_name="$2"
    shift 2
    [[ "$client_key" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || { printf 'ERROR: Invalid client key.\n' >&2; return 2; }
    [[ "$package_name" =~ ^[a-z0-9][a-z0-9_.-]*/[a-z0-9][a-z0-9_.-]*$ ]] || { printf 'ERROR: Invalid Composer package name.\n' >&2; return 2; }

    # Only forward the shared contract's public mode/output options. Do not
    # allow callers to override --platform, --root, --client, --package, or --env.
    local mode='' seen_json=0 seen_confirm=0
    local -a options=()
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --plan|--apply)
                [[ -z "$mode" ]] || { printf 'ERROR: Choose exactly one --plan or --apply.\n' >&2; return 2; }
                mode="$1"
                options+=("$1")
                ;;
            --json)
                [[ "$seen_json" -eq 0 ]] || { printf 'ERROR: Duplicate --json.\n' >&2; return 2; }
                seen_json=1
                options+=("$1")
                ;;
            --confirm-plan)
                [[ "$seen_confirm" -eq 0 && $# -ge 2 && "$2" =~ ^[a-f0-9]{64}$ ]] || {
                    printf 'ERROR: --confirm-plan requires one 64-character lowercase hexadecimal fingerprint.\n' >&2; return 2;
                }
                seen_confirm=1
                options+=("$1" "$2")
                shift
                ;;
            --confirm-plan=*)
                local fingerprint="${1#--confirm-plan=}"
                [[ "$seen_confirm" -eq 0 && "$fingerprint" =~ ^[a-f0-9]{64}$ ]] || {
                    printf 'ERROR: Invalid or duplicate --confirm-plan fingerprint.\n' >&2; return 2;
                }
                seen_confirm=1
                options+=("$1")
                ;;
            *)
                printf 'ERROR: Unknown package revision option.\n' >&2
                return 2
                ;;
        esac
        shift
    done
    [[ -n "$mode" ]] || { printf 'ERROR: --plan or --apply is required.\n' >&2; return 2; }
    if [[ "$mode" == --plan && "$seen_confirm" -eq 1 ]]; then
        printf 'ERROR: --confirm-plan is valid only with --apply.\n' >&2
        return 2
    fi

    local infrastructure_root="${ENGAGE_INFRASTRUCTURE_ROOT:-$HOME/Projects/engage-infrastructure}"
    local engage_cli="$infrastructure_root/scripts/engage"
    local shared_engine="$infrastructure_root/scripts/lib/package_revision.py"
    [[ "$infrastructure_root" == /* ]] || { printf 'ERROR: Infrastructure root must be an absolute path.\n' >&2; return 2; }
    if [[ -e "$infrastructure_root" || -L "$infrastructure_root" ]]; then
        if [[ -L "$infrastructure_root" || ! -d "$infrastructure_root" ||
              -L "$infrastructure_root/scripts" || -L "$infrastructure_root/scripts/lib" ||
              -L "$engage_cli" || ! -f "$engage_cli" ||
              -L "$shared_engine" || ! -f "$shared_engine" ]]; then
            printf 'ERROR: Infrastructure checkout does not contain the verified B51 package revision command.\n' >&2
            return 1
        fi
        exec python3 "$engage_cli" package "$action" \
            --env local --platform core --root "$ROOT_DIR" \
            --client "$client_key" --package "$package_name" "${options[@]}"
    fi

    # Core's existing B47 command remains available when the shared local
    # operator repository is not installed. It is not the cross-platform engine.
    if [[ -n "${ENGAGE_INFRASTRUCTURE_ROOT+x}" ]]; then
        printf 'ERROR: Explicit Infrastructure checkout was not found.\n' >&2
        return 1
    fi
    exec python3 "$ROOT_DIR/scripts/operations/lib/package_revision_workflow.py" \
        "$action" "$client_key" "$package_name" "${options[@]}"
}

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
        revision_operation "$ACTION" "$@"
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
#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
BUNDLE_ROOT=$(cd -- "$SCRIPT_DIR/../.." && pwd)
HELPER="$SCRIPT_DIR/lib/launch_client_environment.py"

usage() {
    cat <<'USAGE'
Usage:
  launch-client-environment.sh new [options]
  launch-client-environment.sh resume <state-file>
  launch-client-environment.sh update <state-file>
  launch-client-environment.sh add-modules <state-file> --module MODULE [--module MODULE ...]
  launch-client-environment.sh verify <state-file>
  launch-client-environment.sh audit --environment ENV --client-key KEY --root-domain DOMAIN [options]
  launch-client-environment.sh fix --environment ENV --client-key KEY --root-domain DOMAIN [options]
  launch-client-environment.sh derive --environment ENV --client-repo URL --root-domain DOMAIN [--client-key KEY]
  launch-client-environment.sh managed-check --instance-id ID
  launch-client-environment.sh managed-source-check --instance-id ID --release-id RELEASE \
    --client-key KEY --core-repository OWNER/REPO --core-commit SHA \
    --client-repository OWNER/REPO --client-commit SHA

Managed-check inspects an Ansible-created app allocation and PHP-FPM pool read-only.
It does not provision source, certificates, credentials, databases, Nginx or services.
Managed-source-check verifies an already-prepared Core release and selected client
against approved repository identities and exact commits. It never fetches or writes.
The former host-mutating state-file workflows are disabled pending replacement.

Audit/fix identity options:
  --environment staging|production
  --client-key KEY
  --root-domain DOMAIN
  --app-path PATH                  Optional existing checkout override; otherwise discovered.
  --client-repo URL                Optional expected client-repository origin.
  --crm-host HOST                  Optional expected CRM host; otherwise discovered from CRM_APP_URL.
  --core-branch BRANCH             Default: main
  --client-branch BRANCH           Default: main
  --deploy-user USER               Default: current user
  --web-user USER                  Default: www-data
  --web-group GROUP                Default: www-data
  --scheduler-user USER            Default: deploy user
  --server-ip IPV4                 Optional authoritative direct-DNS target for Core hosts.

Audit is strictly non-mutating. Secret-file access is checked regardless of Git
provenance. Clean/current Git is required for authoritative application-runtime
interpretation. Audit does not create a
state file, write environment files, change permissions, migrate/install schema,
alter Nginx/Supervisor/cron, issue certificates, reload services, or modify
provider/DNS configuration.

Audit classifications are PASS, INFO, WARNING, BREAKING, and MANUAL VERIFICATION REQUIRED.
Only BREAKING findings may enter the closed automatic fix registry.

Fix options:
  --interactive                     Guided reconciliation (default): review non-breaking drift,
                                    collect missing/mismatched environment values, and confirm
                                    each eligible deterministic repair before mutation.
  --dry-run                         Print the reconciliation/repair plan without prompting or changing state.
  --apply                           Non-interactively apply eligible deterministic safe repairs only.
                                    Provider secrets/credentials are never invented or collected in this mode.
  --only CATEGORY[,CATEGORY...]     Limit to environment,schema,runtime,nginx,horizon,scheduler.
                                    Aliases: env/providers=environment, supervisor=horizon,
                                    cron=scheduler, tls=nginx.
  --all-safe                        Select only the deterministic safe registry:
                                    schema,runtime,nginx,horizon,scheduler.
  plus all Audit/fix identity options above.

Plain fix is the operator-guided reconciliation workflow. It may write values explicitly
supplied or approved by the operator, but it never invents credentials, changes DNS,
normalizes healthy legacy names/paths/prefixes without review, rewrites CRM data, or
performs destructive schema operations. Automatic repair remains BREAKING-only.
Production mutation requires an explicit root-domain confirmation.

New-environment options:
  --environment staging|production
  --client-repo URL
  --root-domain DOMAIN
  --client-key KEY                 Optional override; normally derived from repo basename.
  --topology core_services_only|managed_main_site
  --main-site-type seo|artist|other
  --crm-host HOST                  Optional override; defaults to crm.<root-domain>.
  --core-branch BRANCH             Default: main
  --client-branch BRANCH           Default: main

The state file contains non-secret deployment identity/checkpoints only. Secrets are
entered with hidden prompts and written directly to the target root/client .env files.
USAGE
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

note() {
    printf '\n== %s ==\n' "$*"
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "Required command not found: $1"
}

prompt_default() {
    local prompt="$1"
    local default="$2"
    local answer
    read -r -p "$prompt [$default]: " answer
    printf '%s' "${answer:-$default}"
}

prompt_required() {
    local prompt="$1"
    local answer=""
    while [[ -z "$answer" ]]; do
        read -r -p "$prompt: " answer
        answer="${answer//[$'\r\n']/}"
    done
    printf '%s' "$answer"
}

prompt_secret() {
    local prompt="$1"
    local answer=""
    while [[ -z "$answer" ]]; do
        read -r -s -p "$prompt: " answer
        printf '\n' >&2
    done
    printf '%s' "$answer"
}

prompt_yes_no() {
    local prompt="$1"
    local default="${2:-yes}"
    local suffix='[Y/n]'
    [[ "$default" == "no" ]] && suffix='[y/N]'
    local answer
    read -r -p "$prompt $suffix: " answer
    answer="${answer,,}"
    if [[ -z "$answer" ]]; then
        [[ "$default" == "yes" ]]
        return
    fi
    [[ "$answer" == "y" || "$answer" == "yes" ]]
}

state_get() {
    python3 "$HELPER" state-get --file "$STATE_FILE" --key "$1"
}

state_set() {
    python3 "$HELPER" state-set --file "$STATE_FILE" --key "$1" --value "$2"
}

state_set_json() {
    python3 "$HELPER" state-set --file "$STATE_FILE" --key "$1" --value "$2" --json-value
}

phase_done() {
    python3 "$HELPER" phase-done --file "$STATE_FILE" --phase "$1" >/dev/null 2>&1
}

mark_phase() {
    python3 "$HELPER" mark-phase --file "$STATE_FILE" --phase "$1"
}

env_get() {
    python3 "$HELPER" env-get --file "$1" --key "$2" 2>/dev/null || true
}

env_set() {
    # Never expose provider credentials or passwords in a child process argv.
    printf '%s' "$3" | python3 "$HELPER" env-set --file "$1" --key "$2" --stdin-value
}

env_remove() {
    python3 "$HELPER" env-remove --file "$1" --key "$2"
}

load_state() {
    [[ -f "$STATE_FILE" ]] || fail "State file not found: $STATE_FILE"

    DEPLOY_ENV="$(state_get environment)"
    CLIENT_REPO_URL="$(state_get client_repo_url)"
    CLIENT_KEY="$(state_get client_key)"
    ROOT_DOMAIN="$(state_get root_domain)"
    APP_PATH="$(state_get app_path)"
    CLIENT_PATH="$(state_get client_path)"
    CRM_HOST="$(state_get crm_host)"
    WEBHOOKS_HOST="$(state_get webhooks_host)"
    WEBINAR_HOST="$(state_get webinar_host)"
    MESSAGING_HOST="$(state_get messaging_host)"
    SCHEDULING_DEFAULT_HOST="$(state_get scheduling_default_host)"
    DB_DATABASE_DERIVED="$(state_get database_name)"
    DB_USERNAME_DERIVED="$(state_get database_user)"
    CACHE_PREFIX="$(state_get cache_prefix)"
    REDIS_PREFIX="$(state_get redis_prefix)"
    HORIZON_PREFIX="$(state_get horizon_prefix)"
    HORIZON_PROGRAM="$(state_get horizon_program)"
    NGINX_SITE_NAME="$(state_get nginx_site_name)"
    SCHEDULER_MARKER="$(state_get scheduler_marker)"
    TOPOLOGY="$(state_get topology)"
    MAIN_SITE_TYPE="$(state_get main_site_type 2>/dev/null || true)"
    CORE_BRANCH="$(state_get core_branch 2>/dev/null || printf 'main')"
    CLIENT_BRANCH="$(state_get client_branch 2>/dev/null || printf 'main')"
    DEPLOY_USER="$(state_get deploy_user 2>/dev/null || id -un)"
    WEB_USER="$(state_get web_user 2>/dev/null || printf 'www-data')"
    WEB_GROUP="$(state_get web_group 2>/dev/null || printf 'www-data')"
    SCHEDULER_USER="$(state_get scheduler_user 2>/dev/null || printf '%s' "$DEPLOY_USER")"
    PHP_BIN="$(state_get php_bin 2>/dev/null || command -v php)"
    PHP_VERSION="$(state_get php_version 2>/dev/null || "$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_FPM_SERVICE="$(state_get php_fpm_service 2>/dev/null || printf 'php%s-fpm' "$PHP_VERSION")"
    PHP_FPM_SOCKET="$(state_get php_fpm_socket 2>/dev/null || printf '/run/php/php%s-fpm.sock' "$PHP_VERSION")"
    ROOT_ENV="$APP_PATH/.env"
    CLIENT_ENV="$CLIENT_PATH/.env"
    PLAN_FILE="${STATE_FILE%.json}.deployment-plan.json"
}

identity_preflight() {
    require_command python3
    require_command git
    require_command sudo
    require_command id
    [[ -x "$HELPER" || -f "$HELPER" ]] || fail "Launch helper not found: $HELPER"
    id "$DEPLOY_USER" >/dev/null 2>&1 || fail "Deploy user does not exist: $DEPLOY_USER"
    id "$WEB_USER" >/dev/null 2>&1 || fail "Web/PHP-FPM user does not exist: $WEB_USER"
    getent group "$WEB_GROUP" >/dev/null 2>&1 || fail "Web group does not exist: $WEB_GROUP"
    [[ "$(id -un)" == "$DEPLOY_USER" ]] || fail "Run the launcher as deploy user [$DEPLOY_USER], not [$(id -un)]."
}

parse_new() {
    local environment=""
    local client_repo=""
    local root_domain=""
    local client_key=""
    local topology=""
    local main_site_type=""
    local crm_host=""
    local core_branch="main"
    local client_branch="main"

    while (($#)); do
        case "$1" in
            --environment) environment=${2:?}; shift 2 ;;
            --client-repo) client_repo=${2:?}; shift 2 ;;
            --root-domain) root_domain=${2:?}; shift 2 ;;
            --client-key) client_key=${2:?}; shift 2 ;;
            --topology) topology=${2:?}; shift 2 ;;
            --main-site-type) main_site_type=${2:?}; shift 2 ;;
            --crm-host) crm_host=${2:?}; shift 2 ;;
            --core-branch) core_branch=${2:?}; shift 2 ;;
            --client-branch) client_branch=${2:?}; shift 2 ;;
            -h|--help) usage; exit 0 ;;
            *) fail "Unknown new-environment argument: $1" ;;
        esac
    done

    [[ -n "$environment" ]] || environment="$(prompt_default "Environment (staging/production)" "staging")"
    [[ "$environment" == "staging" || "$environment" == "production" ]] || fail "Environment must be staging or production."
    [[ -n "$client_repo" ]] || client_repo="$(prompt_required "Client repository URL")"
    [[ -n "$root_domain" ]] || root_domain="$(prompt_required "Root domain for this environment")"

    if [[ -z "$topology" ]]; then
        echo "Root-site ownership:"
        echo "  1. Core/public services only; the main website is external"
        echo "  2. We also manage the main website (SEO/Artist/other)"
        local choice
        choice="$(prompt_default "Choose topology" "1")"
        [[ "$choice" == "2" ]] && topology="managed_main_site" || topology="core_services_only"
    fi
    [[ "$topology" == "core_services_only" || "$topology" == "managed_main_site" ]] \
        || fail "Invalid topology [$topology]."

    if [[ "$topology" == "managed_main_site" && -z "$main_site_type" ]]; then
        main_site_type="$(prompt_default "Managed main-site type (seo/artist/other)" "other")"
    fi
    if [[ "$topology" == "core_services_only" ]]; then
        main_site_type="none"
    fi

    require_command python3
    local -a derive_args=(derive --environment "$environment" --client-repo "$client_repo" --root-domain "$root_domain")
    [[ -n "$client_key" ]] && derive_args+=(--client-key "$client_key")
    local identity_json
    identity_json="$(python3 "$HELPER" "${derive_args[@]}")"
    STATE_FILE="$(printf '%s' "$identity_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["state_file"])')"
    python3 "$HELPER" state-init --file "$STATE_FILE" --identity-json "$identity_json"
    state_set topology "$topology"
    state_set main_site_type "$main_site_type"
    state_set core_branch "$core_branch"
    state_set client_branch "$client_branch"
    state_set deploy_user "$(id -un)"
    state_set web_user www-data
    state_set web_group www-data
    state_set scheduler_user "$(id -un)"

    local php_bin php_version
    php_bin="$(command -v php || true)"
    [[ -n "$php_bin" ]] || fail "PHP is not installed or not on PATH."
    php_version="$($php_bin -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    state_set php_bin "$php_bin"
    state_set php_version "$php_version"
    state_set php_fpm_service "php${php_version}-fpm"
    state_set php_fpm_socket "/run/php/php${php_version}-fpm.sock"

    load_state
    if [[ -n "$crm_host" ]]; then
        CRM_HOST="$(python3 "$HELPER" origin-host --origin "https://$crm_host")"
        state_set crm_host "$CRM_HOST"
    fi

    note "Derived deployment identity"
    echo "Client key:       $CLIENT_KEY"
    echo "App path:         $APP_PATH"
    echo "Runtime stem:     $(state_get runtime_prefix_stem)"
    echo "Cache prefix:     $CACHE_PREFIX"
    echo "Redis prefix:     $REDIS_PREFIX"
    echo "Horizon prefix:   $HORIZON_PREFIX"
    echo "Horizon program:  $HORIZON_PROGRAM"
    echo "CRM host:         $CRM_HOST"
    echo "State file:       $STATE_FILE"
    echo
    prompt_yes_no "Continue with this deployment identity?" yes || exit 0

    run_new_or_resume
}

parse_derive() {
    python3 "$HELPER" derive "$@"
}

main() {
    [[ $# -gt 0 ]] || { usage; exit 2; }
    local command="$1"
    shift

    # The extracted pre-Ansible workflows still call sudo for local MySQL,
    # Nginx/Certbot, Supervisor and cron. They are intentionally unreachable
    # until replaced with the Ansible-managed application lifecycle.
    case "$command" in
        new|resume|update|add-modules|verify|fix)
            fail "Legacy host-mutating deployment workflow [$command] is disabled. Use Ansible for host provisioning; the managed application lifecycle is not yet implemented. No changes made."
            ;;
    esac

    case "$command" in
        new)
            parse_new "$@"
            ;;
        resume)
            [[ $# -eq 1 ]] || fail "resume requires exactly one state file."
            STATE_FILE="$1"
            load_state
            run_new_or_resume
            ;;
        update)
            [[ $# -eq 1 ]] || fail "update requires exactly one state file."
            STATE_FILE="$1"
            load_state
            run_update
            ;;
        add-modules)
            [[ $# -ge 3 ]] || fail "add-modules requires a state file and at least one --module MODULE."
            STATE_FILE="$1"
            shift
            REQUESTED_MODULES=()
            while (($#)); do
                case "$1" in
                    --module) REQUESTED_MODULES+=("${2:?}"); shift 2 ;;
                    *) fail "Unknown add-modules argument: $1" ;;
                esac
            done
            load_state
            run_add_modules
            ;;
        verify)
            [[ $# -eq 1 ]] || fail "verify requires exactly one state file."
            STATE_FILE="$1"
            load_state
            verify_runtime
            ;;
        audit)
            parse_audit "$@"
            ;;
        fix)
            parse_fix "$@"
            ;;
        managed-check)
            [[ $# -eq 2 && "$1" == "--instance-id" ]] \
                || fail "managed-check requires exactly --instance-id ID."
            python3 "$SCRIPT_DIR/lib/launch_managed_instance.py" --instance-id "$2"
            ;;
        managed-source-check)
            python3 "$SCRIPT_DIR/lib/launch_managed_source.py" "$@"
            ;;
        derive)
            parse_derive "$@"
            ;;
        -h|--help|help)
            usage
            ;;
        *)
            fail "Unknown command: $command"
            ;;
    esac
}

# Load phase implementations without executing them. The entry point retains
# command parsing; stage ownership stays in the focused phase libraries.
for phase_library in launch_source.sh launch_host_web.sh launch_environment.sh launch_runtime.sh launch_verify.sh launch_application.sh launch_workflow.sh launch_audit.sh launch_host_repair.sh launch_fix.sh; do
    phase_file="$SCRIPT_DIR/lib/$phase_library"
    [[ -f "$phase_file" && ! -L "$phase_file" ]] \
        || fail "Missing or symlinked deployment phase library: $phase_file"
    # shellcheck source=/dev/null
    source "$phase_file"
done

REQUESTED_MODULES=()
main "$@"
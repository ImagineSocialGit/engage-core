#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/operations/launch-client-environment.sh <phase> <config-file>

Phases:
  prepare
      Verify/pull clean Core + client repositories, install dependencies/build
      assets, create env files when missing, write non-secret deployment values,
      set safe env-file permissions, and validate selected-client env ownership.

  install
      Generate APP_KEY only when absent, clear cached state, verify resolved
      client/database identity, run engage:install --force --no-create-user,
      then run modules:status and setup:validate.

  runtime
      Configure runtime-directory permissions, perform deploy/web cross-user
      write checks, install/update the Supervisor Horizon program, install the
      exact Scheduler cron entry, reload PHP-FPM, and verify both processes.

  verify
      Run read-only/current-state readiness checks plus optional HTTP smoke tests.

Important:
  - The config file must contain NON-SECRET values only.
  - Populate DB passwords, provider credentials, Forms signing secrets, staging
    access passwords, and other secrets manually in the deployed env files.
  - The script does not create databases/users, DNS records, Nginx sites,
    certificates, provider accounts, or CRM users.
  - Application/client source changes belong in development. This script only
    pulls approved commits on staging/production.
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

require_var() {
    local name="$1"
    [[ -n "${!name:-}" ]] || fail "Required config variable is empty: $name"
}

require_user() {
    id "$1" >/dev/null 2>&1 || fail "Required operating-system user does not exist: $1"
}

require_group() {
    getent group "$1" >/dev/null 2>&1 || fail "Required operating-system group does not exist: $1"
}

require_user_in_group() {
    local user="$1"
    local group="$2"
    id -nG "$user" | tr ' ' '\n' | grep -Fxq "$group" \
        || fail "User [$user] is not a member of required group [$group]."
}

identity_preflight() {
    require_command id
    require_command getent
    require_command grep
    require_command tr

    require_user "$DEPLOY_USER"
    require_user "$WEB_USER"
    require_user "$SCHEDULER_USER"
    require_group "$WEB_GROUP"
    require_user_in_group "$WEB_USER" "$WEB_GROUP"
    if [[ "$SCHEDULER_USER" != "$DEPLOY_USER" ]]; then
        require_user_in_group "$SCHEDULER_USER" "$WEB_GROUP"
    fi

    if [[ "$(id -un)" != "$DEPLOY_USER" ]]; then
        fail "Run this deployment helper as DEPLOY_USER [$DEPLOY_USER], not [$(id -un)]."
    fi
}

bool_value() {
    case "${1,,}" in
        1|true|yes|on) printf 'true' ;;
        0|false|no|off|'') printf 'false' ;;
        *) fail "Invalid boolean value: $1" ;;
    esac
}

set_env_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    local tmp
    tmp="$(mktemp)"

    awk -v key="$key" -v value="$value" '
        BEGIN { found = 0 }
        $0 ~ "^" key "=" {
            print key "=" value
            found = 1
            next
        }
        { print }
        END {
            if (! found) {
                print key "=" value
            }
        }
    ' "$file" > "$tmp"

    cat "$tmp" > "$file"
    rm -f "$tmp"
}

remove_env_value() {
    local file="$1"
    local key="$2"
    local tmp
    tmp="$(mktemp)"
    awk -v key="$key" '$0 !~ "^" key "=" { print }' "$file" > "$tmp"
    cat "$tmp" > "$file"
    rm -f "$tmp"
}

env_value() {
    local file="$1"
    local key="$2"
    awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print; exit }' "$file"
}

repo_must_be_clean() {
    local dir="$1"
    local label="$2"
    [[ -d "$dir/.git" ]] || fail "$label is not a Git repository: $dir"
    if [[ -n "$(git -C "$dir" status --porcelain)" ]]; then
        git -C "$dir" status --short >&2
        fail "$label checkout is dirty. Staging/production source must come from committed development changes."
    fi
}

repo_pull_ff() {
    local dir="$1"
    local label="$2"
    local branch="$3"

    repo_must_be_clean "$dir" "$label"

    local current
    current="$(git -C "$dir" branch --show-current)"
    [[ "$current" == "$branch" ]] || fail "$label is on branch [$current], expected [$branch]."

    git -C "$dir" fetch origin
    git -C "$dir" pull --ff-only origin "$branch"

    repo_must_be_clean "$dir" "$label"
    printf '%s revision: %s\n' "$label" "$(git -C "$dir" log -1 --oneline --decorate)"
}

ensure_env_permissions() {
    local root_env="$APP_PATH/.env"
    local client_env="$APP_PATH/client/$CLIENT_KEY/.env"

    sudo chown "$DEPLOY_USER:$WEB_GROUP" "$root_env" "$client_env"
    sudo chmod 640 "$root_env" "$client_env"

    sudo -u "$DEPLOY_USER" test -r "$root_env" || fail "$DEPLOY_USER cannot read root .env"
    sudo -u "$DEPLOY_USER" test -r "$client_env" || fail "$DEPLOY_USER cannot read client .env"
    sudo -u "$WEB_USER" test -r "$root_env" || fail "$WEB_USER cannot read root .env"
    sudo -u "$WEB_USER" test -r "$client_env" || fail "$WEB_USER cannot read client .env"
}

validate_client_env_contract() {
    note "Client environment ownership contract"
    (
        cd "$APP_PATH"
        "$PHP_BIN" -r '
require __DIR__."/vendor/autoload.php";
Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();

try {
    (new App\Support\Clients\ClientEnvironmentLoader())->load(__DIR__);
    echo "CLIENT ENV OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).": ".$e->getMessage().PHP_EOL);
    exit(1);
}
'
    ) || fail "Selected-client .env violates ClientEnvironmentLoader. Fix the owning source/example in development and redeploy; do not broaden the loader to accept stale keys."
}

runtime_permission_setup() {
    note "Runtime directory permissions"
    cd "$APP_PATH"

    sudo chown -R "$DEPLOY_USER:$WEB_GROUP" storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
    sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

    local one="storage/logs/.engage-permission-deploy-$$"
    local two="storage/logs/.engage-permission-web-$$"

    sudo -u "$DEPLOY_USER" sh -c "printf 'deploy-created\\n' > '$one'"
    sudo -u "$WEB_USER" sh -c "printf 'web-updated\\n' >> '$one'"

    sudo -u "$WEB_USER" sh -c "printf 'web-created\\n' > '$two'"
    sudo -u "$DEPLOY_USER" sh -c "printf 'deploy-updated\\n' >> '$two'"

    sudo rm -f "$one" "$two"
    echo "Cross-user runtime write check passed."
}

install_supervisor_program() {
    note "Supervisor Horizon program"

    local conf="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local tmp
    tmp="$(mktemp)"

    cat > "$tmp" <<EOF_SUPERVISOR
[program:${HORIZON_PROGRAM}]
process_name=%(program_name)s
command=${PHP_BIN} ${APP_PATH}/artisan horizon
directory=${APP_PATH}
autostart=true
autorestart=true
user=${WEB_USER}
redirect_stderr=true
stdout_logfile=${APP_PATH}/storage/logs/horizon.log
stopwaitsecs=3600
EOF_SUPERVISOR

    sudo install -o root -g root -m 0644 "$tmp" "$conf"
    rm -f "$tmp"

    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart "$HORIZON_PROGRAM" >/dev/null 2>&1 || true
    sleep 1
    sudo supervisorctl status "$HORIZON_PROGRAM"

    ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" || fail "Horizon process for $APP_PATH was not found."
}

install_scheduler_cron() {
    note "Laravel Scheduler cron"

    local line="* * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
    local tmp
    tmp="$(mktemp)"

    sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null > "$tmp" || true

    if ! grep -Fqx "$line" "$tmp"; then
        printf '%s\n' "$line" >> "$tmp"
        sudo crontab -u "$SCHEDULER_USER" "$tmp"
    fi

    rm -f "$tmp"
    sudo crontab -u "$SCHEDULER_USER" -l | grep -F "$line"

    cd "$APP_PATH"
    "$PHP_BIN" artisan schedule:list
}

http_status() {
    local url="$1"
    curl -sS -o /dev/null -w '%{http_code}' "$url"
}

phase_prepare() {
    note "Host/repository preflight"

    identity_preflight
    require_command git
    require_command composer
    require_command npm
    require_command awk
    require_command sudo
    [[ -x "$PHP_BIN" ]] || fail "PHP binary is not executable: $PHP_BIN"

    [[ -f "$APP_PATH/artisan" ]] || fail "Engage Core artisan not found at APP_PATH: $APP_PATH"
    [[ -f "$APP_PATH/.env.example" ]] || fail "Root .env.example not found."

    repo_pull_ff "$APP_PATH" "Core" "$CORE_BRANCH"

    local client_dir="$APP_PATH/client/$CLIENT_KEY"
    if [[ ! -d "$client_dir/.git" ]]; then
        [[ -n "${CLIENT_REPO_URL:-}" ]] || fail "Client repo is missing and CLIENT_REPO_URL is empty."
        mkdir -p "$APP_PATH/client"
        git clone --branch "$CLIENT_BRANCH" "$CLIENT_REPO_URL" "$client_dir"
    fi
    repo_pull_ff "$client_dir" "Client" "$CLIENT_BRANCH"

    note "Host capacity"
    free -h || true
    swapon --show || true
    df -h "$APP_PATH" || true

    note "Dependencies and frontend build"
    cd "$APP_PATH"
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build

    note "Environment scaffolding"
    if [[ ! -f .env ]]; then
        cp .env.example .env
    fi
    if [[ ! -f "client/$CLIENT_KEY/.env" ]]; then
        cp "client/$CLIENT_KEY/.env.example" "client/$CLIENT_KEY/.env"
    fi

    # Root/process-owned non-secret shape.
    set_env_value .env APP_ENV "$DEPLOY_ENV"
    set_env_value .env APP_DEBUG false
    set_env_value .env CLIENT_KEY "$CLIENT_KEY"
    set_env_value .env DB_CONNECTION "$DB_CONNECTION"
    set_env_value .env DB_HOST "$DB_HOST"
    set_env_value .env DB_PORT "$DB_PORT"
    set_env_value .env CACHE_STORE redis
    set_env_value .env SESSION_DRIVER redis
    set_env_value .env SESSION_SECURE_COOKIE true
    set_env_value .env QUEUE_CONNECTION redis
    set_env_value .env REDIS_HOST "$REDIS_HOST"
    set_env_value .env REDIS_PORT "$REDIS_PORT"
    set_env_value .env REDIS_DB "$REDIS_DB"
    set_env_value .env REDIS_CACHE_DB "$REDIS_CACHE_DB"
    set_env_value .env FILESYSTEM_DISK "$FILESYSTEM_DISK"
    set_env_value .env LOG_CHANNEL "$LOG_CHANNEL"
    set_env_value .env LOG_STACK "$LOG_STACK"
    set_env_value .env LOG_LEVEL "$LOG_LEVEL"
    set_env_value .env LOG_DAILY_DAYS "$LOG_DAILY_DAYS"
    set_env_value .env RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS 300
    set_env_value .env HORIZON_SUPERVISOR_1_QUEUES "$HORIZON_PRIMARY_QUEUES"

    # APP_URL belongs to selected-client env. Remove a stale root assignment so
    # the client loader remains authoritative.
    remove_env_value .env APP_URL

    local client_env="client/$CLIENT_KEY/.env"
    set_env_value "$client_env" ROOT_DOMAIN "$ROOT_DOMAIN"
    set_env_value "$client_env" APP_URL "https://$ROOT_DOMAIN"
    set_env_value "$client_env" CRM_APP_URL "https://$CORE_ADMIN_HOST"
    set_env_value "$client_env" DB_DATABASE "$DB_DATABASE"
    set_env_value "$client_env" DB_USERNAME "$DB_USERNAME"
    set_env_value "$client_env" CACHE_PREFIX "${RUNTIME_PREFIX}_cache_"
    set_env_value "$client_env" REDIS_PREFIX "${RUNTIME_PREFIX}_"
    set_env_value "$client_env" HORIZON_PREFIX "${RUNTIME_PREFIX}_horizon:"

    if [[ -n "${WEBINAR_APP_URL:-}" ]]; then
        set_env_value "$client_env" WEBINAR_APP_URL "$WEBINAR_APP_URL"
    fi
    if [[ -n "${SCHEDULING_APP_URL:-}" ]]; then
        set_env_value "$client_env" SCHEDULING_APP_URL "$SCHEDULING_APP_URL"
    fi

    if [[ "$(bool_value "${FORMS_EXTERNAL_INTAKE_ENABLED:-false}")" == "true" ]]; then
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_ENABLED true
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_CLIENT_ID "$FORMS_EXTERNAL_INTAKE_CLIENT_ID"
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_SOURCE "$FORMS_EXTERNAL_INTAKE_SOURCE"
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_PROVIDER "$FORMS_EXTERNAL_INTAKE_PROVIDER"
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS "$FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS"
        set_env_value "$client_env" FORMS_EXTERNAL_INTAKE_DOMAINS "$FORMS_EXTERNAL_INTAKE_DOMAINS"
    fi

    ensure_env_permissions
    validate_client_env_contract

    note "Manual secret/config gate"
    cat <<EOF_GATE
Prepare phase completed.

Before running install, populate the required secrets directly in:
  $APP_PATH/.env
  $APP_PATH/client/$CLIENT_KEY/.env

Typical manual values include:
  DB_PASSWORD
  STAGING_USER / STAGING_PASSWORD when the staging access gate is used
  DigitalOcean Spaces credentials
  Resend/Telnyx/Zoom credentials for enabled providers
  FORMS_EXTERNAL_INTAKE_CLIENT_SECRET when external Forms intake is enabled
  PROJECT_STATE_ADMIN_EMAIL only when deliberately authorized

Do not put those secrets in the launch config file.
EOF_GATE
}

phase_install() {
    note "Install preflight"
    identity_preflight
    cd "$APP_PATH"

    [[ -f .env && -f "client/$CLIENT_KEY/.env" ]] || fail "Run prepare first."
    ensure_env_permissions
    validate_client_env_contract

    local current_key
    current_key="$(env_value .env APP_KEY || true)"
    if [[ -z "$current_key" ]]; then
        note "Generate APP_KEY"
        "$PHP_BIN" artisan key:generate --force
    fi

    "$PHP_BIN" artisan optimize:clear

    note "Resolved client identity"
    "$PHP_BIN" artisan tinker --execute="dump([
        'environment' => app()->environment(),
        'client_key' => config('client.key'),
        'client_preset' => config('client.preset'),
        'client_timezone' => config('client.timezone'),
        'enabled_modules' => config('modules.enabled'),
    ]);"

    note "Database connectivity"
    "$PHP_BIN" artisan tinker --execute="dump([
        'database' => DB::connection()->getDatabaseName(),
        'connected' => DB::connection()->getPdo() !== null,
    ]);"

    note "Fresh environment installation"
    if ! "$PHP_BIN" artisan engage:install --force --no-create-user; then
        cat >&2 <<'EOF_INSTALL_FAIL'

engage:install did not complete its final readiness gate.
If the only failure is an intentionally blank/invalid external Forms signing
secret, run:

  php artisan forms:external-intake:issue-secret [client]

Install the matching Core/caller secrets manually, run optimize:clear, then rerun
this install phase. The completed migration/preset stages are idempotent.
EOF_INSTALL_FAIL
        exit 1
    fi

    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
}

phase_runtime() {
    identity_preflight
    require_command sudo
    require_command ps
    require_command grep
    require_command supervisorctl
    require_command systemctl
    require_command crontab

    ensure_env_permissions
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron

    note "Reload PHP-FPM"
    sudo systemctl reload "$PHP_FPM_SERVICE"

    note "Horizon status"
    cd "$APP_PATH"
    "$PHP_BIN" artisan horizon:status
}

phase_verify() {
    identity_preflight
    require_command sudo
    require_command supervisorctl
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract

    note "Module/setup validation"
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate

    note "Horizon/Scheduler"
    sudo supervisorctl status "$HORIZON_PROGRAM"
    "$PHP_BIN" artisan horizon:status
    "$PHP_BIN" artisan schedule:list

    note "CRM route host"
    if ! "$PHP_BIN" artisan route:list | grep -F "$CORE_ADMIN_HOST"; then
        fail "CRM route list does not contain configured CORE_ADMIN_HOST [$CORE_ADMIN_HOST]."
    fi

    if [[ "$(bool_value "${HTTP_SMOKE:-false}")" == "true" ]]; then
        require_command curl
        note "HTTP smoke"

        local admin_status
        admin_status="$(http_status "https://$CORE_ADMIN_HOST/")"
        case "$admin_status" in
            2??|3??|401|403) echo "CRM root HTTP $admin_status" ;;
            *) fail "CRM root returned HTTP $admin_status" ;;
        esac

        local login_status
        login_status="$(http_status "https://$CORE_ADMIN_HOST/login")"
        case "$login_status" in
            2??|3??|401|403) echo "CRM login HTTP $login_status" ;;
            *) fail "CRM login returned HTTP $login_status" ;;
        esac

        if [[ "$(bool_value "${FORMS_EXTERNAL_INTAKE_ENABLED:-false}")" == "true" && -n "${FORMS_SMOKE_FORM_KEY:-}" ]]; then
            local forms_status
            forms_status="$(curl -sS -o /tmp/engage-core-forms-smoke-$$.json -w '%{http_code}' -H 'Accept: application/json' "https://webhooks.$ROOT_DOMAIN/forms/$FORMS_SMOKE_FORM_KEY")"
            rm -f /tmp/engage-core-forms-smoke-$$.json
            [[ "$forms_status" == "401" ]] || fail "Unsigned external Forms smoke expected HTTP 401, got $forms_status."
            echo "External Forms unsigned route smoke HTTP 401 (expected)."
        fi
    else
        echo "HTTP_SMOKE is disabled; DNS/Nginx/TLS checks remain manual."
    fi

    cat <<'EOF_DONE'

Verification phase completed.
Manual gates still outside this script include:
  - DNS/Nginx/TLS configuration and certificate SAN review
  - provider account/webhook verification
  - CRM user creation (`php artisan engage:user:add`) when needed
  - external caller probes (for example Artist Sites site:probe-core-form)
  - controlled real browser/provider smoke tests
EOF_DONE
}

main() {
    if [[ $# -eq 1 && "$1" =~ ^(-h|--help|help)$ ]]; then
        usage
        exit 0
    fi

    [[ $# -eq 2 ]] || { usage; exit 2; }

    local phase="$1"
    local config_file="$2"
    [[ -f "$config_file" ]] || fail "Config file not found: $config_file"

    # shellcheck disable=SC1090
    source "$config_file"

    for name in \
        DEPLOY_ENV CLIENT_KEY ROOT_DOMAIN CORE_ADMIN_HOST APP_PATH \
        DEPLOY_USER WEB_USER WEB_GROUP SCHEDULER_USER PHP_BIN PHP_FPM_SERVICE \
        CORE_BRANCH CLIENT_BRANCH DB_CONNECTION DB_HOST DB_PORT DB_DATABASE \
        DB_USERNAME REDIS_HOST REDIS_PORT REDIS_DB REDIS_CACHE_DB \
        RUNTIME_PREFIX HORIZON_PROGRAM HORIZON_PRIMARY_QUEUES \
        FILESYSTEM_DISK LOG_CHANNEL LOG_STACK LOG_LEVEL LOG_DAILY_DAYS
    do
        require_var "$name"
    done

    case "$DEPLOY_ENV" in
        staging|production) ;;
        *) fail "DEPLOY_ENV must be staging or production." ;;
    esac

    case "$phase" in
        prepare) phase_prepare ;;
        install) phase_install ;;
        runtime) phase_runtime ;;
        verify) phase_verify ;;
        -h|--help|help) usage ;;
        *) fail "Unknown phase: $phase" ;;
    esac
}

main "$@"
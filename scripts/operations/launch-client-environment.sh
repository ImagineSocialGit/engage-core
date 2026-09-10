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

Audit is strictly non-mutating. It verifies that both Git checkouts match their
configured remote branch before interpreting runtime state. It does not create a
state file, write environment files, change permissions, migrate/install schema,
alter Nginx/Supervisor/cron, issue certificates, reload services, or modify
provider/DNS configuration.

Audit classifications are PASS, INFO, WARNING, BREAKING, and MANUAL VERIFICATION REQUIRED.
Only BREAKING findings may enter the closed automatic fix registry.

Fix options:
  --apply                           Apply safe registered repairs. Default is dry-run.
  --dry-run                         Print the exact safe repair plan without changing state.
  --only CATEGORY[,CATEGORY...]     Limit to schema,runtime,nginx,horizon,scheduler.
                                    Aliases: supervisor=horizon, cron=scheduler, tls=nginx.
  --all-safe                        Select every registered safe repair category (default).
  plus all Audit/fix identity options above.

Fix never pulls source, invents credentials, changes DNS, normalizes healthy legacy
names/paths/prefixes, rewrites CRM data, or performs destructive schema operations.
Production --apply requires an explicit confirmation.

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
    python3 "$HELPER" env-set --file "$1" --key "$2" --value "$3"
}

env_remove() {
    python3 "$HELPER" env-remove --file "$1" --key "$2"
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

ensure_env_permissions() {
    [[ -f "$ROOT_ENV" && -f "$CLIENT_ENV" ]] || fail "Runtime environment files are missing."

    local deploy_group
    deploy_group="$(id -gn "$DEPLOY_USER")"

    sudo chown "$DEPLOY_USER:$deploy_group" "$ROOT_ENV" "$CLIENT_ENV"
    sudo chmod 664 "$ROOT_ENV" "$CLIENT_ENV"

    sudo -u "$DEPLOY_USER" test -r "$ROOT_ENV" || fail "$DEPLOY_USER cannot read $ROOT_ENV"
    sudo -u "$WEB_USER" test -r "$ROOT_ENV" || fail "$WEB_USER cannot read $ROOT_ENV"
    sudo -u "$DEPLOY_USER" test -r "$CLIENT_ENV" || fail "$DEPLOY_USER cannot read $CLIENT_ENV"
    sudo -u "$WEB_USER" test -r "$CLIENT_ENV" || fail "$WEB_USER cannot read $CLIENT_ENV"
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
    ) || fail "Selected-client .env violates ClientEnvironmentLoader."
}

ensure_source() {
    local force="${1:-false}"
    if [[ "$force" != "true" ]] && phase_done source; then
        return
    fi

    note "Repositories"
    identity_preflight
    require_command composer
    require_command npm

    local core_repo_url
    core_repo_url="$(git -C "$BUNDLE_ROOT" remote get-url origin 2>/dev/null || true)"
    [[ -n "$core_repo_url" ]] || fail "Unable to discover Core origin URL from launcher checkout."

    if [[ ! -d "$APP_PATH/.git" ]]; then
        sudo mkdir -p "$(dirname "$APP_PATH")"
        sudo chown "$DEPLOY_USER:$WEB_GROUP" "$(dirname "$APP_PATH")"
        git clone --branch "$CORE_BRANCH" "$core_repo_url" "$APP_PATH"
    fi
    repo_pull_ff "$APP_PATH" "Core" "$CORE_BRANCH"

    if [[ ! -d "$CLIENT_PATH/.git" ]]; then
        mkdir -p "$APP_PATH/client"
        git clone --branch "$CLIENT_BRANCH" "$CLIENT_REPO_URL" "$CLIENT_PATH"
    fi
    repo_pull_ff "$CLIENT_PATH" "Client" "$CLIENT_BRANCH"

    note "Host capacity"
    free -h || true
    swapon --show || true
    df -h "$APP_PATH" || true

    note "Dependencies and frontend build"
    cd "$APP_PATH"
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build

    mark_phase source
}

configure_staging_access() {
    [[ "$DEPLOY_ENV" == "staging" ]] || return
    [[ -n "$(env_get "$ROOT_ENV" STAGING_USER)" && -n "$(env_get "$ROOT_ENV" STAGING_PASSWORD)" ]] && return

    if prompt_yes_no "Protect this staging environment with the shared staging access gate?" yes; then
        local staging_user staging_password
        staging_user="$(prompt_default "Staging access username" "preview")"
        staging_password="$(prompt_secret "Staging access password")"
        env_set "$ROOT_ENV" STAGING_USER "$staging_user"
        env_set "$ROOT_ENV" STAGING_PASSWORD "$staging_password"
    fi
}

configure_local_staging_database() {
    local current_password
    current_password="$(env_get "$CLIENT_ENV" DB_PASSWORD)"
    if [[ -z "$current_password" || "$current_password" =~ ^[0-9a-f]{40}$ ]]; then
        require_command openssl
        current_password="A9!a$(openssl rand -hex 30)"
    fi

    env_set "$ROOT_ENV" DB_CONNECTION mysql
    env_set "$ROOT_ENV" DB_HOST 127.0.0.1
    env_set "$ROOT_ENV" DB_PORT 3306
    env_set "$CLIENT_ENV" DB_DATABASE "$DB_DATABASE_DERIVED"
    env_set "$CLIENT_ENV" DB_USERNAME "$DB_USERNAME_DERIVED"
    env_set "$CLIENT_ENV" DB_PASSWORD "$current_password"

    note "Local Core staging database"
    echo "Database: $DB_DATABASE_DERIVED"
    echo "Application user: $DB_USERNAME_DERIVED"
    echo "Provisioning client-scoped local MySQL database/user through sudo mysql -p."
    echo "Enter the privileged MySQL password when prompted; the launcher does not store it."

    require_command mysql
    if ! sudo mysql -p <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE_DERIVED\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME_DERIVED'@'127.0.0.1' IDENTIFIED BY '$current_password';
ALTER USER '$DB_USERNAME_DERIVED'@'127.0.0.1' IDENTIFIED BY '$current_password';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE_DERIVED\`.* TO '$DB_USERNAME_DERIVED'@'127.0.0.1';
CREATE USER IF NOT EXISTS '$DB_USERNAME_DERIVED'@'localhost' IDENTIFIED BY '$current_password';
ALTER USER '$DB_USERNAME_DERIVED'@'localhost' IDENTIFIED BY '$current_password';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE_DERIVED\`.* TO '$DB_USERNAME_DERIVED'@'localhost';
FLUSH PRIVILEGES;
SQL
    then
        fail "Local MySQL provisioning through sudo mysql -p failed. Fix privileged MySQL access and resume; the generated application credential is already stored in the client .env."
    fi
}

configure_remote_production_database() {
    note "Remote production database"
    echo "Production Core uses the authoritative remote client database."
    echo "The launcher does not create remote databases or remote MySQL users."
    echo "Use your operator/admin database account to provision them, then enter only the client application credential here."

    local host port database username password ssl_ca
    host="$(env_get "$ROOT_ENV" DB_HOST)"
    [[ -n "$host" && "$host" != "127.0.0.1" && "$host" != "localhost" ]] || host=""
    host="${host:-$(prompt_required "Remote DB host")}" 
    port="$(env_get "$ROOT_ENV" DB_PORT)"
    port="${port:-3306}"
    port="$(prompt_default "Remote DB port" "$port")"

    database="$(env_get "$CLIENT_ENV" DB_DATABASE)"
    database="$(prompt_default "Remote client database" "${database:-$DB_DATABASE_DERIVED}")"
    username="$(env_get "$CLIENT_ENV" DB_USERNAME)"
    username="${username:-$(prompt_required "Remote client application DB username")}" 
    password="$(env_get "$CLIENT_ENV" DB_PASSWORD)"
    [[ -n "$password" ]] || password="$(prompt_secret "Remote client application DB password")"

    env_set "$ROOT_ENV" DB_CONNECTION mysql
    env_set "$ROOT_ENV" DB_HOST "$host"
    env_set "$ROOT_ENV" DB_PORT "$port"
    env_set "$CLIENT_ENV" DB_DATABASE "$database"
    env_set "$CLIENT_ENV" DB_USERNAME "$username"
    env_set "$CLIENT_ENV" DB_PASSWORD "$password"

    ssl_ca="$(env_get "$ROOT_ENV" MYSQL_ATTR_SSL_CA)"
    if [[ -z "$ssl_ca" ]] && prompt_yes_no "Does this remote MySQL service require a CA file?" no; then
        ssl_ca="$(prompt_required "Absolute path to MySQL SSL CA file")"
        env_set "$ROOT_ENV" MYSQL_ATTR_SSL_CA "$ssl_ca"
    fi
}

ensure_application_key() {
    local current_key
    current_key="$(env_get "$ROOT_ENV" APP_KEY)"
    [[ -n "$current_key" ]] && return

    note "Generate environment APP_KEY"

    if ! grep -q '^APP_KEY=' "$ROOT_ENV"; then
        printf '\nAPP_KEY=\n' >> "$ROOT_ENV"
    fi

    cd "$APP_PATH"
    "$PHP_BIN" artisan key:generate --force

    current_key="$(env_get "$ROOT_ENV" APP_KEY)"
    [[ -n "$current_key" ]] || fail "APP_KEY generation did not populate $ROOT_ENV."
}

ensure_environment() {
    if phase_done environment; then
        ensure_application_key
        return
    fi

    note "Minimal runtime environment"
    mkdir -p "$APP_PATH" "$CLIENT_PATH"
    touch "$ROOT_ENV" "$CLIENT_ENV"

    env_set "$ROOT_ENV" APP_ENV "$DEPLOY_ENV"
    env_set "$ROOT_ENV" APP_DEBUG false
    env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"
    env_set "$ROOT_ENV" CACHE_STORE redis
    env_set "$ROOT_ENV" SESSION_DRIVER redis
    env_set "$ROOT_ENV" SESSION_SECURE_COOKIE true
    env_set "$ROOT_ENV" QUEUE_CONNECTION redis
    env_set "$ROOT_ENV" REDIS_HOST 127.0.0.1
    env_set "$ROOT_ENV" REDIS_PORT 6379
    env_set "$ROOT_ENV" REDIS_DB 0
    env_set "$ROOT_ENV" REDIS_CACHE_DB 1
    env_set "$ROOT_ENV" LOG_CHANNEL stack
    env_set "$ROOT_ENV" LOG_STACK daily_json
    env_set "$ROOT_ENV" LOG_LEVEL info
    env_set "$ROOT_ENV" LOG_DAILY_DAYS 14
    env_set "$ROOT_ENV" HORIZON_NAME "$ROOT_DOMAIN"
    env_remove "$ROOT_ENV" APP_URL

    env_set "$CLIENT_ENV" ROOT_DOMAIN "$ROOT_DOMAIN"
    env_set "$CLIENT_ENV" APP_URL "https://$ROOT_DOMAIN"
    env_set "$CLIENT_ENV" CRM_APP_URL "https://$CRM_HOST"
    env_set "$CLIENT_ENV" CACHE_PREFIX "$CACHE_PREFIX"
    env_set "$CLIENT_ENV" REDIS_PREFIX "$REDIS_PREFIX"
    env_set "$CLIENT_ENV" HORIZON_PREFIX "$HORIZON_PREFIX"

    if [[ "$DEPLOY_ENV" == "staging" ]]; then
        configure_local_staging_database
    else
        configure_remote_production_database
    fi

    configure_staging_access
    ensure_env_permissions
    validate_client_env_contract

    ensure_application_key
    cd "$APP_PATH"
    "$PHP_BIN" artisan optimize:clear

    mark_phase environment
}

write_plan_json() {
    cd "$APP_PATH"
    set +e
    "$PHP_BIN" artisan engage:deployment-plan --json > "$PLAN_FILE"
    local status=$?
    set -e
    [[ -s "$PLAN_FILE" ]] || fail "engage:deployment-plan --json produced no JSON."
    python3 -m json.tool "$PLAN_FILE" >/dev/null || fail "Deployment-plan JSON is invalid."
    return "$status"
}

module_enabled() {
    local target="$1"
    python3 "$HELPER" plan-modules --plan "$PLAN_FILE" | grep -Fxq "$target"
}

prepare_host_plan() {
    write_plan_json || true
    maybe_configure_optional_surfaces
    write_plan_json || true
}

maybe_configure_optional_surfaces() {
    if module_enabled scheduling && [[ -z "$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)" ]]; then
        local asked
        asked="$(state_get optional_scheduling_decided 2>/dev/null || true)"
        if [[ "$asked" != "true" ]]; then
            if prompt_yes_no "Scheduling is enabled. Expose the generic public booking surface at https://$SCHEDULING_DEFAULT_HOST ?" no; then
                env_set "$CLIENT_ENV" SCHEDULING_APP_URL "https://$SCHEDULING_DEFAULT_HOST"
            fi
            state_set optional_scheduling_decided true
            cd "$APP_PATH"
            "$PHP_BIN" artisan optimize:clear
        fi
    fi
}

resolve_deployment_plan() {
    local force="${1:-false}"
    if [[ "$force" != "true" ]] && phase_done deployment_plan; then
        write_plan_json || true
        return
    fi

    note "Application-owned deployment plan"
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract

    local pass changed setup blocking
    for pass in $(seq 1 20); do
        write_plan_json || true
        maybe_configure_optional_surfaces
        write_plan_json || true

        "$PHP_BIN" artisan engage:environment:sync --write-missing
        ensure_env_permissions
        "$PHP_BIN" artisan optimize:clear
        write_plan_json || true

        local result_file
        result_file="$(mktemp)"
        python3 "$HELPER" resolve-requirements \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --mode non-setup \
            --result-file "$result_file"
        changed="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$changed" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        result_file="$(mktemp)"
        python3 "$HELPER" run-setup-steps \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --state "$STATE_FILE" \
            --result-file "$result_file"
        setup="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$setup" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        result_file="$(mktemp)"
        python3 "$HELPER" resolve-requirements \
            --plan "$PLAN_FILE" \
            --root-env "$ROOT_ENV" \
            --client-env "$CLIENT_ENV" \
            --mode all \
            --result-file "$result_file"
        changed="$(cat "$result_file")"
        rm -f "$result_file"

        if [[ "$changed" != "0" ]]; then
            ensure_env_permissions
            "$PHP_BIN" artisan optimize:clear
            continue
        fi

        write_plan_json || true
        blocking="$(python3 "$HELPER" plan-blocking-count --plan "$PLAN_FILE")"
        if [[ "$blocking" == "0" ]]; then
            mark_phase deployment_plan
            "$PHP_BIN" artisan engage:deployment-plan
            return
        fi
    done

    fail "Deployment-plan resolution did not converge after 20 passes. Inspect $PLAN_FILE and php artisan engage:deployment-plan --verbose."
}

verify_database_connection() {
    note "Database connectivity and identity"
    cd "$APP_PATH"
    "$PHP_BIN" artisan tinker --execute="dump([
        'host' => config('database.connections.mysql.host'),
        'database' => DB::connection()->getDatabaseName(),
        'connected' => DB::connection()->getPdo() !== null,
    ]);"
}

install_new_environment() {
    if phase_done application_install; then
        return
    fi

    note "Application installation"
    cd "$APP_PATH"
    verify_database_connection
    "$PHP_BIN" artisan engage:install --force --no-create-user
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate

    if prompt_yes_no "Create the initial CRM owner/login now?" yes; then
        "$PHP_BIN" artisan engage:user:add
    fi

    mark_phase application_install
}

update_application() {
    note "Normal application deployment"
    cd "$APP_PATH"
    verify_database_connection
    "$PHP_BIN" artisan migrate --force
    "$PHP_BIN" artisan modules:migrate --force
    "$PHP_BIN" artisan presets:sync
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
}

install_added_modules() {
    local module
    [[ ${#REQUESTED_MODULES[@]} -gt 0 ]] || fail "add-modules requires at least one --module MODULE."

    note "Install newly enabled module schema"
    cd "$APP_PATH"
    verify_database_connection
    for module in "${REQUESTED_MODULES[@]}"; do
        if ! module_enabled "$module"; then
            fail "Requested module [$module] is not enabled by the pulled client configuration. Enable/test/commit it in development first."
        fi
        "$PHP_BIN" artisan modules:install "$module" --force
    done
    "$PHP_BIN" artisan presets:sync
    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
}

runtime_permission_setup() {
    note "Runtime directory permissions"
    cd "$APP_PATH"
    sudo chown -R "$DEPLOY_USER:$WEB_GROUP" storage bootstrap/cache
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
    sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

    local log_dir="$APP_PATH/storage/logs"
    local deploy_probe="$log_dir/.engage-permission-deploy-$$"
    local web_probe="$log_dir/.engage-permission-web-$$"

    sudo -u "$DEPLOY_USER" sh -c 'printf "deploy-created\\n" > "$1"' sh "$deploy_probe"
    sudo -u "$WEB_USER" sh -c 'printf "web-updated\\n" >> "$1"' sh "$deploy_probe"
    sudo -u "$WEB_USER" sh -c 'printf "web-created\\n" > "$1"' sh "$web_probe"

    sudo rm -f "$deploy_probe" "$web_probe"
}

install_supervisor_program() {
    note "Supervisor / Horizon"
    require_command supervisorctl
    local conf="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp" <<EOF
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
EOF
    sudo install -o root -g root -m 0644 "$tmp" "$conf"
    rm -f "$tmp"
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl restart "$HORIZON_PROGRAM" >/dev/null 2>&1 || true
    sleep 1
    sudo supervisorctl status "$HORIZON_PROGRAM"
    ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null \
        || fail "Horizon process for $APP_PATH was not found."
}

install_scheduler_cron() {
    note "Laravel Scheduler"
    require_command crontab
    local line="* * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
    local tmp
    tmp="$(mktemp)"
    sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null \
        | grep -Fv "# ${SCHEDULER_MARKER}" > "$tmp" || true
    printf '%s\n' "$line" >> "$tmp"
    sudo crontab -u "$SCHEDULER_USER" "$tmp"
    rm -f "$tmp"
    sudo crontab -u "$SCHEDULER_USER" -l | grep -F "# ${SCHEDULER_MARKER}"
    cd "$APP_PATH"
    "$PHP_BIN" artisan schedule:list
}

ensure_runtime() {
    if phase_done runtime; then
        return
    fi

    identity_preflight
    ensure_env_permissions
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron

    note "Reload PHP-FPM"
    sudo systemctl reload "$PHP_FPM_SERVICE"
    cd "$APP_PATH"
    "$PHP_BIN" artisan horizon:status
    mark_phase runtime
}

core_hosts() {
    local -a hosts=("$CRM_HOST")

    if module_enabled messaging; then
        hosts+=("$MESSAGING_HOST")
    fi
    if module_enabled webinars; then
        hosts+=("$WEBINAR_HOST")
    fi
    if module_enabled messaging || module_enabled inbound_messaging || module_enabled webinars || module_enabled forms; then
        hosts+=("$WEBHOOKS_HOST")
    fi

    local scheduling_url scheduling_host
    scheduling_url="$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)"
    if [[ -n "$scheduling_url" ]]; then
        scheduling_host="$(python3 "$HELPER" origin-host --origin "$scheduling_url")"
        [[ -n "$scheduling_host" ]] && hosts+=("$scheduling_host")
    fi

    printf '%s\n' "${hosts[@]}" | awk 'NF && !seen[$0]++'
}

current_core_hosts_json() {
    core_hosts | python3 -c 'import json,sys; print(json.dumps([line.strip() for line in sys.stdin if line.strip()]))'
}

json_array_lines() {
    python3 -c 'import json,sys; value=sys.stdin.read().strip(); data=json.loads(value) if value else []; [print(str(item)) for item in data if str(item)]'
}

stored_tls_hosts() {
    local value
    value="$(state_get tls_core_hosts 2>/dev/null || true)"
    [[ -n "$value" ]] || return 0
    printf '%s' "$value" | json_array_lines
}

render_nginx_site_config() {
    local available="$1"
    local access_log="$2"
    local error_log="$3"

    local -a desired=() tls=() served=() pending=()
    mapfile -t desired < <(core_hosts)
    mapfile -t tls < <(stored_tls_hosts)

    local host
    for host in "${desired[@]}"; do
        if printf '%s\n' "${tls[@]}" | grep -Fxq "$host"; then
            served+=("$host")
        else
            pending+=("$host")
        fi
    done

    local log_format_suffix=""
    local request_header=""
    local fastcgi_request_id=""
    if [[ -f /etc/nginx/conf.d/00-engage-core-observability.conf ]]; then
        log_format_suffix=" engage_core_json"
    fi
    if [[ -f /etc/nginx/snippets/engage-core-request-id-fastcgi.conf ]]; then
        request_header='    add_header X-Request-ID $request_id always;'
        fastcgi_request_id='        include /etc/nginx/snippets/engage-core-request-id-fastcgi.conf;'
    fi

    local tmp
    tmp="$(mktemp)"
    : > "$tmp"

    if [[ ${#served[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${served[*]};

    location ^~ /.well-known/acme-challenge/ {
        root ${APP_PATH}/public;
        try_files \$uri =404;
    }

    location / {
        return 301 https://\$host\$request_uri;
    }
}

EOF
    fi

    if [[ ${#pending[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${pending[*]};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    access_log ${access_log}${log_format_suffix};
    error_log ${error_log};

    location ^~ /.well-known/acme-challenge/ {
        try_files \$uri =404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
${fastcgi_request_id}
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

EOF
    fi

    if [[ ${#served[@]} -gt 0 ]]; then
        cat >> "$tmp" <<EOF
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${served[*]};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    ssl_certificate /etc/letsencrypt/live/${NGINX_SITE_NAME}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${NGINX_SITE_NAME}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    access_log ${access_log}${log_format_suffix};
    error_log ${error_log};

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
${fastcgi_request_id}
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
    fi

    sudo install -o root -g root -m 0644 "$tmp" "$available"
    rm -f "$tmp"
}

install_nginx_site() {
    local desired_hosts_json previous_hosts_json first_install=false
    desired_hosts_json="$(current_core_hosts_json)"
    previous_hosts_json="$(state_get core_hosts 2>/dev/null || true)"
    if phase_done nginx && [[ "$previous_hosts_json" == "$desired_hosts_json" ]]; then
        return
    fi
    [[ -z "$previous_hosts_json" ]] && first_install=true

    note "Nginx Core-owned hosts"
    require_command nginx
    [[ -S "$PHP_FPM_SOCKET" ]] || fail "PHP-FPM socket not found: $PHP_FPM_SOCKET"

    local -a hosts
    mapfile -t hosts < <(core_hosts)
    [[ ${#hosts[@]} -gt 0 ]] || fail "No Core-owned hosts resolved."
    printf 'Core will own:\n'
    printf '  - %s\n' "${hosts[@]}"

    if [[ "$TOPOLOGY" == "core_services_only" ]]; then
        echo "Root site $ROOT_DOMAIN is external and will NOT be changed by this launcher."
    else
        echo "Root site $ROOT_DOMAIN is reserved for managed main-site type [$MAIN_SITE_TYPE] and will NOT be pointed at Core."
    fi

    local available="/etc/nginx/sites-available/$NGINX_SITE_NAME"
    local enabled="/etc/nginx/sites-enabled/$NGINX_SITE_NAME"
    local access_log="/var/log/nginx/${NGINX_SITE_NAME}-access.log"
    local error_log="/var/log/nginx/${NGINX_SITE_NAME}-error.log"

    render_nginx_site_config "$available" "$access_log" "$error_log"
    sudo ln -sfn "$available" "$enabled"
    sudo nginx -t
    sudo systemctl reload nginx

    local pool_config="/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf"
    if [[ "$first_install" == "true" && -f "$APP_PATH/scripts/operations/install-observability.sh" && -f "$pool_config" ]]; then
        note "Install/refresh Core observability integration"
        sudo bash "$APP_PATH/scripts/operations/install-observability.sh" \
            --client-key "$CLIENT_KEY" \
            --app-path "$APP_PATH" \
            --nginx-site "$available" \
            --access-log "$access_log" \
            --php-version "$PHP_VERSION" \
            --php-pool-config "$pool_config" \
            --deploy-user "$DEPLOY_USER" \
            --apply
    fi

    state_set nginx_site_path "$available"
    state_set access_log_path "$access_log"
    state_set_json core_hosts "$desired_hosts_json"
    mark_phase nginx
}

server_public_ip() {
    local current
    current="$(state_get server_public_ip 2>/dev/null || true)"
    if [[ -n "$current" ]]; then
        printf '%s' "$current"
        return
    fi

    local guess
    guess="$(hostname -I 2>/dev/null | awk '{print $1}')"
    current="$(prompt_default "Public IPv4 address DNS should point to" "${guess:-127.0.0.1}")"
    state_set server_public_ip "$current"
    printf '%s' "$current"
}

dns_host_ready() {
    local host="$1"
    local expected="$2"
    getent ahostsv4 "$host" 2>/dev/null | awk '{print $1}' | sort -u | grep -Fxq "$expected"
}

ensure_certbot() {
    if command -v certbot >/dev/null 2>&1; then
        return
    fi

    if prompt_yes_no "Certbot is not installed. Install certbot now?" yes; then
        sudo apt-get update
        sudo apt-get install -y certbot
    else
        fail "Install Certbot and resume when ready."
    fi
}

install_certbot_reload_hook() {
    local hook='/etc/letsencrypt/renewal-hooks/deploy/engage-nginx-reload.sh'
    local tmp
    tmp="$(mktemp)"
    cat > "$tmp" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
nginx -t
systemctl reload nginx
EOF
    sudo install -d -o root -g root -m 0755 /etc/letsencrypt/renewal-hooks/deploy
    sudo install -o root -g root -m 0755 "$tmp" "$hook"
    rm -f "$tmp"
}

ensure_dns_and_tls() {
    local desired_hosts_json tls_hosts_json
    desired_hosts_json="$(current_core_hosts_json)"
    tls_hosts_json="$(state_get tls_core_hosts 2>/dev/null || true)"
    if phase_done dns_tls && [[ "$tls_hosts_json" == "$desired_hosts_json" ]]; then
        return
    fi

    note "DNS gate"
    require_command getent
    local ip
    ip="$(server_public_ip)"
    local -a hosts old_tls_hosts
    mapfile -t hosts < <(core_hosts)
    mapfile -t old_tls_hosts < <(stored_tls_hosts)

    echo "Create/verify these DNS records in the client's DNS provider:"
    local host
    for host in "${hosts[@]}"; do
        printf '  A  %-45s -> %s\n' "$host" "$ip"
    done
    echo
    echo "Do not change $ROOT_DOMAIN here; its owner is defined by topology [$TOPOLOGY]."

    while true; do
        local missing=0
        for host in "${hosts[@]}"; do
            if dns_host_ready "$host" "$ip"; then
                echo "DNS ready: $host -> $ip"
            else
                echo "DNS NOT ready: $host does not currently resolve to $ip"
                missing=1
            fi
        done
        [[ "$missing" == "0" ]] && break
        echo "Press Enter to check DNS again, or Ctrl+C to stop and resume later."
        read -r
    done

    note "TLS certificates"
    ensure_certbot
    install_certbot_reload_hook

    local email
    email="$(state_get certbot_email 2>/dev/null || true)"
    if [[ -z "$email" ]]; then
        email="$(prompt_required "Email address for Let's Encrypt/Certbot notices")"
        state_set certbot_email "$email"
    fi

    local -a cert_args=()
    for host in "${hosts[@]}"; do
        cert_args+=( -d "$host" )
    done

    local mode_arg='--keep-until-expiring'
    if [[ ${#old_tls_hosts[@]} -gt 0 ]]; then
        local old_host all_old_present=true
        for old_host in "${old_tls_hosts[@]}"; do
            if ! printf '%s\n' "${hosts[@]}" | grep -Fxq "$old_host"; then
                all_old_present=false
                break
            fi
        done
        if [[ "$all_old_present" == "true" ]]; then
            mode_arg='--expand'
        else
            mode_arg='--force-renewal'
        fi
    fi

    sudo certbot certonly --webroot \
        -w "$APP_PATH/public" \
        --non-interactive \
        --agree-tos \
        --cert-name "$NGINX_SITE_NAME" \
        "$mode_arg" \
        -m "$email" \
        "${cert_args[@]}"

    state_set_json tls_core_hosts "$desired_hosts_json"
    local available access_log error_log
    available="$(state_get nginx_site_path)"
    access_log="$(state_get access_log_path)"
    error_log="/var/log/nginx/${NGINX_SITE_NAME}-error.log"
    render_nginx_site_config "$available" "$access_log" "$error_log"
    sudo nginx -t
    sudo systemctl reload nginx
    mark_phase dns_tls
}

audit_reset() {
    AUDIT_PASS=0
    AUDIT_INFO=0
    AUDIT_WARNING=0
    AUDIT_BREAKING=0
    AUDIT_MANUAL=0
    AUDIT_BREAKING_KEYS=()
    AUDIT_SOURCE_CURRENT="true"
    AUDIT_APP_COMMANDS_AVAILABLE="unknown"
    AUDIT_APP_BOOTSTRAP_READY="unknown"
    AUDIT_NGINX_DUMP_LOADED="false"
    AUDIT_NGINX_DUMP=""
    AUDIT_PLAN_JSON=""
    AUDIT_MODULE_STATUS_JSON=""
}

audit_result() {
    local status="$1"
    local key="$2"
    local message="$3"
    message="${message//$'\n'/ }"

    printf '%-28s %-38s %s\n' "$status" "$key" "$message"

    case "$status" in
        PASS) ((AUDIT_PASS += 1)) ;;
        INFO) ((AUDIT_INFO += 1)) ;;
        WARNING) ((AUDIT_WARNING += 1)) ;;
        BREAKING)
            ((AUDIT_BREAKING += 1))
            AUDIT_BREAKING_KEYS+=("$key")
            ;;
        "MANUAL VERIFICATION REQUIRED") ((AUDIT_MANUAL += 1)) ;;
        *) fail "Unknown audit result status [$status]." ;;
    esac
}

audit_summary() {
    echo
    echo "Audit summary"
    printf '  PASS:                         %d\n' "$AUDIT_PASS"
    printf '  INFO:                         %d\n' "$AUDIT_INFO"
    printf '  WARNING:                      %d\n' "$AUDIT_WARNING"
    printf '  BREAKING:                     %d\n' "$AUDIT_BREAKING"
    printf '  MANUAL VERIFICATION REQUIRED: %d\n' "$AUDIT_MANUAL"
    echo
    echo "Fix eligibility: only BREAKING findings are candidates for automatic remediation."
    echo "INFO and WARNING findings are never normalized merely to match a new-deployment convention."
    echo "No deployment state was changed by audit."

    [[ "$AUDIT_BREAKING" -eq 0 ]]
}

audit_runtime_violation() {
    local key="$1"
    local message="$2"

    case "${AUDIT_CHECKOUT_ACTIVE:-unknown}" in
        true)
            audit_result BREAKING "$key" "$message"
            ;;
        false)
            audit_result WARNING "$key" "$message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
            ;;
        *)
            audit_result WARNING "$key" "$message Active runtime ownership is not proven, so automatic repair is not eligible."
            ;;
    esac
}

audit_identity_value() {
    local key="$1"
    printf '%s' "$AUDIT_IDENTITY_JSON" | python3 -c '
import json, sys
data = json.load(sys.stdin)
value = data.get(sys.argv[1])
if value is None:
    raise SystemExit(1)
print(value)
' "$key"
}

audit_find_app_path() {
    local requested="$1"
    local canonical="$2"

    if [[ -n "$requested" ]]; then
        printf '%s\n' "$requested"
        return 0
    fi

    if [[ -f "$canonical/artisan" && -d "$canonical/client/$CLIENT_KEY" ]]; then
        printf '%s\n' "$canonical"
        return 0
    fi

    local -a candidates=()
    local modules_file candidate existing
    while IFS= read -r modules_file; do
        [[ -n "$modules_file" ]] || continue
        candidate="${modules_file%/client/$CLIENT_KEY/config/modules.php}"
        [[ -f "$candidate/artisan" ]] || continue

        local duplicate=false
        for existing in "${candidates[@]:-}"; do
            if [[ "$existing" == "$candidate" ]]; then
                duplicate=true
                break
            fi
        done

        [[ "$duplicate" == "true" ]] || candidates+=("$candidate")
    done < <(find /var/www -maxdepth 9 -type f -path "*/client/$CLIENT_KEY/config/modules.php" -print 2>/dev/null || true)

    if [[ ${#candidates[@]} -eq 1 ]]; then
        printf '%s\n' "${candidates[0]}"
        return 0
    fi

    if [[ ${#candidates[@]} -gt 1 ]]; then
        printf 'Multiple candidate Core checkouts contain client [%s]:\n' "$CLIENT_KEY" >&2
        printf '  - %s\n' "${candidates[@]}" >&2
        return 2
    fi

    return 1
}

audit_git_repo() {
    local path="$1"
    local key_prefix="$2"
    local expected_branch="$3"
    local expected_origin="${4:-}"

    if [[ ! -d "$path/.git" ]]; then
        audit_result WARNING "$key_prefix.repository" "Git metadata is missing at $path; current-source provenance cannot be verified."
        AUDIT_SOURCE_CURRENT="false"
        return
    fi

    local branch dirty origin head remote_head
    branch="$(git -C "$path" branch --show-current 2>/dev/null || true)"
    if [[ "$branch" == "$expected_branch" ]]; then
        audit_result PASS "$key_prefix.branch" "Branch is $branch."
    else
        audit_result WARNING "$key_prefix.branch" "Checkout is on [${branch:-detached/unknown}], expected [$expected_branch]. Pull/select the intended current branch before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    fi

    dirty="$(git -C "$path" status --porcelain 2>/dev/null || true)"
    if [[ -z "$dirty" ]]; then
        audit_result PASS "$key_prefix.clean" "Checkout is clean."
    else
        audit_result WARNING "$key_prefix.clean" "Checkout has uncommitted or untracked changes. Resolve source drift before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    fi

    origin="$(git -C "$path" remote get-url origin 2>/dev/null || true)"
    if [[ -z "$origin" ]]; then
        audit_result WARNING "$key_prefix.origin" "Origin remote is not configured; current-source provenance cannot be verified."
        AUDIT_SOURCE_CURRENT="false"
        return
    fi

    if [[ -n "$expected_origin" && "$origin" != "$expected_origin" ]]; then
        audit_result WARNING "$key_prefix.origin" "Origin differs from expected [$expected_origin]; found [$origin]. Resolve source identity before auditing runtime state."
        AUDIT_SOURCE_CURRENT="false"
    else
        audit_result PASS "$key_prefix.origin" "Origin is [$origin]."
    fi

    head="$(git -C "$path" rev-parse HEAD 2>/dev/null || true)"
    set +e
    remote_head="$(
        GIT_TERMINAL_PROMPT=0 \
        GIT_SSH_COMMAND='ssh -o BatchMode=yes -o ConnectTimeout=8' \
        git ls-remote "$origin" "refs/heads/$expected_branch" 2>/dev/null \
            | awk 'NR == 1 {print $1}'
    )"
    local remote_status=$?
    set -e

    if [[ "$remote_status" -ne 0 || -z "$remote_head" ]]; then
        audit_result "MANUAL VERIFICATION REQUIRED" "$key_prefix.current" \
            "Could not verify the remote [$expected_branch] revision without mutating local Git state. Confirm remote access and rerun audit."
        AUDIT_SOURCE_CURRENT="false"
    elif [[ "$head" == "$remote_head" ]]; then
        audit_result PASS "$key_prefix.current" "Local HEAD matches origin/$expected_branch."
    else
        audit_result WARNING "$key_prefix.current" \
            "Local HEAD does not match origin/$expected_branch. Pull the current repository state, then rerun audit."
        AUDIT_SOURCE_CURRENT="false"
    fi
}

audit_env_equals() {
    local file="$1"
    local env_key="$2"
    local expected="$3"
    local result_key="$4"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_runtime_violation "$result_key" "$env_key is not populated in $file."
    elif [[ "$actual" == "$expected" ]]; then
        audit_result PASS "$result_key" "$env_key matches the canonical value."
    else
        audit_runtime_violation "$result_key" "$env_key expected [$expected]; found [$actual]."
    fi
}

audit_env_present_redacted() {
    local file="$1"
    local env_key="$2"
    local result_key="$3"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -n "$actual" ]]; then
        audit_result PASS "$result_key" "$env_key is populated; value not displayed."
    else
        audit_runtime_violation "$result_key" "$env_key is blank or missing."
    fi
}

audit_env_file_metadata() {
    local path="$1"
    local key_prefix="$2"

    if [[ ! -f "$path" ]]; then
        audit_runtime_violation "$key_prefix.file" "Environment file is missing: $path"
        return
    fi

    local owner group mode deploy_group
    owner="$(stat -c '%U' "$path")"
    group="$(stat -c '%G' "$path")"
    mode="$(stat -c '%a' "$path")"
    deploy_group="$(id -gn "$DEPLOY_USER" 2>/dev/null || true)"

    if [[ "$DEPLOY_ENV" == "staging" ]]; then
        if [[ -z "$deploy_group" ]]; then
            audit_result WARNING "$key_prefix.metadata" \
                "Cannot resolve the primary group for deploy user [$DEPLOY_USER]; found $owner:$group $mode."
        elif [[ "$owner" == "$DEPLOY_USER" && "$group" == "$deploy_group" && "$mode" == "664" ]]; then
            audit_result PASS "$key_prefix.metadata" "Staging convention is $owner:$group $mode."
        else
            audit_result INFO "$key_prefix.metadata" \
                "Legacy metadata drift only: new staging deployments use $DEPLOY_USER:$deploy_group 664; found $owner:$group $mode. Effective readability is authoritative."
        fi
    else
        if [[ "$owner" == "$DEPLOY_USER" ]]; then
            audit_result PASS "$key_prefix.owner" "Production environment file owner is $owner."
        else
            audit_result INFO "$key_prefix.owner" "Production environment-file owner differs from new-deployment convention [$DEPLOY_USER]; found [$owner]. Effective access and deliberate production policy are authoritative."
        fi
        audit_result "MANUAL VERIFICATION REQUIRED" "$key_prefix.mode" \
            "Production secret-file mode/group policy is intentionally not auto-normalized by the staging-proven 0664 convention; found $owner:$group $mode."
    fi

    if id "$DEPLOY_USER" >/dev/null 2>&1; then
        if sudo -u "$DEPLOY_USER" test -r "$path"; then
            audit_result PASS "$key_prefix.deploy_read" "$DEPLOY_USER can read the environment file."
        else
            audit_runtime_violation "$key_prefix.deploy_read" "$DEPLOY_USER cannot read the environment file."
        fi
    else
        audit_result WARNING "$key_prefix.deploy_read" "Deploy user [$DEPLOY_USER] does not exist; verify the audit identity before remediation."
    fi

    if id "$WEB_USER" >/dev/null 2>&1; then
        if sudo -u "$WEB_USER" test -r "$path"; then
            audit_result PASS "$key_prefix.web_read" "$WEB_USER can read the environment file."
        else
            audit_runtime_violation "$key_prefix.web_read" "$WEB_USER cannot read the environment file."
        fi
    else
        audit_result WARNING "$key_prefix.web_read" "Web user [$WEB_USER] does not exist; verify the audit identity before remediation."
    fi
}

audit_prefix_collision_from_map() {
    local collision_json="$1"
    local env_key="$2"
    local value="$3"
    local result_key="$4"

    [[ -n "$value" ]] || return 0

    local collisions
    collisions="$(printf '%s' "$collision_json" | python3 -c '
import json, sys
key = sys.argv[1]
try:
    payload = json.load(sys.stdin)
except json.JSONDecodeError:
    payload = {}
for path in payload.get(key, []):
    print(path)
' "$env_key" 2>/dev/null || true)"

    if [[ -z "$collisions" ]]; then
        audit_result PASS "$result_key" "$env_key is not duplicated in another readable /var/www environment."
    else
        collisions="${collisions//$'\n'/, }"
        audit_result WARNING "$result_key" \
            "$env_key is duplicated in another readable environment: $collisions. File duplication alone does not prove concurrent Redis use; review active workers before remediation."
    fi
}

audit_env_defaulted() {
    local file="$1"
    local env_key="$2"
    local default_value="$3"
    local result_key="$4"
    local actual

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_result PASS "$result_key" "$env_key is not persisted and therefore uses the Core default [$default_value]."
    elif [[ "$actual" == "$default_value" ]]; then
        audit_result PASS "$result_key" "$env_key explicitly matches the Core default [$default_value]."
    else
        audit_result WARNING "$result_key" \
            "$env_key explicitly overrides the Core default [$default_value] with [$actual]. Review only if the override is not deliberate."
    fi
}

audit_canonical_drift() {
    local actual="$1"
    local expected="$2"
    local result_key="$3"
    local label="$4"

    if [[ -z "$actual" ]]; then
        audit_result WARNING "$result_key" "$label is blank. Validate the application-owned requirement before treating this as repairable."
    elif [[ "$actual" == "$expected" ]]; then
        audit_result PASS "$result_key" "$label matches canonical [$expected]."
    else
        audit_result INFO "$result_key" \
            "Legacy naming drift only: new deployments derive [$expected]; found [$actual]. Do not normalize a functional existing identity."
    fi
}

audit_load_nginx_dump() {
    if [[ "$AUDIT_NGINX_DUMP_LOADED" == "true" ]]; then
        [[ -n "$AUDIT_NGINX_DUMP" ]]
        return
    fi

    AUDIT_NGINX_DUMP_LOADED="true"

    local status
    set +e
    AUDIT_NGINX_DUMP="$(sudo nginx -T 2>/dev/null)"
    status=$?
    set -e

    [[ "$status" -eq 0 && -n "$AUDIT_NGINX_DUMP" ]]
}

audit_nginx_owners_json() {
    local host="$1"

    if audit_load_nginx_dump; then
        printf '%s' "$AUDIT_NGINX_DUMP" \
            | python3 "$HELPER" nginx-host-owners-stdin --host "$host" 2>/dev/null \
            || printf '[]'
        return
    fi

    python3 "$HELPER" nginx-host-owners \
        --directory /etc/nginx/sites-enabled \
        --host "$host" 2>/dev/null || printf '[]'
}

audit_nginx_owner_summary() {
    local owners_json="$1"

    printf '%s' "$owners_json" | python3 -c '
import json, sys
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    root = owner.get("root") or "[no root]"
    config = owner.get("config") or "[unknown config]"
    fastcgi = owner.get("fastcgi_pass") or "[no fastcgi_pass]"
    print(f"{root} via {config} ({fastcgi})")
' 2>/dev/null || true
}

audit_detect_active_host_owner() {
    AUDIT_CHECKOUT_ACTIVE="unknown"
    AUDIT_ACTIVE_APP_PATH=""

    local owners_json count root config owner_summary
    owners_json="$(audit_nginx_owners_json "$CRM_HOST")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"

    if [[ "$count" -eq 0 ]]; then
        audit_result WARNING deployment.crm_host_owner \
            "No application-serving Nginx block was discovered for CRM host [$CRM_HOST]. Runtime ownership is not proven."
        return
    fi

    if [[ "$count" -gt 1 ]]; then
        owner_summary="$(audit_nginx_owner_summary "$owners_json")"
        owner_summary="${owner_summary//$'\n'/; }"
        audit_result BREAKING deployment.crm_host_owner \
            "Multiple distinct application-serving Nginx owners claim CRM host [$CRM_HOST]: ${owner_summary:-[details unavailable]}. Host ownership is genuinely ambiguous."
        return
    fi

    root="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)[0].get("root", ""))')"
    config="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(json.load(sys.stdin)[0].get("config", ""))')"

    if [[ "$root" == "${APP_PATH%/}/public" ]]; then
        AUDIT_CHECKOUT_ACTIVE="true"
        AUDIT_ACTIVE_APP_PATH="$APP_PATH"
        audit_result PASS deployment.crm_host_owner \
            "CRM host [$CRM_HOST] is served by the audited Engage Core checkout via [$config]."
        return
    fi

    if [[ "$root" == */public ]]; then
        AUDIT_ACTIVE_APP_PATH="${root%/public}"
    fi
    AUDIT_CHECKOUT_ACTIVE="false"

    local active_origin=""
    if [[ -n "$AUDIT_ACTIVE_APP_PATH" && -d "$AUDIT_ACTIVE_APP_PATH/.git" ]]; then
        active_origin="$(git -C "$AUDIT_ACTIVE_APP_PATH" remote get-url origin 2>/dev/null || true)"
    fi

    audit_result INFO deployment.crm_host_owner \
        "CRM host [$CRM_HOST] is currently served from [${root:-unknown root}] via [$config], not [$APP_PATH/public].${active_origin:+ Active checkout origin: [$active_origin].} This is runtime ownership/cutover state, not an automatic fix."

    audit_result INFO deployment.checkout_role \
        "The audited Engage Core checkout is not the live CRM host owner. Treat its runtime findings as cutover readiness, not defects in the currently served application."
}

audit_composer_dependencies() {
    if [[ -f "$APP_PATH/vendor/autoload.php" ]]; then
        AUDIT_APP_COMMANDS_AVAILABLE="true"
        audit_result PASS runtime.composer_dependencies "Composer runtime dependencies are installed."
        return
    fi

    AUDIT_APP_COMMANDS_AVAILABLE="false"
    AUDIT_APP_BOOTSTRAP_READY="false"
    if [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
        audit_result INFO runtime.composer_dependencies \
            "vendor/autoload.php is absent in the inactive Engage Core checkout. Application-level checks are unavailable until a deliberate cutover is prepared."
    else
        audit_result BREAKING runtime.composer_dependencies \
            "vendor/autoload.php is absent in the active Engage Core checkout. Artisan and application runtime cannot operate normally without installed Composer dependencies."
    fi
}

audit_client_environment_contract() {
    if [[ "$AUDIT_APP_COMMANDS_AVAILABLE" != "true" ]]; then
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" -r '
require __DIR__."/vendor/autoload.php";

try {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
} catch (Throwable $e) {
    fwrite(STDERR, "ROOT_ENV_PARSE_ERROR: ".get_class($e).PHP_EOL);
    exit(2);
}

try {
    (new App\Support\Clients\ClientEnvironmentLoader())->load(__DIR__);
    echo "CLIENT ENV OK\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).": ".$e->getMessage().PHP_EOL);
    exit(1);
}
' 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -eq 0 ]]; then
        AUDIT_APP_BOOTSTRAP_READY="true"
        audit_result PASS env.client_contract "Root/client environment loading contract passed before Laravel bootstrap."
        return
    fi

    AUDIT_APP_BOOTSTRAP_READY="false"
    output="$(printf '%s' "$output" | tail -n 3 | tr '\n' ' ')"

    if [[ "$status" -eq 2 ]]; then
        audit_runtime_violation env.root_parse \
            "Root .env could not be parsed safely. Error details are suppressed because environment values may be sensitive."
    else
        audit_runtime_violation env.client_contract \
            "Selected-client environment violates the bootstrap-safe ownership contract: ${output:-[no diagnostic output]}"
    fi
}

audit_app_command() {
    local result_key="$1"
    shift

    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO "$result_key"             "Not evaluated because the early environment/bootstrap contract is already failing."
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$@" 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -eq 0 ]]; then
        audit_result PASS "$result_key" "Read-only application command passed."
    else
        output="$(printf '%s' "$output" | tail -n 8 | tr '\n' ' ')"
        audit_runtime_violation "$result_key" "Read-only application command failed: ${output:-[no output]}"
    fi
}

audit_resolve_plan() {
    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO deployment_plan.unavailable             "Not evaluated because the early environment/bootstrap contract is already failing."
        AUDIT_PLAN_JSON=""
        return
    fi

    local output status
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan engage:deployment-plan --json 2>&1
    )"
    status=$?
    set -e

    if ! printf '%s' "$output" | python3 -m json.tool >/dev/null 2>&1; then
        audit_runtime_violation deployment_plan.json \
            "engage:deployment-plan --json did not produce valid JSON (exit $status)."
        AUDIT_PLAN_JSON=""
        return
    fi

    AUDIT_PLAN_JSON="$output"
    while IFS=$'\t' read -r result_status result_key result_message; do
        [[ -n "$result_status" ]] || continue
        if [[ "$result_status" == "BREAKING" && "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
            audit_result WARNING "$result_key" "$result_message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
        else
            audit_result "$result_status" "$result_key" "$result_message"
        fi
    done < <(printf '%s' "$AUDIT_PLAN_JSON" | python3 "$HELPER" plan-audit)
}

audit_module_migrations() {
    if [[ "${AUDIT_APP_BOOTSTRAP_READY:-unknown}" == "false" ]]; then
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    local output status payload
    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$modules = app(\App\Support\Modules\ModuleManager::class);
$planner = app(\App\Support\Modules\Migrations\ModuleMigrationPlanner::class);
$inspector = app(\App\Support\Modules\Migrations\ModuleMigrationStatusInspector::class);
$migrator = app(\Illuminate\Database\Migrations\Migrator::class);

$enabled = $modules->enabledKeysWithDependencies();
$plan = $planner->forModules($enabled);
$statuses = $inspector->inspectScopes($plan->migrationScopes);

$payload = [
    "platform" => [
        "repository_exists" => $migrator->repositoryExists(),
        "ledger_exists" => \Illuminate\Support\Facades\Schema::hasTable("module_installations"),
    ],
    "scopes" => array_map(
        static fn (\App\Support\Modules\Migrations\ModuleMigrationStatus $status): array => [
            "module_key" => (string) $status->scope->moduleKey,
            "migration_state" => $status->migrationState,
            "progress" => $status->progress(),
            "pending_migrations" => $status->pendingMigrationFiles,
            "ledger_status" => $status->ledgerStatus,
            "contract_state" => $status->contractState,
        ],
        $statuses,
    ),
];

echo "__ENGAGE_MODULE_STATUS_JSON__".json_encode($payload, JSON_THROW_ON_ERROR);
' 2>&1
    )"
    status=$?
    set -e

    if [[ "$status" -ne 0 || "$output" != *"__ENGAGE_MODULE_STATUS_JSON__"* ]]; then
        output="$(printf '%s' "$output" | tail -n 8 | tr '\n' ' ')"
        audit_runtime_violation module_migrations.inspect \
            "Unable to inspect enabled module migration state read-only: ${output:-[no output]}"
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    payload="${output#*__ENGAGE_MODULE_STATUS_JSON__}"
    if ! printf '%s' "$payload" | python3 -m json.tool >/dev/null 2>&1; then
        audit_runtime_violation module_migrations.inspect \
            "Enabled module migration inspection returned invalid JSON."
        AUDIT_MODULE_STATUS_JSON=""
        return
    fi

    AUDIT_MODULE_STATUS_JSON="$payload"
    while IFS=$'\t' read -r result_status result_key result_message; do
        [[ -n "$result_status" ]] || continue
        if [[ "$result_status" == "BREAKING" && "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
            audit_result WARNING "$result_key" \
                "$result_message The audited checkout is not the live CRM owner, so this is cutover readiness rather than a current platform break."
        else
            audit_result "$result_status" "$result_key" "$result_message"
        fi
    done < <(printf '%s' "$AUDIT_MODULE_STATUS_JSON" | python3 "$HELPER" module-status-audit)
}

audit_plan_module_enabled() {
    local module="$1"
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 1
    printf '%s' "$AUDIT_PLAN_JSON" \
        | python3 "$HELPER" plan-modules-stdin \
        | grep -Fxq "$module"
}

audit_core_hosts() {
    local -a hosts=("$CRM_HOST")

    if audit_plan_module_enabled messaging; then
        hosts+=("$MESSAGING_HOST")
    fi
    if audit_plan_module_enabled webinars; then
        hosts+=("$WEBINAR_HOST")
    fi
    if audit_plan_module_enabled messaging \
        || audit_plan_module_enabled inbound_messaging \
        || audit_plan_module_enabled webinars \
        || audit_plan_module_enabled forms
    then
        hosts+=("$WEBHOOKS_HOST")
    fi

    local scheduling_url scheduling_host
    scheduling_url="$(env_get "$CLIENT_ENV" SCHEDULING_APP_URL)"
    if [[ -n "$scheduling_url" ]]; then
        scheduling_host="$(python3 "$HELPER" origin-host --origin "$scheduling_url" 2>/dev/null || true)"
        [[ -n "$scheduling_host" ]] && hosts+=("$scheduling_host")
    fi

    printf '%s\n' "${hosts[@]}" | awk 'NF && !seen[$0]++'
}

audit_nginx_host() {
    local expected_host="$1"
    local owners_json count owner_summary matching_roots

    owners_json="$(audit_nginx_owners_json "$expected_host")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"

    if [[ "$count" -eq 0 ]]; then
        audit_result BREAKING "nginx.host.$expected_host" \
            "No application-serving Nginx block owns required Core host [$expected_host]."
        return
    fi

    matching_roots="$(printf '%s' "$owners_json" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
print(sum(1 for owner in owners if (owner.get("root") or "").rstrip("/") == target))
' "$APP_PATH/public" 2>/dev/null || printf '0')"

    if [[ "$count" -eq 1 && "$matching_roots" -eq 1 ]]; then
        audit_result PASS "nginx.host.$expected_host" \
            "Required Core host is served from $APP_PATH/public."
        return
    fi

    owner_summary="$(audit_nginx_owner_summary "$owners_json")"
    owner_summary="${owner_summary//$'\n'/; }"

    if [[ "$matching_roots" -gt 0 ]]; then
        audit_result BREAKING "nginx.host.$expected_host" \
            "Core host [$expected_host] has multiple distinct application-serving owners: ${owner_summary:-[details unavailable]}."
    else
        audit_result BREAKING "nginx.host.$expected_host" \
            "Core host [$expected_host] is served by a different application root: ${owner_summary:-[details unavailable]}."
    fi
}

audit_live_tls_host() {
    local host="$1"

    if ! command -v curl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" "tls.host.$host" \
            "curl is unavailable; verify the live certificate and hostname coverage for https://$host manually."
        return
    fi

    local tls_error
    set +e
    tls_error="$(curl -sS \
        --resolve "$host:443:127.0.0.1" \
        --connect-timeout 5 \
        --max-time 10 \
        -o /dev/null \
        "https://$host/" 2>&1)"
    local curl_status=$?
    set -e

    if [[ "$curl_status" -eq 0 ]]; then
        audit_result PASS "tls.host.$host" \
            "The certificate served locally for [$host] passes CA and hostname validation."
    else
        tls_error="$(printf '%s' "$tls_error" | tail -n 2 | tr '\n' ' ')"
        audit_result BREAKING "tls.host.$host" \
            "TLS validation failed for the certificate actually served for [$host]: ${tls_error:-curl exit $curl_status}."
        return
    fi

    if ! command -v openssl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" "tls.expiry.$host" \
            "openssl is unavailable; verify certificate expiry for [$host] manually."
        return
    fi

    local certificate
    certificate="$(
        printf '\n' \
            | openssl s_client \
                -connect 127.0.0.1:443 \
                -servername "$host" \
                -showcerts 2>/dev/null \
            | awk '
                /-----BEGIN CERTIFICATE-----/ {capture=1}
                capture {print}
                /-----END CERTIFICATE-----/ {exit}
            '
    )"

    if [[ -z "$certificate" ]]; then
        audit_result WARNING "tls.expiry.$host" \
            "TLS hostname validation passed, but the leaf certificate could not be extracted for expiry inspection."
        return
    fi

    if printf '%s\n' "$certificate" \
        | openssl x509 -noout -checkend 2592000 >/dev/null 2>&1
    then
        audit_result PASS "tls.expiry.$host" \
            "The certificate actually served for [$host] is valid for more than 30 days."
    else
        audit_result WARNING "tls.expiry.$host" \
            "The certificate actually served for [$host] expires within 30 days or its expiry could not be validated."
    fi
}

audit_nginx() {
    note "Nginx / TLS"
    local canonical="/etc/nginx/sites-available/$NGINX_SITE_NAME"
    local -a configs=()
    local path resolved existing

    for path in /etc/nginx/sites-enabled/*; do
        [[ -e "$path" ]] || continue
        if grep -Fq "root $APP_PATH/public;" "$path" 2>/dev/null; then
            resolved="$(readlink -f "$path" 2>/dev/null || printf '%s' "$path")"
            local duplicate=false
            for existing in "${configs[@]:-}"; do
                [[ "$existing" == "$resolved" ]] && duplicate=true
            done
            [[ "$duplicate" == "true" ]] || configs+=("$resolved")
        fi
    done

    if [[ ${#configs[@]} -eq 0 ]]; then
        audit_result BREAKING nginx.site \
            "No enabled Nginx configuration references the active Engage Core document root $APP_PATH/public."
    elif [[ ${#configs[@]} -eq 1 && "$(readlink -f "${configs[0]}" 2>/dev/null || printf '%s' "${configs[0]}")" == "$(readlink -f "$canonical" 2>/dev/null || printf '%s' "$canonical")" ]]; then
        audit_result PASS nginx.site "Canonical Core site exists at $canonical and is enabled."
    else
        audit_result INFO nginx.site \
            "Nginx site filename/layout differs from new-deployment convention [$canonical]; discovered: ${configs[*]}. Do not rename a healthy site for consistency."
    fi

    local expected_host
    while IFS= read -r expected_host; do
        [[ -n "$expected_host" ]] || continue
        audit_nginx_host "$expected_host"
    done < <(audit_core_hosts)

    local root_owners root_core_count root_summary
    root_owners="$(audit_nginx_owners_json "$ROOT_DOMAIN")"
    root_core_count="$(printf '%s' "$root_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
print(sum(1 for owner in owners if (owner.get("root") or "").rstrip("/") == target))
' "$APP_PATH/public" 2>/dev/null || printf '0')"

    if [[ "$root_core_count" -gt 0 ]]; then
        audit_result BREAKING nginx.root_domain \
            "Root domain [$ROOT_DOMAIN] is served from the Core document root [$APP_PATH/public]. Core must not take ownership of the main-site root."
    else
        root_summary="$(audit_nginx_owner_summary "$root_owners")"
        root_summary="${root_summary//$'\n'/; }"
        if [[ -n "$root_summary" ]]; then
            audit_result PASS nginx.root_domain \
                "Root domain [$ROOT_DOMAIN] is served separately from Core: $root_summary."
        else
            audit_result PASS nginx.root_domain \
                "Root domain [$ROOT_DOMAIN] is not served from the Core document root."
        fi
    fi

    local crm_owners crm_root crm_fastcgi
    crm_owners="$(audit_nginx_owners_json "$CRM_HOST")"
    crm_root="$(printf '%s' "$crm_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    if (owner.get("root") or "").rstrip("/") == target:
        print(owner.get("root") or "")
        break
' "$APP_PATH/public" 2>/dev/null || true)"
    crm_fastcgi="$(printf '%s' "$crm_owners" | python3 -c '
import json, sys
target = sys.argv[1].rstrip("/")
try:
    owners = json.load(sys.stdin)
except json.JSONDecodeError:
    owners = []
for owner in owners:
    if (owner.get("root") or "").rstrip("/") == target:
        print(owner.get("fastcgi_pass") or "")
        break
' "$APP_PATH/public" 2>/dev/null || true)"

    if [[ "$crm_root" == "$APP_PATH/public" ]]; then
        audit_result PASS nginx.document_root \
            "CRM Nginx ownership resolves to $APP_PATH/public."
    else
        audit_result BREAKING nginx.document_root \
            "CRM Nginx ownership does not resolve to $APP_PATH/public."
    fi

    if [[ "$crm_fastcgi" == "unix:$PHP_FPM_SOCKET" ]]; then
        audit_result PASS nginx.php_fpm \
            "The CRM application-serving block uses expected PHP-FPM socket $PHP_FPM_SOCKET."
    elif [[ -n "$crm_fastcgi" ]]; then
        audit_result INFO nginx.php_fpm \
            "The CRM application-serving block uses [$crm_fastcgi] rather than new-deployment default [unix:$PHP_FPM_SOCKET]. HTTP runtime verification is authoritative before remediation."
    else
        audit_result WARNING nginx.php_fpm \
            "No fastcgi_pass target was discovered in the CRM application-serving block."
    fi

    if audit_load_nginx_dump; then
        audit_result PASS nginx.syntax "nginx -T passed and the active configuration was parsed."
    else
        local nginx_test
        set +e
        nginx_test="$(sudo nginx -t 2>&1)"
        local nginx_status=$?
        set -e
        if [[ "$nginx_status" -eq 0 ]]; then
            audit_result PASS nginx.syntax \
                "nginx -t passed, but nginx -T output was unavailable for authoritative ownership parsing."
        else
            audit_result BREAKING nginx.syntax \
                "$(printf '%s' "$nginx_test" | tail -n 3 | tr '\n' ' ')"
        fi
    fi

    while IFS= read -r expected_host; do
        [[ -n "$expected_host" ]] || continue
        audit_live_tls_host "$expected_host"
    done < <(audit_core_hosts)
}

audit_http_runtime() {
    note "HTTP runtime"

    if [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" != "true" ]]; then
        audit_result INFO http.crm "HTTP runtime verification is skipped because the audited checkout is not the active CRM host owner."
        return
    fi

    if ! command -v curl >/dev/null 2>&1; then
        audit_result "MANUAL VERIFICATION REQUIRED" http.crm "curl is unavailable; verify https://$CRM_HOST/login manually."
        return
    fi

    local status
    status="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "https://$CRM_HOST/login" 2>/dev/null || true)"
    case "$status" in
        2??|3??|401|403) audit_result PASS http.crm "https://$CRM_HOST/login returned HTTP $status." ;;
        000|'') audit_result BREAKING http.crm "https://$CRM_HOST/login was not reachable over HTTPS." ;;
        *) audit_result BREAKING http.crm "https://$CRM_HOST/login returned HTTP $status." ;;
    esac
}

audit_dns() {
    note "DNS"
    local server_ip="$AUDIT_SERVER_IP"
    local source="explicit"

    if [[ -z "$server_ip" ]] && command -v curl >/dev/null 2>&1; then
        server_ip="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
        if [[ ! "$server_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
            server_ip=""
        else
            source="observed public IPv4"
        fi
    fi

    local host addresses
    while IFS= read -r host; do
        [[ -n "$host" ]] || continue
        addresses="$(getent ahostsv4 "$host" 2>/dev/null | awk '{print $1}' | sort -u | paste -sd, -)"
        if [[ -z "$addresses" ]]; then
            audit_runtime_violation "dns.$host" "No IPv4 address resolved for required host [$host]."
            continue
        fi

        if [[ -n "$AUDIT_SERVER_IP" ]]; then
            if tr ',' '\n' <<<"$addresses" | grep -Fxq "$AUDIT_SERVER_IP"; then
                audit_result PASS "dns.$host" "Resolves to authoritative server IPv4 $AUDIT_SERVER_IP."
            else
                audit_result BREAKING "dns.$host" "Authoritative server IPv4 is $AUDIT_SERVER_IP; required host resolved to $addresses."
            fi
        elif [[ -n "$server_ip" ]]; then
            if tr ',' '\n' <<<"$addresses" | grep -Fxq "$server_ip"; then
                audit_result PASS "dns.$host" "Resolves to this server's $source $server_ip."
            else
                audit_result WARNING "dns.$host" \
                    "Resolved $addresses, while this server reports $server_ip. Proxy/NAT may be intentional; use --server-ip for an authoritative direct-DNS comparison."
            fi
        else
            audit_result "MANUAL VERIFICATION REQUIRED" "dns.$host" \
                "Resolved IPv4: $addresses. Supply --server-ip to compare against an authoritative direct-DNS target."
        fi
    done < <(audit_core_hosts)
}

audit_runtime_directories() {
    note "Runtime directory access"
    local relative path owner group mode

    for relative in storage storage/logs bootstrap/cache; do
        path="$APP_PATH/$relative"
        if [[ ! -d "$path" ]]; then
            audit_runtime_violation "runtime.$relative" "Required runtime directory is missing: $path"
            continue
        fi

        if sudo -u "$DEPLOY_USER" test -w "$path" && sudo -u "$WEB_USER" test -w "$path"; then
            audit_result PASS "runtime.$relative.write" "$DEPLOY_USER and $WEB_USER both have effective write access."
        elif [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
            audit_result INFO "runtime.$relative.write" \
                "The inactive Engage Core checkout is not yet writable by both $DEPLOY_USER and $WEB_USER. This is cutover readiness only."
        else
            audit_result BREAKING "runtime.$relative.write" \
                "The active runtime requires both $DEPLOY_USER and $WEB_USER to have effective write access."
        fi

        owner="$(stat -c '%U' "$path")"
        group="$(stat -c '%G' "$path")"
        mode="$(stat -c '%a' "$path")"
        if [[ "$owner" == "$DEPLOY_USER" && "$group" == "$WEB_GROUP" && "$mode" == "2775" ]]; then
            audit_result PASS "runtime.$relative.metadata" "Matches canonical $DEPLOY_USER:$WEB_GROUP 2775."
        else
            audit_result INFO "runtime.$relative.metadata" \
                "Metadata differs from new-deployment convention $DEPLOY_USER:$WEB_GROUP 2775; found $owner:$group $mode. Effective access is authoritative."
        fi
    done
}

audit_supervisor() {
    note "Supervisor / Horizon"
    local canonical="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local config=""
    local -a candidates=()
    local horizon_process=false

    if ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null; then
        horizon_process=true
        audit_result PASS horizon.process "A Horizon process is running from $APP_PATH."
    else
        audit_result BREAKING horizon.process "No Horizon process was found for the active audited checkout $APP_PATH."
    fi

    if [[ -f "$canonical" ]]; then
        config="$canonical"
    else
        local path
        for path in /etc/supervisor/conf.d/*.conf; do
            [[ -f "$path" ]] || continue
            if grep -Fq "$APP_PATH/artisan horizon" "$path" 2>/dev/null; then
                candidates+=("$path")
            fi
        done
        if [[ ${#candidates[@]} -eq 1 ]]; then
            config="${candidates[0]}"
        fi
    fi

    if [[ -z "$config" ]]; then
        if [[ "$horizon_process" == "true" ]]; then
            audit_result WARNING supervisor.config                 "Horizon is running from the correct checkout, but no matching Supervisor config was found. An alternative process manager may be intentional; do not auto-install Supervisor without confirming ownership."
        else
            audit_result WARNING supervisor.config                 "No matching Supervisor config was found. The BREAKING horizon.process finding is the functional failure; process-manager choice remains a separate remediation decision."
        fi
        audit_app_command horizon.status "$PHP_BIN" artisan horizon:status
        return
    fi

    if [[ "$config" == "$canonical" ]]; then
        audit_result PASS supervisor.config "Canonical config exists at $canonical."
    else
        audit_result INFO supervisor.config "Supervisor config differs from new-deployment filename [$canonical]; found [$config]. Functional process ownership is authoritative."
    fi

    local program directory user command
    program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
    directory="$(sed -n 's/^directory=//p' "$config" | head -n 1)"
    user="$(sed -n 's/^user=//p' "$config" | head -n 1)"
    command="$(sed -n 's/^command=//p' "$config" | head -n 1)"

    if [[ "$program" == "$HORIZON_PROGRAM" ]]; then
        audit_result PASS supervisor.program "Program name is $program."
    else
        audit_result INFO supervisor.program "Supervisor program name differs from new-deployment convention [$HORIZON_PROGRAM]; found [${program:-missing}]. Do not rename a healthy process for consistency."
    fi

    if [[ "$directory" == "$APP_PATH" && "$command" == *"$APP_PATH/artisan horizon"* ]]; then
        audit_result PASS supervisor.path "Supervisor command/directory point at the audited checkout."
    else
        audit_result WARNING supervisor.path "Supervisor config does not consistently point at $APP_PATH. Actual running process ownership is authoritative; do not rewrite a working process manager solely from config drift."
    fi

    if [[ "$user" == "$WEB_USER" ]]; then
        audit_result PASS supervisor.user "Horizon runs as $WEB_USER."
    else
        audit_result INFO supervisor.user "Horizon runs as [${user:-missing}] rather than new-deployment default [$WEB_USER]. Effective runtime access is authoritative."
    fi

    local status_output status_code
    set +e
    status_output="$(sudo supervisorctl status "${program:-$HORIZON_PROGRAM}" 2>&1)"
    status_code=$?
    set -e
    if [[ "$status_code" -eq 0 && "$status_output" == *"RUNNING"* ]]; then
        audit_result PASS supervisor.running "$status_output"
    elif [[ "$horizon_process" == "true" ]]; then
        audit_result WARNING supervisor.running "${status_output:-Supervisor does not report the program as running, but a Horizon process is active from the correct checkout.}"
    else
        audit_result WARNING supervisor.running "${status_output:-Supervisor program is not running.}"
    fi

    audit_app_command horizon.status "$PHP_BIN" artisan horizon:status
}

audit_scheduler() {
    note "Laravel Scheduler"
    local cron expected
    cron="$(sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null || true)"
    expected="cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run"

    if printf '%s\n' "$cron" | grep -F "$expected" | grep -Fq "# ${SCHEDULER_MARKER}"; then
        audit_result PASS scheduler.cron "Canonical Scheduler cron entry and marker are present for $SCHEDULER_USER."
    elif printf '%s\n' "$cron" | grep -Fq "$APP_PATH" && printf '%s\n' "$cron" | grep -Fq "artisan schedule:run"; then
        audit_result INFO scheduler.cron \
            "A Scheduler entry exists for this checkout but differs from new-deployment command/marker [${SCHEDULER_MARKER}]. Functional scheduling is authoritative."
    else
        audit_result BREAKING scheduler.cron "No Scheduler cron entry was found for the active audited checkout $APP_PATH under $SCHEDULER_USER."
    fi

    audit_app_command scheduler.list "$PHP_BIN" artisan schedule:list
}

audit_database_and_namespaces() {
    note "Database / Redis identity"
    local db_host db_name db_user db_password
    db_host="$(env_get "$ROOT_ENV" DB_HOST)"
    db_name="$(env_get "$CLIENT_ENV" DB_DATABASE)"
    db_user="$(env_get "$CLIENT_ENV" DB_USERNAME)"
    db_password="$(env_get "$CLIENT_ENV" DB_PASSWORD)"

    if [[ "$DEPLOY_ENV" == "staging" ]]; then
        if [[ "$db_host" == "127.0.0.1" || "$db_host" == "localhost" ]]; then
            audit_result PASS database.topology "Core staging uses a local database host [$db_host]."
        else
            audit_result WARNING database.topology "New Core staging deployments use local MySQL; found DB_HOST [${db_host:-blank}]. A functional existing topology is not normalized automatically."
        fi
    else
        if [[ -n "$db_host" && "$db_host" != "127.0.0.1" && "$db_host" != "localhost" ]]; then
            audit_result PASS database.topology "Core production uses remote database host [$db_host]."
        else
            audit_result WARNING database.topology "New Core production deployments use a remote database; found DB_HOST [${db_host:-blank}]. Review deliberately, but do not auto-migrate a functional database topology."
        fi
    fi

    audit_canonical_drift "$db_name" "$DB_DATABASE_DERIVED" database.name DB_DATABASE
    audit_canonical_drift "$db_user" "$DB_USERNAME_DERIVED" database.user DB_USERNAME

    if [[ -n "$db_password" ]]; then
        audit_result PASS database.password "DB_PASSWORD is populated; value not displayed."
    else
        audit_runtime_violation database.password "DB_PASSWORD is blank."
    fi

    audit_canonical_drift "$(env_get "$CLIENT_ENV" CACHE_PREFIX)" "$CACHE_PREFIX" redis.cache_prefix CACHE_PREFIX
    audit_canonical_drift "$(env_get "$CLIENT_ENV" REDIS_PREFIX)" "$REDIS_PREFIX" redis.prefix REDIS_PREFIX
    audit_canonical_drift "$(env_get "$CLIENT_ENV" HORIZON_PREFIX)" "$HORIZON_PREFIX" redis.horizon_prefix HORIZON_PREFIX

    local cache_value redis_value horizon_value
    cache_value="$(env_get "$CLIENT_ENV" CACHE_PREFIX)"
    redis_value="$(env_get "$CLIENT_ENV" REDIS_PREFIX)"
    horizon_value="$(env_get "$CLIENT_ENV" HORIZON_PREFIX)"

    local -a collision_args=(
        python3 "$HELPER" env-collision-map
        --search-root /var/www
        --exclude "$CLIENT_ENV"
    )
    [[ -n "$cache_value" ]] && collision_args+=(--query "CACHE_PREFIX=$cache_value")
    [[ -n "$redis_value" ]] && collision_args+=(--query "REDIS_PREFIX=$redis_value")
    [[ -n "$horizon_value" ]] && collision_args+=(--query "HORIZON_PREFIX=$horizon_value")

    local collision_json
    collision_json="$("${collision_args[@]}" 2>/dev/null || printf '{}')"
    audit_prefix_collision_from_map "$collision_json" CACHE_PREFIX "$cache_value" redis.cache_collision
    audit_prefix_collision_from_map "$collision_json" REDIS_PREFIX "$redis_value" redis.redis_collision
    audit_prefix_collision_from_map "$collision_json" HORIZON_PREFIX "$horizon_value" redis.horizon_collision

    audit_env_defaulted "$ROOT_ENV" REDIS_HOST 127.0.0.1 redis.host
    audit_env_defaulted "$ROOT_ENV" REDIS_DB 0 redis.database
    audit_env_defaulted "$ROOT_ENV" REDIS_CACHE_DB 1 redis.cache_database
}

audit_resolve_public_hosts() {
    local crm_url discovered_crm
    crm_url="$(env_get "$CLIENT_ENV" CRM_APP_URL)"

    if [[ -n "$AUDIT_CRM_HOST" ]]; then
        CRM_HOST="$(python3 "$HELPER" origin-host --origin "https://$AUDIT_CRM_HOST" 2>/dev/null || true)"
        if [[ -z "$CRM_HOST" ]]; then
            audit_result WARNING env.crm_host "Provided --crm-host [$AUDIT_CRM_HOST] is invalid; using the derived CRM hostname for inspection."
            CRM_HOST="$(audit_identity_value crm_host)"
        fi
    elif [[ -n "$crm_url" ]]; then
        discovered_crm="$(python3 "$HELPER" origin-host --origin "$crm_url" 2>/dev/null || true)"
        CRM_HOST="${discovered_crm:-$(audit_identity_value crm_host)}"
    else
        CRM_HOST="$(audit_identity_value crm_host)"
    fi

    WEBHOOKS_HOST="$(audit_identity_value webhooks_host)"
    WEBINAR_HOST="$(audit_identity_value webinar_host)"
    MESSAGING_HOST="$(audit_identity_value messaging_host)"
}

audit_env_origin() {
    local file="$1"
    local env_key="$2"
    local expected_host="$3"
    local result_key="$4"
    local actual actual_host

    actual="$(env_get "$file" "$env_key")"
    if [[ -z "$actual" ]]; then
        audit_runtime_violation "$result_key" "$env_key is blank or missing."
        return
    fi

    actual_host="$(python3 "$HELPER" origin-host --origin "$actual" 2>/dev/null || true)"
    if [[ -z "$actual_host" ]]; then
        audit_runtime_violation "$result_key" "$env_key must be a valid http:// or https:// origin; found [$actual]."
        return
    fi

    if [[ "$actual_host" == "$expected_host" ]]; then
        audit_result PASS "$result_key" "$env_key is a valid origin for [$actual_host]."
    else
        audit_result INFO "$result_key" "$env_key is a valid origin for [$actual_host], while new deployments derive [$expected_host]. Functional host ownership is authoritative."
    fi
}

audit_environment_identity() {
    note "Environment / client identity"
    audit_env_file_metadata "$ROOT_ENV" env.root
    audit_env_file_metadata "$CLIENT_ENV" env.client

    [[ -f "$ROOT_ENV" && -f "$CLIENT_ENV" ]] || return 0

    audit_env_equals "$ROOT_ENV" APP_ENV "$DEPLOY_ENV" env.app_env
    audit_env_equals "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY" env.client_key
    audit_env_present_redacted "$ROOT_ENV" APP_KEY env.app_key
    audit_env_equals "$CLIENT_ENV" ROOT_DOMAIN "$ROOT_DOMAIN" env.root_domain
    audit_env_origin "$CLIENT_ENV" APP_URL "$ROOT_DOMAIN" env.app_url
    audit_env_origin "$CLIENT_ENV" CRM_APP_URL "$CRM_HOST" env.crm_app_url
}

run_audit() {
    audit_reset
    require_command python3
    require_command git
    require_command stat
    require_command find
    require_command getent
    require_command sudo
    require_command awk
    require_command grep

    AUDIT_IDENTITY_JSON="$(python3 "$HELPER" derive-audit \
        --environment "$DEPLOY_ENV" \
        --client-key "$CLIENT_KEY" \
        --root-domain "$ROOT_DOMAIN")"

    CANONICAL_APP_PATH="$(audit_identity_value app_path)"
    DB_DATABASE_DERIVED="$(audit_identity_value database_name)"
    DB_USERNAME_DERIVED="$(audit_identity_value database_user)"
    CACHE_PREFIX="$(audit_identity_value cache_prefix)"
    REDIS_PREFIX="$(audit_identity_value redis_prefix)"
    HORIZON_PREFIX="$(audit_identity_value horizon_prefix)"
    HORIZON_PROGRAM="$(audit_identity_value horizon_program)"
    NGINX_SITE_NAME="$(audit_identity_value nginx_site_name)"
    SCHEDULER_MARKER="$(audit_identity_value scheduler_marker)"

    local discovery_status=0
    set +e
    APP_PATH="$(audit_find_app_path "$AUDIT_APP_PATH" "$CANONICAL_APP_PATH")"
    discovery_status=$?
    set -e

    if [[ "$discovery_status" -ne 0 || -z "$APP_PATH" ]]; then
        if [[ "$discovery_status" -eq 2 ]]; then
            audit_result "MANUAL VERIFICATION REQUIRED" deployment.app_path.discovery \
                "Multiple matching checkouts exist. Rerun audit with --app-path; do not remediate until the intended checkout is identified."
        else
            audit_result "MANUAL VERIFICATION REQUIRED" deployment.app_path.discovery \
                "No Core checkout containing client [$CLIENT_KEY] was found. Rerun with --app-path if it is outside /var/www."
        fi
        audit_summary
        return 1
    fi

    if [[ "$APP_PATH" == "$CANONICAL_APP_PATH" ]]; then
        audit_result PASS deployment.app_path "Checkout is at canonical path $CANONICAL_APP_PATH."
    else
        audit_result INFO deployment.app_path \
            "Legacy path drift only: new deployments use $CANONICAL_APP_PATH; audited checkout is at $APP_PATH. Do not relocate a functional deployment for consistency."
    fi

    CLIENT_PATH="$APP_PATH/client/$CLIENT_KEY"
    ROOT_ENV="$APP_PATH/.env"
    CLIENT_ENV="$CLIENT_PATH/.env"
    DEPLOY_USER="$AUDIT_DEPLOY_USER"
    WEB_USER="$AUDIT_WEB_USER"
    WEB_GROUP="$AUDIT_WEB_GROUP"
    SCHEDULER_USER="$AUDIT_SCHEDULER_USER"

    PHP_BIN="$(command -v php || true)"
    if [[ -z "$PHP_BIN" ]]; then
        audit_result BREAKING runtime.php "PHP is not installed or not on PATH; the audited Core runtime cannot execute."
        audit_summary
        return 1
    fi
    PHP_VERSION="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"

    if id "$DEPLOY_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.deploy_user "Deploy user [$DEPLOY_USER] exists."
    else
        audit_result WARNING runtime.deploy_user "Deploy user [$DEPLOY_USER] does not exist; verify audit identity before remediation."
    fi
    if id "$WEB_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.web_user "Web user [$WEB_USER] exists."
    else
        audit_result WARNING runtime.web_user "Web user [$WEB_USER] does not exist; verify audit identity before remediation."
    fi
    if getent group "$WEB_GROUP" >/dev/null 2>&1; then
        audit_result PASS runtime.web_group "Web group [$WEB_GROUP] exists."
    else
        audit_result WARNING runtime.web_group "Web group [$WEB_GROUP] does not exist; verify audit identity before remediation."
    fi
    if id "$SCHEDULER_USER" >/dev/null 2>&1; then
        audit_result PASS runtime.scheduler_user "Scheduler user [$SCHEDULER_USER] exists."
    else
        audit_result WARNING runtime.scheduler_user "Scheduler user [$SCHEDULER_USER] does not exist; verify audit identity before remediation."
    fi

    note "Repository state"
    local expected_core_origin
    expected_core_origin="$(git -C "$BUNDLE_ROOT" remote get-url origin 2>/dev/null || true)"
    audit_git_repo "$APP_PATH" source.core "$AUDIT_CORE_BRANCH" "$expected_core_origin"
    audit_git_repo "$CLIENT_PATH" source.client "$AUDIT_CLIENT_BRANCH" "$AUDIT_CLIENT_REPO"

    if [[ "$AUDIT_SOURCE_CURRENT" != "true" ]]; then
        echo
        echo "Authoritative runtime audit deferred: Core and client source must be clean and match their configured remote branches first."
        audit_summary || true
        return 1
    fi

    if [[ ! -f "$ROOT_ENV" || ! -f "$CLIENT_ENV" ]]; then
        audit_environment_identity
        audit_summary
        return 1
    fi

    audit_resolve_public_hosts
    audit_load_nginx_dump || true
    audit_detect_active_host_owner
    audit_environment_identity
    audit_composer_dependencies
    audit_client_environment_contract
    audit_database_and_namespaces

    note "Application deployment contract"
    AUDIT_PLAN_JSON=""
    if [[ "$AUDIT_APP_COMMANDS_AVAILABLE" == "true" && "$AUDIT_APP_BOOTSTRAP_READY" == "true" ]]; then
        audit_resolve_plan
        audit_app_command modules.status "$PHP_BIN" artisan modules:status
        audit_module_migrations
        audit_app_command setup.validate "$PHP_BIN" artisan setup:validate
    elif [[ "$AUDIT_APP_COMMANDS_AVAILABLE" != "true" ]]; then
        audit_result INFO deployment_plan.unavailable \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO modules.status \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
        audit_result INFO setup.validate \
            "Not evaluated because Composer runtime dependencies are unavailable in the audited checkout."
    else
        audit_result INFO deployment_plan.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO modules.status \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO module_migrations.unavailable \
            "Not evaluated because the early environment/bootstrap contract is already failing."
        audit_result INFO setup.validate \
            "Not evaluated because the early environment/bootstrap contract is already failing."
    fi

    audit_runtime_directories

    if [[ "$AUDIT_CHECKOUT_ACTIVE" == "false" ]]; then
        note "Supervisor / Horizon"
        audit_result INFO supervisor.audit_scope \
            "Not evaluated as a live Engage Core service because CRM traffic is currently owned by [${AUDIT_ACTIVE_APP_PATH:-another checkout}]."

        note "Laravel Scheduler"
        audit_result INFO scheduler.audit_scope \
            "Not evaluated as a live Engage Core service because the audited checkout is not the current CRM host owner."

        note "Nginx / TLS"
        audit_result INFO nginx.audit_scope \
            "Core-specific Nginx/TLS reconciliation is deferred until cutover; the current CRM host is intentionally reported under deployment.crm_host_owner."
    else
        audit_supervisor
        audit_scheduler
        audit_nginx
    fi

    audit_dns
    audit_http_runtime

    audit_summary
}

parse_audit() {
    local environment=""
    local client_key=""
    local root_domain=""
    local app_path=""
    local client_repo=""
    local crm_host=""
    local core_branch="main"
    local client_branch="main"
    local deploy_user
    deploy_user="$(id -un)"
    local web_user="www-data"
    local web_group="www-data"
    local scheduler_user=""
    local server_ip=""

    while (($#)); do
        case "$1" in
            --environment) environment=${2:?}; shift 2 ;;
            --client-key) client_key=${2:?}; shift 2 ;;
            --root-domain) root_domain=${2:?}; shift 2 ;;
            --app-path) app_path=${2:?}; shift 2 ;;
            --client-repo) client_repo=${2:?}; shift 2 ;;
            --crm-host) crm_host=${2:?}; shift 2 ;;
            --core-branch) core_branch=${2:?}; shift 2 ;;
            --client-branch) client_branch=${2:?}; shift 2 ;;
            --deploy-user) deploy_user=${2:?}; shift 2 ;;
            --web-user) web_user=${2:?}; shift 2 ;;
            --web-group) web_group=${2:?}; shift 2 ;;
            --scheduler-user) scheduler_user=${2:?}; shift 2 ;;
            --server-ip) server_ip=${2:?}; shift 2 ;;
            -h|--help) usage; return 0 ;;
            *) fail "Unknown audit argument: $1" ;;
        esac
    done

    [[ "$environment" == "staging" || "$environment" == "production" ]] \
        || fail "audit requires --environment staging|production."
    [[ -n "$client_key" ]] || fail "audit requires --client-key."
    [[ -n "$root_domain" ]] || fail "audit requires --root-domain."
    [[ "$client_key" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail "Invalid --client-key [$client_key]."

    DEPLOY_ENV="$environment"
    CLIENT_KEY="$client_key"
    ROOT_DOMAIN="$(python3 "$HELPER" derive-audit \
        --environment "$environment" \
        --client-key "$client_key" \
        --root-domain "$root_domain" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["root_domain"])')"

    AUDIT_APP_PATH="$app_path"
    AUDIT_CLIENT_REPO="$client_repo"
    AUDIT_CRM_HOST="$crm_host"
    AUDIT_CORE_BRANCH="$core_branch"
    AUDIT_CLIENT_BRANCH="$client_branch"
    AUDIT_DEPLOY_USER="$deploy_user"
    AUDIT_WEB_USER="$web_user"
    AUDIT_WEB_GROUP="$web_group"
    AUDIT_SCHEDULER_USER="${scheduler_user:-$deploy_user}"
    AUDIT_SERVER_IP="$server_ip"

    run_audit
}

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1"
}

verify_runtime() {
    note "Final Core verification"
    require_command curl
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract
    write_plan_json || true

    local blocking
    blocking="$(python3 "$HELPER" plan-blocking-count --plan "$PLAN_FILE")"
    [[ "$blocking" == "0" ]] || fail "Deployment plan has $blocking blocking environment requirement(s)."

    "$PHP_BIN" artisan modules:status
    "$PHP_BIN" artisan setup:validate
    sudo supervisorctl status "$HORIZON_PROGRAM"
    "$PHP_BIN" artisan horizon:status
    "$PHP_BIN" artisan schedule:list

    local admin_status
    admin_status="$(http_status "https://$CRM_HOST/")"
    case "$admin_status" in
        2??|3??|401|403) echo "CRM root HTTP $admin_status" ;;
        *) fail "CRM root returned HTTP $admin_status" ;;
    esac

    local login_status
    login_status="$(http_status "https://$CRM_HOST/login")"
    case "$login_status" in
        2??|3??|401|403) echo "CRM login HTTP $login_status" ;;
        *) fail "CRM login returned HTTP $login_status" ;;
    esac

    echo
    echo "External/provider verification remains intentionally human-observed where the provider dashboard or a real message/event is authoritative."
    echo "Review the verification items shown during each deployment setup step."
    echo "State file: $STATE_FILE"
    echo "Deployment plan: $PLAN_FILE"
}

audit_has_breaking() {
    local expected="$1"
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == "$expected" ]] && return 0
    done
    return 1
}

audit_has_breaking_prefix() {
    local prefix="$1"
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == "$prefix"* ]] && return 0
    done
    return 1
}

fix_category_selected() {
    local category="$1"
    [[ ",${FIX_CATEGORIES}," == *",${category},"* ]]
}

fix_safe_nginx_missing_hosts() {
    [[ "${AUDIT_NGINX_DUMP_LOADED:-false}" == "true" ]] || return 0
    audit_has_breaking nginx.syntax && return 0

    local key host owners_json count
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        [[ "$key" == nginx.host.* ]] || continue
        host="${key#nginx.host.}"
        owners_json="$(audit_nginx_owners_json "$host")"
        count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"
        [[ "$count" -eq 0 ]] && printf '%s\n' "$host"
    done | awk 'NF && !seen[$0]++'
}

fix_schema_modules() {
    [[ -n "$AUDIT_MODULE_STATUS_JSON" ]] || return 0
    printf '%s' "$AUDIT_MODULE_STATUS_JSON" | python3 "$HELPER" module-status-fix-modules
}

fix_schema_repairable() {
    [[ -n "$AUDIT_MODULE_STATUS_JSON" ]]         && audit_has_breaking_prefix module_migrations.
}

fix_schema_plan() {
    local -a modules=()
    mapfile -t modules < <(fix_schema_modules)

    echo "  [schema] cd $APP_PATH"
    echo "           $PHP_BIN artisan migrate --force"
    local module
    for module in "${modules[@]}"; do
        echo "           $PHP_BIN artisan modules:install $module --force"
    done
    echo "           $PHP_BIN artisan presets:sync"
    echo "           $PHP_BIN artisan modules:status"
    echo "           then re-evaluate setup:validate before runtime workers are eligible."
}

fix_apply_schema() {
    if [[ -z "$AUDIT_MODULE_STATUS_JSON" ]]; then
        echo "ERROR: Module migration audit data is unavailable; refusing automatic schema repair." >&2
        return 1
    fi

    local modules_output
    if ! modules_output="$(fix_schema_modules)"; then
        echo "ERROR: Unable to derive the enabled schema repair list from audit data." >&2
        return 1
    fi

    local -a modules=()
    if [[ -n "$modules_output" ]]; then
        mapfile -t modules <<<"$modules_output"
    fi

    note "Fix: platform and enabled module schema"

    cd "$APP_PATH" || return 1
    "$PHP_BIN" artisan migrate --force || return 1

    local module
    for module in "${modules[@]}"; do
        "$PHP_BIN" artisan modules:install "$module" --force || return 1
    done

    "$PHP_BIN" artisan presets:sync || return 1
    "$PHP_BIN" artisan modules:status || return 1
}

fix_runtime_plan() {
    echo "  [runtime] chown $DEPLOY_USER:$WEB_GROUP under $APP_PATH/storage and $APP_PATH/bootstrap/cache"
    echo "            directories -> 2775; files -> 0664; then verify deploy/web effective writes."
}

fix_apply_runtime() {
    note "Fix: runtime directory permissions"

    cd "$APP_PATH" || return 1
    sudo chown -R "$DEPLOY_USER:$WEB_GROUP" storage bootstrap/cache || return 1
    sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \; || return 1
    sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \; || return 1

    local log_dir="$APP_PATH/storage/logs"
    local deploy_probe="$log_dir/.engage-permission-deploy-fix-$$"
    local web_probe="$log_dir/.engage-permission-web-fix-$$"

    if ! sudo -u "$DEPLOY_USER" sh -c 'printf "deploy-created\n" > "$1"' sh "$deploy_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi
    if ! sudo -u "$WEB_USER" sh -c 'printf "web-updated\n" >> "$1"' sh "$deploy_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi
    if ! sudo -u "$WEB_USER" sh -c 'printf "web-created\n" > "$1"' sh "$web_probe"; then
        sudo rm -f "$deploy_probe" "$web_probe" || true
        return 1
    fi

    sudo rm -f "$deploy_probe" "$web_probe" || return 1
}

fix_host_dns_ready() {
    local host="$1"
    local expected="$AUDIT_SERVER_IP"

    if [[ -z "$expected" ]] && command -v curl >/dev/null 2>&1; then
        expected="$(curl -fsS --max-time 4 https://api.ipify.org 2>/dev/null || true)"
        [[ "$expected" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || expected=""
    fi

    [[ -n "$expected" ]] || return 1
    dns_host_ready "$host" "$expected"
}

fix_supplemental_nginx_id() {
    local host="$1"
    printf 'engage-core-%s' "${host//./-}"
}

fix_render_supplemental_nginx() {
    local host="$1"
    local cert_name="$2"
    local destination="$3"
    local tls_enabled="$4"
    local id access_log error_log
    id="$(fix_supplemental_nginx_id "$host")"
    access_log="/var/log/nginx/${id}-access.log"
    error_log="/var/log/nginx/${id}-error.log"

    local log_format_suffix=""
    local request_header=""
    local fastcgi_request_id=""
    if [[ -f /etc/nginx/conf.d/00-engage-core-observability.conf ]]; then
        log_format_suffix=" engage_core_json"
    fi
    if [[ -f /etc/nginx/snippets/engage-core-request-id-fastcgi.conf ]]; then
        request_header='    add_header X-Request-ID $request_id always;'
        fastcgi_request_id='        include /etc/nginx/snippets/engage-core-request-id-fastcgi.conf;'
    fi

    {
        echo "# Managed by Engage Core deployment fix: supplemental Core host."
        echo "server {"
        echo "    listen 80;"
        echo "    listen [::]:80;"
        echo "    server_name ${host};"
        echo "    root ${APP_PATH}/public;"
        echo "    index index.php;"
        echo "    charset utf-8;"
        [[ -n "$request_header" ]] && echo "$request_header"
        echo
        echo "    access_log ${access_log}${log_format_suffix};"
        echo "    error_log ${error_log};"
        echo
        echo "    location ^~ /.well-known/acme-challenge/ {"
        echo '        try_files $uri =404;'
        echo "    }"
        echo

        if [[ "$tls_enabled" == "true" ]]; then
            echo "    location / {"
            echo '        return 301 https://$host$request_uri;'
            echo "    }"
        else
            echo "    location / {"
            echo '        try_files $uri $uri/ /index.php?$query_string;'
            echo "    }"
            echo
            echo '    location ~ \.php$ {'
            echo "        include snippets/fastcgi-php.conf;"
            [[ -n "$fastcgi_request_id" ]] && echo "$fastcgi_request_id"
            echo "        fastcgi_pass unix:${PHP_FPM_SOCKET};"
            echo "    }"
            echo
            echo '    location ~ /\.(?!well-known).* {'
            echo "        deny all;"
            echo "    }"
        fi
        echo "}"

        if [[ "$tls_enabled" == "true" ]]; then
            cat <<EOF

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name ${host};
    root ${APP_PATH}/public;
    index index.php;
    charset utf-8;
${request_header}

    ssl_certificate /etc/letsencrypt/live/${cert_name}/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/${cert_name}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;

    access_log ${access_log}${log_format_suffix};
    error_log ${error_log};

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
${fastcgi_request_id}
        fastcgi_pass unix:${PHP_FPM_SOCKET};
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
        fi
    } > "$destination"
}

fix_nginx_plan() {
    local -a hosts=()
    mapfile -t hosts < <(fix_safe_nginx_missing_hosts)
    local host id

    for host in "${hosts[@]}"; do
        id="$(fix_supplemental_nginx_id "$host")"
        echo "  [nginx] Add missing Core host [$host] without rewriting the existing shared/legacy site."
        echo "          Write/enable: /etc/nginx/sites-available/$id -> /etc/nginx/sites-enabled/$id"
        echo "          Validate/reload Nginx, issue dedicated Certbot certificate [$id] for [$host], render HTTPS, validate/reload again."
    done
}

fix_install_certbot_reload_hook() {
    local hook='/etc/letsencrypt/renewal-hooks/deploy/engage-nginx-reload.sh'
    local tmp
    tmp="$(mktemp)" || return 1

    if ! cat > "$tmp" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
nginx -t
systemctl reload nginx
EOF
    then
        rm -f "$tmp"
        return 1
    fi

    if ! sudo install -d -o root -g root -m 0755 /etc/letsencrypt/renewal-hooks/deploy; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0755 "$tmp" "$hook"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"
}

fix_apply_nginx_host() {
    local host="$1"
    local id available enabled cert_name tmp
    id="$(fix_supplemental_nginx_id "$host")"
    available="/etc/nginx/sites-available/$id"
    enabled="/etc/nginx/sites-enabled/$id"
    cert_name="$id"

    local owners_json count
    owners_json="$(audit_nginx_owners_json "$host")"
    count="$(printf '%s' "$owners_json" | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' 2>/dev/null || printf '0')"
    if [[ "$count" -ne 0 ]]; then
        echo "ERROR: Refusing Nginx auto-repair for [$host]: an application-serving owner now exists. Re-audit first." >&2
        return 1
    fi

    if [[ -f "$available" ]] && ! grep -Fq '# Managed by Engage Core deployment fix: supplemental Core host.' "$available"; then
        echo "ERROR: Refusing to overwrite unmanaged Nginx config [$available]." >&2
        return 1
    fi

    if ! fix_host_dns_ready "$host"; then
        echo "ERROR: Refusing TLS repair for [$host]: DNS is not proven to resolve directly to this server." >&2
        return 1
    fi

    if ! command -v certbot >/dev/null 2>&1; then
        echo "ERROR: Certbot is not installed; automatic TLS repair is unavailable." >&2
        return 1
    fi

    note "Fix: Nginx/TLS supplemental host $host"

    tmp="$(mktemp)" || return 1
    if ! fix_render_supplemental_nginx "$host" "$cert_name" "$tmp" false; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0644 "$tmp" "$available"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo ln -sfn "$available" "$enabled" || return 1
    sudo nginx -t || return 1
    sudo systemctl reload nginx || return 1

    fix_install_certbot_reload_hook || return 1
    sudo certbot certonly --webroot \
        -w "$APP_PATH/public" \
        --non-interactive \
        --agree-tos \
        --keep-until-expiring \
        --cert-name "$cert_name" \
        -d "$host" || return 1

    tmp="$(mktemp)" || return 1
    if ! fix_render_supplemental_nginx "$host" "$cert_name" "$tmp" true; then
        rm -f "$tmp"
        return 1
    fi
    if ! sudo install -o root -g root -m 0644 "$tmp" "$available"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo nginx -t || return 1
    sudo systemctl reload nginx || return 1
}

fix_existing_supervisor_config() {
    local canonical="/etc/supervisor/conf.d/${HORIZON_PROGRAM}.conf"
    local -a candidates=()
    local path

    if [[ -f "$canonical" ]] && grep -Fq "$APP_PATH/artisan horizon" "$canonical" 2>/dev/null; then
        printf '%s\n' "$canonical"
        return 0
    fi

    for path in /etc/supervisor/conf.d/*.conf; do
        [[ -f "$path" ]] || continue
        if grep -Fq "$APP_PATH/artisan horizon" "$path" 2>/dev/null; then
            candidates+=("$path")
        fi
    done

    [[ ${#candidates[@]} -eq 1 ]] || return 1
    printf '%s\n' "${candidates[0]}"
}

fix_horizon_plan() {
    local config program
    config="$(fix_existing_supervisor_config 2>/dev/null || true)"
    if [[ -n "$config" ]]; then
        program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
        echo "  [horizon] After application readiness is green: supervisorctl reread/update/restart [${program:-unknown}] using existing [$config]."
    else
        echo "  [horizon] No single existing Supervisor config can be safely selected; automatic Horizon repair is not currently eligible."
    fi
}

fix_apply_horizon() {
    local config program
    config="$(fix_existing_supervisor_config 2>/dev/null || true)"
    if [[ -z "$config" ]]; then
        echo "ERROR: No single existing Supervisor config points at $APP_PATH; refusing to create/replace process-manager ownership automatically." >&2
        return 1
    fi

    program="$(sed -n 's/^\[program:\([^]]*\)\].*/\1/p' "$config" | head -n 1)"
    if [[ -z "$program" ]]; then
        echo "ERROR: Supervisor config [$config] does not declare a program name." >&2
        return 1
    fi

    note "Fix: Horizon via existing Supervisor program $program"
    sudo supervisorctl reread || return 1
    sudo supervisorctl update || return 1
    sudo supervisorctl restart "$program" || return 1
    sleep 1
    sudo supervisorctl status "$program" || return 1
    ps aux | grep '[a]rtisan horizon' | grep -F "$APP_PATH" >/dev/null \
        || return 1
}

fix_scheduler_plan() {
    echo "  [scheduler] After application readiness is green, install for [$SCHEDULER_USER]:"
    echo "              * * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
}

fix_apply_scheduler() {
    note "Fix: Laravel Scheduler"

    local line="* * * * * cd ${APP_PATH} && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1 # ${SCHEDULER_MARKER}"
    local tmp
    tmp="$(mktemp)" || return 1

    if ! sudo crontab -u "$SCHEDULER_USER" -l 2>/dev/null \
        | grep -Fv "# ${SCHEDULER_MARKER}" > "$tmp"
    then
        : > "$tmp"
    fi

    printf '%s\n' "$line" >> "$tmp" || {
        rm -f "$tmp"
        return 1
    }

    if ! sudo crontab -u "$SCHEDULER_USER" "$tmp"; then
        rm -f "$tmp"
        return 1
    fi
    rm -f "$tmp"

    sudo crontab -u "$SCHEDULER_USER" -l | grep -F "# ${SCHEDULER_MARKER}" || return 1
    (
        cd "$APP_PATH" || exit 1
        "$PHP_BIN" artisan schedule:list
    ) || return 1
}

fix_runtime_directories_ready() {
    local relative path
    for relative in storage storage/logs bootstrap/cache; do
        path="$APP_PATH/$relative"
        [[ -d "$path" ]] || return 1
        sudo -u "$DEPLOY_USER" test -w "$path" || return 1
        sudo -u "$WEB_USER" test -w "$path" || return 1
    done
}

fix_application_ready() {
    local plan output status

    set +e
    plan="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan engage:deployment-plan --json 2>/dev/null
    )"
    status=$?
    set -e
    [[ "$status" -eq 0 ]] || return 1
    printf '%s' "$plan" | python3 -m json.tool >/dev/null 2>&1 || return 1

    local blockers
    blockers="$(printf '%s' "$plan" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
blocking = 0
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"}:
        blocking += 1
    elif requirement == "required" and status in {"missing", "unresolved"}:
        blocking += 1
print(blocking)
')"
    [[ "$blockers" == "0" ]] || return 1

    set +e
    output="$(
        cd "$APP_PATH" &&
        "$PHP_BIN" artisan setup:validate >/dev/null 2>&1
    )"
    status=$?
    set -e

    [[ "$status" -eq 0 ]]
}

fix_manual_breaking_summary() {
    local key
    for key in "${AUDIT_BREAKING_KEYS[@]:-}"; do
        case "$key" in
            module_migrations.*)
                fix_category_selected schema || echo "  [not selected] $key"
                ;;
            runtime.storage.write|runtime.storage/logs.write|runtime.bootstrap/cache.write)
                fix_category_selected runtime || echo "  [not selected] $key"
                ;;
            nginx.host.*)
                if fix_category_selected nginx; then
                    local host="${key#nginx.host.}"
                    if ! fix_safe_nginx_missing_hosts | grep -Fxq "$host"; then
                        echo "  [manual] $key — Nginx ownership is ambiguous or already claimed; automatic repair is refused."
                    fi
                else
                    echo "  [not selected] $key"
                fi
                ;;
            tls.host.*)
                local tls_host="${key#tls.host.}"
                if fix_category_selected nginx && fix_safe_nginx_missing_hosts | grep -Fxq "$tls_host"; then
                    :
                else
                    echo "  [manual] $key — TLS-only or non-selected repair requires operator review."
                fi
                ;;
            horizon.process|horizon.status)
                if fix_category_selected horizon; then
                    if [[ -z "$(fix_existing_supervisor_config 2>/dev/null || true)" ]]; then
                        echo "  [manual] $key — no single existing Supervisor owner can be selected safely."
                    fi
                else
                    echo "  [not selected] $key"
                fi
                ;;
            scheduler.cron)
                fix_category_selected scheduler || echo "  [not selected] $key"
                ;;
            setup.validate)
                if fix_category_selected schema && fix_schema_repairable; then
                    echo "  [recheck] setup.validate — re-evaluate after schema repair."
                else
                    echo "  [manual] setup.validate — no registered deterministic prerequisite repair explains this failure."
                fi
                ;;
            deployment_plan.*|external_setup.*|dns.*)
                echo "  [manual] $key — external/provider/DNS values or verification are never invented by fix."
                ;;
            *)
                echo "  [manual] $key — no closed safe-remediation handler is registered."
                ;;
        esac
    done
}

fix_print_plan() {
    echo
    echo "Fix plan (${FIX_MODE})"
    echo "Selected safe categories: $FIX_CATEGORIES"

    local planned=false

    if fix_category_selected schema && fix_schema_repairable; then
        fix_schema_plan
        planned=true
    fi

    if fix_category_selected runtime && (
        audit_has_breaking runtime.storage.write \
        || audit_has_breaking runtime.storage/logs.write \
        || audit_has_breaking runtime.bootstrap/cache.write
    ); then
        fix_runtime_plan
        planned=true
    fi

    if fix_category_selected nginx && [[ -n "$(fix_safe_nginx_missing_hosts)" ]]; then
        fix_nginx_plan
        planned=true
    fi

    if fix_category_selected horizon && (
        audit_has_breaking horizon.process || audit_has_breaking horizon.status
    ); then
        fix_horizon_plan
        planned=true
    fi

    if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
        fix_scheduler_plan
        planned=true
    fi

    if [[ "$planned" == "false" ]]; then
        echo "  No registered safe repair is currently selected."
    fi

    echo
    echo "Breaking findings outside automatic application:"
    fix_manual_breaking_summary
}

fix_reaudit_after_failure() {
    echo
    echo "A safe repair step failed. Re-auditing the resulting state before stopping."
    set +e
    run_audit
    set -e
}

run_fix() {
    echo "== Fix precondition audit =="
    set +e
    run_audit
    local audit_status=$?
    set -e

    [[ "$AUDIT_SOURCE_CURRENT" == "true" ]] \
        || fail "Fix requires clean Core/client checkouts matching their configured remote branches. Source is never pulled automatically."

    [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" == "true" ]] \
        || fail "Fix requires the audited checkout to be the active CRM owner."

    fix_print_plan

    if [[ "$FIX_MODE" == "dry-run" ]]; then
        echo
        echo "Dry-run only. No deployment state was changed by fix."
        return 0
    fi

    if [[ "$DEPLOY_ENV" == "production" ]]; then
        local confirmation
        read -r -p "Type [$ROOT_DOMAIN] to apply safe production repairs: " confirmation
        [[ "$confirmation" == "$ROOT_DOMAIN" ]] \
            || fail "Production fix confirmation did not match the root domain."
    fi

    if fix_category_selected schema && fix_schema_repairable; then
        if ! fix_apply_schema; then
            fix_reaudit_after_failure
            return 1
        fi
    fi

    if fix_category_selected runtime && (
        audit_has_breaking runtime.storage.write \
        || audit_has_breaking runtime.storage/logs.write \
        || audit_has_breaking runtime.bootstrap/cache.write
    ); then
        if ! fix_apply_runtime; then
            fix_reaudit_after_failure
            return 1
        fi
    fi

    if fix_category_selected nginx; then
        local -a nginx_hosts=()
        mapfile -t nginx_hosts < <(fix_safe_nginx_missing_hosts)
        local host
        for host in "${nginx_hosts[@]}"; do
            if ! fix_apply_nginx_host "$host"; then
                fix_reaudit_after_failure
                return 1
            fi
        done
    fi

    local runtime_ready=false
    if fix_application_ready && fix_runtime_directories_ready; then
        runtime_ready=true
    fi

    if [[ "$runtime_ready" == "true" ]]; then
        if fix_category_selected horizon && (
            audit_has_breaking horizon.process || audit_has_breaking horizon.status
        ); then
            if ! fix_apply_horizon; then
                fix_reaudit_after_failure
                return 1
            fi
        fi

        if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
            if ! fix_apply_scheduler; then
                fix_reaudit_after_failure
                return 1
            fi
        fi
    else
        if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
            echo
            echo "Deferred Scheduler repair: deployment plan/setup validation or effective runtime-directory access is not green yet."
        fi
        if fix_category_selected horizon && (
            audit_has_breaking horizon.process || audit_has_breaking horizon.status
        ); then
            echo "Deferred Horizon repair: deployment plan/setup validation or effective runtime-directory access is not green yet."
        fi
    fi

    echo
    echo "== Mandatory post-fix audit =="
    set +e
    run_audit
    local final_status=$?
    set -e

    if [[ "$final_status" -eq 0 ]]; then
        echo "Safe repairs applied and re-audit is clean."
        return 0
    fi

    echo "Safe repairs applied. Re-audit still reports findings that require another selected safe pass or manual/external resolution."
    return 1
}

normalize_fix_categories() {
    local raw="$1"
    python3 -c '
import sys
aliases = {
    "schema": "schema",
    "runtime": "runtime",
    "nginx": "nginx",
    "tls": "nginx",
    "horizon": "horizon",
    "supervisor": "horizon",
    "scheduler": "scheduler",
    "cron": "scheduler",
}
raw = sys.argv[1]
selected = []
for item in raw.split(","):
    item = item.strip().lower()
    if not item:
        continue
    if item not in aliases:
        raise SystemExit(f"Unknown fix category [{item}].")
    canonical = aliases[item]
    if canonical not in selected:
        selected.append(canonical)
print(",".join(selected))
' "$raw"
}

parse_fix() {
    local environment=""
    local client_key=""
    local root_domain=""
    local app_path=""
    local client_repo=""
    local crm_host=""
    local core_branch="main"
    local client_branch="main"
    local deploy_user
    deploy_user="$(id -un)"
    local web_user="www-data"
    local web_group="www-data"
    local scheduler_user=""
    local server_ip=""
    local mode="dry-run"
    local only=""
    local all_safe=false

    while (($#)); do
        case "$1" in
            --environment) environment=${2:?}; shift 2 ;;
            --client-key) client_key=${2:?}; shift 2 ;;
            --root-domain) root_domain=${2:?}; shift 2 ;;
            --app-path) app_path=${2:?}; shift 2 ;;
            --client-repo) client_repo=${2:?}; shift 2 ;;
            --crm-host) crm_host=${2:?}; shift 2 ;;
            --core-branch) core_branch=${2:?}; shift 2 ;;
            --client-branch) client_branch=${2:?}; shift 2 ;;
            --deploy-user) deploy_user=${2:?}; shift 2 ;;
            --web-user) web_user=${2:?}; shift 2 ;;
            --web-group) web_group=${2:?}; shift 2 ;;
            --scheduler-user) scheduler_user=${2:?}; shift 2 ;;
            --server-ip) server_ip=${2:?}; shift 2 ;;
            --apply) mode="apply"; shift ;;
            --dry-run) mode="dry-run"; shift ;;
            --only) only=${2:?}; shift 2 ;;
            --all-safe) all_safe=true; shift ;;
            -h|--help) usage; return 0 ;;
            *) fail "Unknown fix argument: $1" ;;
        esac
    done

    [[ "$environment" == "staging" || "$environment" == "production" ]] \
        || fail "fix requires --environment staging|production."
    [[ -n "$client_key" ]] || fail "fix requires --client-key."
    [[ -n "$root_domain" ]] || fail "fix requires --root-domain."
    [[ "$client_key" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || fail "Invalid --client-key [$client_key]."
    [[ -z "$only" || "$all_safe" == "false" ]] \
        || fail "Use either --only or --all-safe, not both."

    DEPLOY_ENV="$environment"
    CLIENT_KEY="$client_key"
    ROOT_DOMAIN="$(python3 "$HELPER" derive-audit \
        --environment "$environment" \
        --client-key "$client_key" \
        --root-domain "$root_domain" \
        | python3 -c 'import json,sys; print(json.load(sys.stdin)["root_domain"])')"

    AUDIT_APP_PATH="$app_path"
    AUDIT_CLIENT_REPO="$client_repo"
    AUDIT_CRM_HOST="$crm_host"
    AUDIT_CORE_BRANCH="$core_branch"
    AUDIT_CLIENT_BRANCH="$client_branch"
    AUDIT_DEPLOY_USER="$deploy_user"
    AUDIT_WEB_USER="$web_user"
    AUDIT_WEB_GROUP="$web_group"
    AUDIT_SCHEDULER_USER="${scheduler_user:-$deploy_user}"
    AUDIT_SERVER_IP="$server_ip"

    FIX_MODE="$mode"
    if [[ -n "$only" ]]; then
        FIX_CATEGORIES="$(normalize_fix_categories "$only")" \
            || fail "Invalid --only category list [$only]."
        [[ -n "$FIX_CATEGORIES" ]] || fail "--only must select at least one fix category."
    else
        FIX_CATEGORIES="schema,runtime,nginx,horizon,scheduler"
    fi

    run_fix
}

run_new_or_resume() {
    ensure_source
    load_state
    ensure_environment
    load_state
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan
    install_new_environment
    ensure_runtime
    verify_runtime

    cat <<EOF

Deployment orchestration completed.
Client:      $CLIENT_KEY
Environment: $DEPLOY_ENV
Root domain: $ROOT_DOMAIN
Core path:   $APP_PATH
CRM:         https://$CRM_HOST
Horizon:     $HORIZON_PROGRAM

Main-site topology: $TOPOLOGY${MAIN_SITE_TYPE:+ ($MAIN_SITE_TYPE)}
The root site itself was not pointed at Core.
EOF
}

run_update() {
    ensure_source true
    load_state
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan true
    update_application
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron
    sudo systemctl reload "$PHP_FPM_SERVICE"
    verify_runtime
}

run_add_modules() {
    ensure_source true
    load_state
    prepare_host_plan
    install_nginx_site
    ensure_dns_and_tls
    resolve_deployment_plan true
    install_added_modules
    runtime_permission_setup
    install_supervisor_program
    install_scheduler_cron
    sudo systemctl reload "$PHP_FPM_SERVICE"
    verify_runtime
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
            write_plan_json || true
            verify_runtime
            ;;
        audit)
            parse_audit "$@"
            ;;
        fix)
            parse_fix "$@"
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

REQUESTED_MODULES=()
main "$@"
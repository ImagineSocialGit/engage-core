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
  launch-client-environment.sh derive --environment ENV --client-repo URL --root-domain DOMAIN [--client-key KEY]

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
    sudo chown "$DEPLOY_USER:$WEB_GROUP" "$ROOT_ENV" "$CLIENT_ENV"
    sudo chmod 640 "$ROOT_ENV" "$CLIENT_ENV"
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

ensure_environment() {
    if phase_done environment; then
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

    cd "$APP_PATH"
    if [[ -z "$(env_get "$ROOT_ENV" APP_KEY)" ]]; then
        note "Generate environment APP_KEY"
        "$PHP_BIN" artisan key:generate --force
    fi
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

    local one="storage/logs/.engage-permission-deploy-$$"
    local two="storage/logs/.engage-permission-web-$$"
    sudo -u "$DEPLOY_USER" sh -c "printf 'deploy-created\\n' > '$one'"
    sudo -u "$WEB_USER" sh -c "printf 'web-updated\\n' >> '$one'"
    sudo -u "$WEB_USER" sh -c "printf 'web-created\\n' > '$two'"
    sudo -u "$DEPLOY_USER" sh -c "printf 'deploy-updated\\n' >> '$two'"
    sudo rm -f "$one" "$two"
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

    local -a desired tls served pending
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
    listen 443 ssl;
    listen [::]:443 ssl;
    http2 on;
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
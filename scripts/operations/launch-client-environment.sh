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

# Creates only absent files; existing secrets are never chowned or chmodded
# implicitly during an update, resume, or verification pass.
provision_missing_env_files() {
    local path
    for path in "$ROOT_ENV" "$CLIENT_ENV"; do
        [[ ! -L "$path" ]] || fail "Refusing a symlinked environment file: $path"
        if [[ ! -e "$path" ]]; then
            sudo install -o "$DEPLOY_USER" -g "$WEB_GROUP" -m 0640 /dev/null "$path" \
                || fail "Could not provision restricted environment file: $path"
        fi
    done
    ensure_env_permissions
}

# A read-only gate. This function must never repair an environment file.
# The deploy and actual PHP identities must both read the file, while other
# users must not gain read/write access through mode bits or an extended ACL.
ensure_env_permissions() {
    local path mode permission_bits
    for path in "$ROOT_ENV" "$CLIENT_ENV"; do
        [[ -f "$path" && ! -L "$path" ]] || fail "Missing or symlinked environment file: $path"
        mode="$(stat -c '%a' "$path")" || fail "Cannot inspect environment permissions: $path"
        permission_bits=$((8#$mode))
        (( (permission_bits & 0007) == 0 && (permission_bits & 0020) == 0 )) \
            || fail "Unsafe environment-file permissions [$mode] on [$path]. Explicitly repair ownership/mode after verifying the actual runtime readers; do not use 0664."
        sudo -u "$DEPLOY_USER" test -r "$path" \
            || fail "Deploy user [$DEPLOY_USER] cannot read $path"
        sudo -u "$WEB_USER" test -r "$path" \
            || fail "PHP runtime user [$WEB_USER] cannot read $path"
        if id nobody >/dev/null 2>&1 && [[ "$DEPLOY_USER" != nobody && "$WEB_USER" != nobody ]]; then
            if sudo -n -u nobody true >/dev/null 2>&1; then
                if sudo -n -u nobody test -r "$path"; then
                    fail "Unrelated user [nobody] can read $path; check extended ACLs and parent directories."
                fi
            else
                fail "Cannot verify denial to unrelated user [nobody] for $path (sudo check unavailable)."
            fi
        fi
    done
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
        ensure_env_permissions
        ensure_application_key
        return
    fi

    note "Minimal runtime environment"
    mkdir -p "$APP_PATH" "$CLIENT_PATH"
    provision_missing_env_files

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

    local forms_url forms_host
    if module_enabled forms; then
        forms_url="$(env_get "$CLIENT_ENV" FORMS_APP_URL)"
        if [[ -n "$forms_url" ]]; then
            forms_host="$(python3 "$HELPER" origin-host --origin "$forms_url")"
            [[ -n "$forms_host" ]] && hosts+=("$forms_host")
        fi
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

http_status() {
    curl -sS -o /dev/null -w '%{http_code}' "$1"
}

verify_runtime() {
    note "Final Core verification"
    require_command curl
    cd "$APP_PATH"
    ensure_env_permissions
    validate_client_env_contract

    # Resolve directly through a pipe. No deployment-plan JSON or state-file
    # write is allowed in verify, even when required values are missing.
    local plan_json blocking
    plan_json="$("$PHP_BIN" artisan engage:deployment-plan --json)" || true
    [[ -n "$plan_json" ]] || fail "Read-only deployment-plan inspection returned no JSON."
    blocking="$(printf '%s' "$plan_json" | python3 "$HELPER" plan-blocking-count --plan -)" \
        || fail "Read-only deployment-plan JSON is invalid."
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
    echo "Deployment plan: inspected in memory (no file written)"
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

fix_confirm_mutation_once() {
    if [[ "$DEPLOY_ENV" != "production" ]]; then
        return 0
    fi

    if [[ "${FIX_PRODUCTION_CONFIRMED:-false}" == "true" ]]; then
        return 0
    fi

    local confirmation
    read -r -p "Type [$ROOT_DOMAIN] to allow this guided/automatic production mutation: " confirmation
    [[ "$confirmation" == "$ROOT_DOMAIN" ]] \
        || fail "Production fix confirmation did not match the root domain."

    FIX_PRODUCTION_CONFIRMED="true"
}

fix_review_nonbreaking_findings() {
    local -a indexes=()
    local i status key message

    for i in "${!AUDIT_RESULT_STATUSES[@]}"; do
        status="${AUDIT_RESULT_STATUSES[$i]}"
        [[ "$status" == "INFO" || "$status" == "WARNING" ]] || continue

        key="${AUDIT_RESULT_KEYS[$i]}"
        case "$key" in
            deployment.app_path|database.topology|database.name|database.user|redis.cache_prefix|redis.prefix|redis.horizon_prefix|redis.*_collision|deployment_plan.unused_environment|runtime.*.metadata|supervisor.config|supervisor.program|supervisor.running|nginx.site)
                indexes+=("$i")
                ;;
        esac
    done

    [[ ${#indexes[@]} -gt 0 ]] || return 0

    echo
    echo "== Guided review: non-breaking drift/warnings =="
    for i in "${indexes[@]}"; do
        printf '  %-38s [%s] %s\n' \
            "${AUDIT_RESULT_KEYS[$i]}" \
            "${AUDIT_RESULT_STATUSES[$i]}" \
            "${AUDIT_RESULT_MESSAGES[$i]}"
    done

    if prompt_yes_no "Keep these non-breaking findings as-is for this fix pass?" "yes"; then
        return 0
    fi

    echo
    echo "Reviewing individually. Choosing not to keep one stops fix without mutating that finding."
    for i in "${indexes[@]}"; do
        key="${AUDIT_RESULT_KEYS[$i]}"
        status="${AUDIT_RESULT_STATUSES[$i]}"
        message="${AUDIT_RESULT_MESSAGES[$i]}"
        echo
        echo "[$status] $key"
        echo "  $message"
        if ! prompt_yes_no "Keep the current state for [$key] and continue?" "yes"; then
            fail "Fix stopped at [$key]. This non-breaking finding has no generic automatic normalization; reconcile it deliberately, then rerun fix."
        fi
    done
}

fix_plan_blocking_environment_count() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || {
        printf '0\n'
        return 0
    }

    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
count = 0
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"}:
        count += 1
    elif requirement == "required" and status in {"missing", "unresolved"}:
        count += 1
print(count)
'
}

fix_environment_plan() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    local count
    count="$(fix_plan_blocking_environment_count)"
    [[ "$count" -gt 0 ]] || return 0

    echo "  [environment] $count blocking environment requirement(s) need operator reconciliation:"
    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    blocking = status in {"mismatch", "invalid"} or (
        requirement == "required" and status in {"missing", "unresolved"}
    )
    if not blocking:
        continue
    key = str(item.get("key", "unknown"))
    scope = str(item.get("scope", "unknown"))
    secret = "secret; hidden input" if item.get("secret") else "non-secret"
    expected = item.get("expected_value")
    suffix = f"; expected={expected}" if expected is not None and not item.get("secret") else ""
    print(f"                {key} ({scope}, {status}, {secret}{suffix})")
'
}

fix_environment_guidance() {
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    printf '%s' "$AUDIT_PLAN_JSON" | python3 -c '
import json, sys
plan = json.load(sys.stdin)
blocking = set()
for item in plan.get("environment_requirements", []):
    if not isinstance(item, dict):
        continue
    status = item.get("status")
    requirement = item.get("requirement")
    if status in {"mismatch", "invalid"} or (
        requirement == "required" and status in {"missing", "unresolved"}
    ):
        key = item.get("key")
        if isinstance(key, str):
            blocking.add(key)

shown = False
for step in plan.get("setup_steps", []):
    if not isinstance(step, dict):
        continue
    keys = {key for key in step.get("environment_keys", []) if isinstance(key, str)}
    if not (keys & blocking):
        continue
    if not shown:
        print("\nProvider/external guidance for the blocking values:")
        shown = True
    title = str(step.get("title", step.get("key", "Setup step"))).strip()
    reason = str(step.get("reason", "")).strip()
    print(f"\n  {title}")
    if reason:
        print(f"    {reason}")
    for instruction in step.get("instructions", []):
        if isinstance(instruction, str) and instruction.strip():
            print(f"    - {instruction.strip()}")
'
}

fix_reaudit_after_environment_change() {
    echo
    echo "Environment values changed. Clearing cached application configuration and re-auditing before any runtime repair."
    (
        cd "$APP_PATH" || exit 1
        "$PHP_BIN" artisan optimize:clear
    ) || return 1

    run_audit || true

    [[ "$AUDIT_SOURCE_CURRENT" == "true" ]] \
        || fail "Fix requires clean Core/client checkouts matching their configured remote branches. Source is never pulled automatically."
    [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" == "true" ]] \
        || fail "Fix requires the audited checkout to be the active CRM owner."
}

fix_interactive_environment_reconciliation() {
    fix_category_selected environment || return 0
    [[ -n "$AUDIT_PLAN_JSON" ]] || return 0

    local count
    count="$(fix_plan_blocking_environment_count)"
    [[ "$count" -gt 0 ]] || return 0

    echo
    echo "== Guided reconciliation: environment/provider requirements =="
    fix_environment_guidance
    echo
    echo "The launcher will now prompt only for currently blocking environment values."
    echo "Secret input is hidden. Values are written only after you supply or approve them."
    echo "Use Ctrl+C to stop safely if you need to leave and retrieve a provider value."

    if ! prompt_yes_no "Reconcile these blocking environment values now?" "yes"; then
        echo "Skipping environment reconciliation for this pass."
        return 0
    fi

    fix_confirm_mutation_once

    local plan_file result_file changed
    plan_file="$(mktemp)" || return 1
    result_file="$(mktemp)" || {
        rm -f "$plan_file"
        return 1
    }

    printf '%s\n' "$AUDIT_PLAN_JSON" > "$plan_file"

    if ! python3 "$HELPER" resolve-requirements \
        --plan "$plan_file" \
        --root-env "$ROOT_ENV" \
        --client-env "$CLIENT_ENV" \
        --mode all \
        --result-file "$result_file"
    then
        rm -f "$plan_file" "$result_file"
        return 1
    fi

    changed="$(cat "$result_file" 2>/dev/null || printf '0')"
    rm -f "$plan_file" "$result_file"

    if [[ "$changed" =~ ^[0-9]+$ && "$changed" -gt 0 ]]; then
        fix_reaudit_after_environment_change || return 1
    else
        echo "No environment values changed."
    fi
}

fix_should_apply_interactively() {
    local prompt="$1"
    if [[ "$FIX_MODE" != "interactive" ]]; then
        return 0
    fi
    prompt_yes_no "$prompt" "yes"
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
    echo "           $PHP_BIN artisan modules:preflight"
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
    "$PHP_BIN" artisan modules:preflight || return 1

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
            deployment_plan.*)
                if fix_category_selected environment && [[ "$FIX_MODE" != "apply" ]]; then
                    echo "  [guided] $key — operator-supplied environment reconciliation is available; values are never invented."
                else
                    echo "  [manual] $key — non-interactive fix never invents or collects environment/provider values."
                fi
                ;;
            external_setup.*|dns.*)
                echo "  [manual] $key — provider-dashboard verification and DNS changes remain operator/external work."
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
    echo "Selected categories: $FIX_CATEGORIES"

    local planned=false

    if fix_category_selected environment && [[ "$(fix_plan_blocking_environment_count)" -gt 0 ]]; then
        fix_environment_plan
        planned=true
    fi

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
        echo "  No guided reconciliation or registered safe repair is currently selected."
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
    run_audit || true

    [[ "$AUDIT_SOURCE_CURRENT" == "true" ]] \
        || fail "Fix requires clean Core/client checkouts matching their configured remote branches. Source is never pulled automatically."

    [[ "${AUDIT_CHECKOUT_ACTIVE:-unknown}" == "true" ]] \
        || fail "Fix requires the audited checkout to be the active CRM owner."

    fix_print_plan

    if [[ "$FIX_MODE" == "dry-run" ]]; then
        echo
        echo "Dry-run only. No prompts were opened and no deployment state was changed by fix."
        return 0
    fi

    if [[ "$FIX_MODE" == "interactive" ]]; then
        if fix_category_selected environment; then
            fix_review_nonbreaking_findings
        fi
        if ! fix_interactive_environment_reconciliation; then
            fix_reaudit_after_failure
            return 1
        fi
        echo
        echo "== Reconciled repair plan =="
        fix_print_plan
    fi

    if fix_category_selected schema && fix_schema_repairable; then
        if fix_should_apply_interactively "Apply the enabled schema/ledger repair shown above?"; then
            fix_confirm_mutation_once
            if ! fix_apply_schema; then
                fix_reaudit_after_failure
                return 1
            fi
        else
            echo "Skipped schema repair."
        fi
    fi

    if fix_category_selected runtime && (
        audit_has_breaking runtime.storage.write \
        || audit_has_breaking runtime.storage/logs.write \
        || audit_has_breaking runtime.bootstrap/cache.write
    ); then
        if fix_should_apply_interactively "Repair the proven runtime-directory write failure?"; then
            fix_confirm_mutation_once
            if ! fix_apply_runtime; then
                fix_reaudit_after_failure
                return 1
            fi
        else
            echo "Skipped runtime-directory repair."
        fi
    fi

    if fix_category_selected nginx; then
        local -a nginx_hosts=()
        mapfile -t nginx_hosts < <(fix_safe_nginx_missing_hosts)
        local host
        for host in "${nginx_hosts[@]}"; do
            if fix_should_apply_interactively "Create the supplemental Nginx/TLS host [$host]?"; then
                fix_confirm_mutation_once
                if ! fix_apply_nginx_host "$host"; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Nginx/TLS repair for [$host]."
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
            if fix_should_apply_interactively "Start/restart Horizon through the existing checkout-owned Supervisor program?"; then
                fix_confirm_mutation_once
                if ! fix_apply_horizon; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Horizon repair."
            fi
        fi

        if fix_category_selected scheduler && audit_has_breaking scheduler.cron; then
            if fix_should_apply_interactively "Install the missing Laravel Scheduler cron entry?"; then
                fix_confirm_mutation_once
                if ! fix_apply_scheduler; then
                    fix_reaudit_after_failure
                    return 1
                fi
            else
                echo "Skipped Scheduler repair."
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
    run_audit || true

    if [[ "$AUDIT_BREAKING" -eq 0 ]]; then
        echo "Fix reconciliation completed and re-audit is clean."
        return 0
    fi

    echo "Fix reconciliation completed. Re-audit still reports findings that require another selected pass or operator/external resolution."
    return 1
}

normalize_fix_categories() {
    local raw="$1"
    python3 -c '
import sys
aliases = {
    "environment": "environment",
    "env": "environment",
    "provider": "environment",
    "providers": "environment",
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
    local mode="interactive"
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
            --interactive) mode="interactive"; shift ;;
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
    FIX_PRODUCTION_CONFIRMED="false"
    if [[ -n "$only" ]]; then
        FIX_CATEGORIES="$(normalize_fix_categories "$only")" \
            || fail "Invalid --only category list [$only]."
        [[ -n "$FIX_CATEGORIES" ]] || fail "--only must select at least one fix category."
    elif [[ "$all_safe" == "true" ]]; then
        FIX_CATEGORIES="schema,runtime,nginx,horizon,scheduler"
    else
        FIX_CATEGORIES="environment,schema,runtime,nginx,horizon,scheduler"
    fi

    run_fix
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

# Load phase implementations without executing them. The entry point retains
# command parsing; stage ownership stays in the focused phase libraries.
for phase_library in launch_application.sh launch_workflow.sh launch_audit.sh; do
    phase_file="$SCRIPT_DIR/lib/$phase_library"
    [[ -f "$phase_file" && ! -L "$phase_file" ]] \
        || fail "Missing or symlinked deployment phase library: $phase_file"
    # shellcheck source=/dev/null
    source "$phase_file"
done

REQUESTED_MODULES=()
main "$@"
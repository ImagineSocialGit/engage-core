#!/usr/bin/env bash
# Application environment contracts and setup for the existing deployment entry point.
# Function definitions only; no environment files are read or written on import.
# Pre-Ansible DB and Redis assumptions in ensure_environment are to be replaced;
# do not invoke mutating deployment workflows against Ansible-managed hosts yet.

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
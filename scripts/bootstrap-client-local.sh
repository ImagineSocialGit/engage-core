#!/usr/bin/env bash

set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage:
  ./scripts/bootstrap-client-local.sh client-key

This command is local-development only.

Canonical local Core hosts:
  ROOT_DOMAIN=engagecore.test
  APP_URL=http://engagecore.test
  CRM_APP_URL=http://crm.engagecore.test

The command never creates, deletes, commits, pushes, or edits a GitHub repository.
It creates/reconciles ignored local runtime state for an already-created client,
provisions a client-scoped local MySQL database/user, safely selects the client in
the root local .env, runs the normal install path, and verifies the effective
runtime under both the developer process and the local PHP-FPM/web user before
returning success. Local root/client runtime .env files are normalized to mode 0664,
matching the proven development convention.

Safe local product defaults:
  EMAIL_PROVIDER=resend      when Messaging is enabled and no value exists
  SMS_ENABLED=false          when Messaging is enabled and no value exists
  FORMS_EXTERNAL_INTAKE_ENABLED=false
                             when Forms is enabled and no value exists

If bootstrap fails after changing CLIENT_KEY, the previously selected local client
is restored automatically.

Example:
  ./scripts/bootstrap-client-local.sh stevie-woodward-crm
USAGE
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

CLIENT_KEY=""
WEB_USER="${ENGAGE_CORE_WEB_USER:-www-data}"

while (($#)); do
  case "$1" in
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      fail "Unknown option: $1"
      ;;
    *)
      if [[ -n "$CLIENT_KEY" ]]; then
        fail "Unexpected positional argument: $1"
      fi
      CLIENT_KEY="$1"
      shift
      ;;
  esac
done

[[ -n "$CLIENT_KEY" ]] || {
  usage >&2
  exit 1
}

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  fail "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT_ENV="$ROOT_DIR/.env"
CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY"
CLIENT_ENV="$CLIENT_DIR/.env"
HELPER="$ROOT_DIR/scripts/operations/lib/launch_client_environment.py"

LOCAL_ROOT_DOMAIN="engagecore.test"
LOCAL_APP_URL="http://engagecore.test"
LOCAL_CRM_APP_URL="http://crm.engagecore.test"

[[ -f "$ROOT_DIR/artisan" && -f "$ROOT_DIR/bootstrap/app.php" ]] \
  || fail "Run this script from an Engage Core checkout."

[[ -d "$CLIENT_DIR" ]] || fail "Client directory does not exist: $CLIENT_DIR"
[[ -f "$CLIENT_DIR/config/client.php" ]] || fail "Client config is missing: $CLIENT_DIR/config/client.php"
[[ -f "$CLIENT_DIR/config/modules.php" ]] || fail "Client modules config is missing: $CLIENT_DIR/config/modules.php"
[[ -f "$ROOT_ENV" ]] || fail "Root local environment file does not exist: $ROOT_ENV"
[[ -f "$HELPER" ]] || fail "Deployment environment helper is missing: $HELPER"

for command in php python3 openssl mysql sudo id awk sed rm stat; do
  command -v "$command" >/dev/null 2>&1 \
    || fail "Required command not found: $command"
done

if ! id "$WEB_USER" >/dev/null 2>&1; then
  fail "Local PHP-FPM/web user does not exist: $WEB_USER"
fi

env_get_optional() {
  local file="$1"
  local key="$2"

  python3 "$HELPER" env-get \
    --file "$file" \
    --key "$key" \
    2>/dev/null || true
}

env_set() {
  local file="$1"
  local key="$2"
  local value="$3"

  python3 "$HELPER" env-set \
    --file "$file" \
    --key "$key" \
    --value "$value"
}

env_remove() {
  local file="$1"
  local key="$2"

  python3 "$HELPER" env-remove \
    --file "$file" \
    --key "$key"
}

env_set_if_blank() {
  local file="$1"
  local key="$2"
  local value="$3"
  local current

  current="$(env_get_optional "$file" "$key")"
  if [[ -z "$current" ]]; then
    env_set "$file" "$key" "$value"
  fi
}

module_enabled() {
  local module="$1"

  php -r '
$config = require $argv[1];
$enabled = $config["enabled"] ?? [];
exit(in_array($argv[2], is_array($enabled) ? $enabled : [], true) ? 0 : 1);
' "$CLIENT_DIR/config/modules.php" "$module"
}

bounded_identifier() {
  local value="$1"
  local maximum="$2"

  python3 - "$value" "$maximum" <<'PY'
import hashlib
import sys

value = sys.argv[1]
maximum = int(sys.argv[2])

if len(value) <= maximum:
    print(value)
    raise SystemExit(0)

digest = hashlib.sha256(value.encode("utf-8")).hexdigest()[:8]
room = maximum - 1 - len(digest)
prefix = value[:room].rstrip("._-") or value[:room]
print(f"{prefix}_{digest}")
PY
}

invalidate_client_runtime_caches() {
  rm -f "$ROOT_DIR/bootstrap/cache/config.php"
  rm -f "$ROOT_DIR/bootstrap/cache/events.php"
  rm -f "$ROOT_DIR/bootstrap/cache/routes-"*.php
}

apply_local_env_permissions() {
  chmod 0664 "$ROOT_ENV" "$CLIENT_ENV"
}

assert_local_env_readability() {
  test -r "$ROOT_ENV" \
    || fail "Current developer process cannot read root local environment: $ROOT_ENV"
  test -r "$CLIENT_ENV" \
    || fail "Current developer process cannot read selected-client local environment: $CLIENT_ENV"

  sudo -u "$WEB_USER" test -r "$ROOT_ENV" \
    || fail "Local PHP-FPM/web user [$WEB_USER] cannot read root local environment: $ROOT_ENV"
  sudo -u "$WEB_USER" test -r "$CLIENT_ENV" \
    || fail "Local PHP-FPM/web user [$WEB_USER] cannot read selected-client local environment: $CLIENT_ENV"
}

web_environment_context() {
  sudo -u "$WEB_USER" php -r '
require __DIR__."/vendor/autoload.php";

Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
(new App\Support\Clients\ClientEnvironmentLoader())->load(__DIR__);

foreach ([
    "CLIENT_KEY",
    "ROOT_DOMAIN",
    "APP_URL",
    "CRM_APP_URL",
    "DB_DATABASE",
    "DB_USERNAME",
] as $key) {
    $value = $_ENV[$key] ?? getenv($key) ?: "";
    fwrite(STDOUT, "__".$key."__=".$value.PHP_EOL);
}
'
}

APP_ENV_VALUE="$(env_get_optional "$ROOT_ENV" APP_ENV)"
[[ "$APP_ENV_VALUE" == "local" ]] \
  || fail "Local bootstrap requires APP_ENV=local in $ROOT_ENV; found [${APP_ENV_VALUE:-blank}]."

PREVIOUS_CLIENT_KEY="$(env_get_optional "$ROOT_ENV" CLIENT_KEY)"
CLIENT_SELECTION_CHANGED=false
BOOTSTRAP_COMPLETE=false

restore_previous_client_on_failure() {
  if [[ "$BOOTSTRAP_COMPLETE" == true || "$CLIENT_SELECTION_CHANGED" != true ]]; then
    return
  fi

  printf '\nLocal bootstrap did not complete. Restoring previous client selection.\n' >&2

  if [[ -n "$PREVIOUS_CLIENT_KEY" ]]; then
    env_set "$ROOT_ENV" CLIENT_KEY "$PREVIOUS_CLIENT_KEY"
    printf 'Restored CLIENT_KEY=%s\n' "$PREVIOUS_CLIENT_KEY" >&2
  else
    env_remove "$ROOT_ENV" CLIENT_KEY
    printf 'Restored root CLIENT_KEY to unset.\n' >&2
  fi

  apply_local_env_permissions
  invalidate_client_runtime_caches
}

trap restore_previous_client_on_failure EXIT

RUNTIME_STEM="$(
  printf '%s' "$CLIENT_KEY" \
    | tr '[:upper:]' '[:lower:]' \
    | sed -E 's/[^a-z0-9]+/_/g; s/^_+//; s/_+$//; s/_+/_/g'
)"
[[ -n "$RUNTIME_STEM" ]] || RUNTIME_STEM="client"

DERIVED_DB_DATABASE="$(bounded_identifier "${RUNTIME_STEM}_local" 64)"
DERIVED_DB_USERNAME="$(bounded_identifier "${RUNTIME_STEM}_local" 32)"

touch "$CLIENT_ENV"

# Local runtime files intentionally follow the proven development convention:
# developer-owned and mode 0664. Atomic environment writes may replace the
# inode, so final permissions are applied only after all writes in each phase.

# Local host identity is canonical and shared by every selected Core client.
# Never inherit a production/staging domain or fall back to localhost here.
env_set "$CLIENT_ENV" ROOT_DOMAIN "$LOCAL_ROOT_DOMAIN"
env_set "$CLIENT_ENV" APP_URL "$LOCAL_APP_URL"
env_set "$CLIENT_ENV" CRM_APP_URL "$LOCAL_CRM_APP_URL"

DB_DATABASE="$(env_get_optional "$CLIENT_ENV" DB_DATABASE)"
if [[ -z "$DB_DATABASE" || "$DB_DATABASE" == "laravel" ]]; then
  DB_DATABASE="$DERIVED_DB_DATABASE"
  env_set "$CLIENT_ENV" DB_DATABASE "$DB_DATABASE"
fi

DB_USERNAME="$(env_get_optional "$CLIENT_ENV" DB_USERNAME)"
if [[ -z "$DB_USERNAME" || "$DB_USERNAME" == "root" ]]; then
  DB_USERNAME="$DERIVED_DB_USERNAME"
  env_set "$CLIENT_ENV" DB_USERNAME "$DB_USERNAME"
fi

DB_PASSWORD="$(env_get_optional "$CLIENT_ENV" DB_PASSWORD)"
if [[ -z "$DB_PASSWORD" ]]; then
  DB_PASSWORD="$(openssl rand -hex 20)"
  env_set "$CLIENT_ENV" DB_PASSWORD "$DB_PASSWORD"
fi

if module_enabled messaging; then
  env_set_if_blank "$CLIENT_ENV" EMAIL_PROVIDER resend
  env_set_if_blank "$CLIENT_ENV" SMS_ENABLED false
fi

if module_enabled forms; then
  env_set_if_blank "$CLIENT_ENV" FORMS_EXTERNAL_INTAKE_ENABLED false
fi

[[ "$DB_DATABASE" =~ ^[A-Za-z0-9_]+$ ]] \
  || fail "Automated local MySQL provisioning requires a simple database identifier; found [$DB_DATABASE]."
[[ "$DB_USERNAME" =~ ^[A-Za-z0-9_]+$ ]] \
  || fail "Automated local MySQL provisioning requires a simple database username; found [$DB_USERNAME]."
[[ "$DB_PASSWORD" =~ ^[A-Za-z0-9._~!@#%^*+=:-]+$ ]] \
  || fail "Automated local MySQL provisioning cannot safely use the existing DB_PASSWORD. Use letters, numbers, or ._~!@#%^*+=:- for the local client credential."

ROOT_DB_CONNECTION="$(env_get_optional "$ROOT_ENV" DB_CONNECTION)"
ROOT_DB_HOST="$(env_get_optional "$ROOT_ENV" DB_HOST)"
ROOT_DB_PORT="$(env_get_optional "$ROOT_ENV" DB_PORT)"

[[ -z "$ROOT_DB_CONNECTION" || "$ROOT_DB_CONNECTION" == "mysql" ]] \
  || fail "Local bootstrap requires the root DB_CONNECTION to be mysql; found [$ROOT_DB_CONNECTION]."

[[ -z "$ROOT_DB_HOST" || "$ROOT_DB_HOST" == "127.0.0.1" || "$ROOT_DB_HOST" == "localhost" ]] \
  || fail "Local bootstrap only provisions local MySQL; root DB_HOST is [$ROOT_DB_HOST]."

[[ -z "$ROOT_DB_PORT" || "$ROOT_DB_PORT" =~ ^[0-9]+$ ]] \
  || fail "Root DB_PORT must be numeric; found [$ROOT_DB_PORT]."

env_set_if_blank "$ROOT_ENV" DB_CONNECTION mysql
env_set_if_blank "$ROOT_ENV" DB_HOST 127.0.0.1
env_set_if_blank "$ROOT_ENV" DB_PORT 3306

apply_local_env_permissions
assert_local_env_readability

echo
echo "Local client database"
echo "  Client:   $CLIENT_KEY"
echo "  Database: $DB_DATABASE"
echo "  User:     $DB_USERNAME"
echo
echo "Provisioning the client-scoped local MySQL database/user through sudo mysql -p."
echo "The privileged MySQL password is not stored."

if ! sudo mysql -p <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USERNAME'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'127.0.0.1';
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USERNAME'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';
FLUSH PRIVILEGES;
SQL
then
  fail "Local MySQL provisioning failed. The client .env was preserved and the existing selected client was not changed."
fi

# Build the entire target client runtime before changing the active client.
# Remove client-specific caches directly so the first Artisan boot after the
# switch cannot reuse cached configuration/routes from the previous client.
invalidate_client_runtime_caches

env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"
if [[ "$PREVIOUS_CLIENT_KEY" != "$CLIENT_KEY" ]]; then
  CLIENT_SELECTION_CHANGED=true
fi

apply_local_env_permissions
assert_local_env_readability
invalidate_client_runtime_caches
cd "$ROOT_DIR"

php artisan optimize:clear >/dev/null

RESOLVED_APP_ENV="$(
  php artisan env --no-ansi 2>/dev/null \
    | awk -F'[][]' '/The application environment is/ { print $2; exit }'
)"
[[ "$RESOLVED_APP_ENV" == "local" ]] \
  || fail "Laravel no longer resolves APP_ENV=local after selecting [$CLIENT_KEY]; got [$RESOLVED_APP_ENV]."

developer_runtime_context() {
  php artisan tinker --execute='
$connection = trim((string) config("database.default"));
fwrite(STDOUT, "__CLIENT_KEY__=" . trim((string) config("client.key")) . PHP_EOL);
fwrite(STDOUT, "__ROOT_DOMAIN__=" . trim((string) env("ROOT_DOMAIN")) . PHP_EOL);
fwrite(STDOUT, "__APP_URL__=" . trim((string) config("app.url")) . PHP_EOL);
fwrite(STDOUT, "__CRM_APP_URL__=" . trim((string) env("CRM_APP_URL")) . PHP_EOL);
fwrite(STDOUT, "__DB_DATABASE__=" . trim((string) config("database.connections." . $connection . ".database")) . PHP_EOL);
fwrite(STDOUT, "__DB_USERNAME__=" . trim((string) config("database.connections." . $connection . ".username")) . PHP_EOL);
' 2>/dev/null
}

context_value() {
  local context="$1"
  local key="$2"

  printf '%s\n' "$context" \
    | sed -n "s/^__${key}__=//p" \
    | tail -n 1
}

assert_developer_runtime() {
  local context effective_client effective_root effective_app effective_crm effective_database effective_username

  context="$(developer_runtime_context)"
  effective_client="$(context_value "$context" CLIENT_KEY)"
  effective_root="$(context_value "$context" ROOT_DOMAIN)"
  effective_app="$(context_value "$context" APP_URL)"
  effective_crm="$(context_value "$context" CRM_APP_URL)"
  effective_database="$(context_value "$context" DB_DATABASE)"
  effective_username="$(context_value "$context" DB_USERNAME)"

  [[ "$effective_client" == "$CLIENT_KEY" ]] \
    || fail "Effective client mismatch after local bootstrap selection: expected [$CLIENT_KEY], got [${effective_client:-blank}]."
  [[ "$effective_root" == "$LOCAL_ROOT_DOMAIN" ]] \
    || fail "Effective ROOT_DOMAIN mismatch: expected [$LOCAL_ROOT_DOMAIN], got [${effective_root:-blank}]."
  [[ "$effective_app" == "$LOCAL_APP_URL" ]] \
    || fail "Effective APP_URL mismatch: expected [$LOCAL_APP_URL], got [${effective_app:-blank}]."
  [[ "$effective_crm" == "$LOCAL_CRM_APP_URL" ]] \
    || fail "Effective CRM_APP_URL mismatch: expected [$LOCAL_CRM_APP_URL], got [${effective_crm:-blank}]."
  [[ "$effective_database" == "$DB_DATABASE" && "$effective_database" != "laravel" ]] \
    || fail "Effective client database is wrong: expected [$DB_DATABASE], got [${effective_database:-blank}]. Refusing Laravel fallback database [laravel]."
  [[ "$effective_username" == "$DB_USERNAME" && "$effective_username" != "root" ]] \
    || fail "Effective client database user is wrong: expected [$DB_USERNAME], got [${effective_username:-blank}]. Refusing Laravel fallback user [root]."
}

assert_web_runtime() {
  local context effective_client effective_root effective_app effective_crm effective_database effective_username

  context="$(web_environment_context)"
  effective_client="$(context_value "$context" CLIENT_KEY)"
  effective_root="$(context_value "$context" ROOT_DOMAIN)"
  effective_app="$(context_value "$context" APP_URL)"
  effective_crm="$(context_value "$context" CRM_APP_URL)"
  effective_database="$(context_value "$context" DB_DATABASE)"
  effective_username="$(context_value "$context" DB_USERNAME)"

  [[ "$effective_client" == "$CLIENT_KEY" ]] \
    || fail "Local PHP-FPM/web runtime client mismatch: expected [$CLIENT_KEY], got [${effective_client:-blank}]."
  [[ "$effective_root" == "$LOCAL_ROOT_DOMAIN" ]] \
    || fail "Local PHP-FPM/web runtime ROOT_DOMAIN mismatch: expected [$LOCAL_ROOT_DOMAIN], got [${effective_root:-blank}]."
  [[ "$effective_app" == "$LOCAL_APP_URL" ]] \
    || fail "Local PHP-FPM/web runtime APP_URL mismatch: expected [$LOCAL_APP_URL], got [${effective_app:-blank}]."
  [[ "$effective_crm" == "$LOCAL_CRM_APP_URL" ]] \
    || fail "Local PHP-FPM/web runtime CRM_APP_URL mismatch: expected [$LOCAL_CRM_APP_URL], got [${effective_crm:-blank}]."
  [[ "$effective_database" == "$DB_DATABASE" && "$effective_database" != "laravel" ]] \
    || fail "Local PHP-FPM/web runtime database is wrong: expected [$DB_DATABASE], got [${effective_database:-blank}]. Refusing Laravel fallback database [laravel]."
  [[ "$effective_username" == "$DB_USERNAME" && "$effective_username" != "root" ]] \
    || fail "Local PHP-FPM/web runtime database user is wrong: expected [$DB_USERNAME], got [${effective_username:-blank}]. Refusing Laravel fallback user [root]."
}

assert_developer_runtime
assert_web_runtime

php artisan engage:environment:sync --write-missing
php artisan optimize:clear >/dev/null
apply_local_env_permissions
assert_local_env_readability
assert_developer_runtime
assert_web_runtime

echo
echo "Validating local deployment requirements..."
php artisan engage:deployment-plan

echo
echo "Installing local client runtime..."
php artisan engage:install

echo
echo "Final local validation..."
php artisan engage:deployment-plan
php artisan modules:status
apply_local_env_permissions
assert_local_env_readability
assert_developer_runtime
assert_web_runtime

BOOTSTRAP_COMPLETE=true
trap - EXIT

echo
echo "Local client bootstrap complete."
echo "  Client:      $CLIENT_KEY"
echo "  Environment: local"
echo "  ROOT_DOMAIN: $LOCAL_ROOT_DOMAIN"
echo "  APP_URL:     $LOCAL_APP_URL"
echo "  CRM_APP_URL: $LOCAL_CRM_APP_URL"
echo "  Database:    $DB_DATABASE"
echo "  DB user:     $DB_USERNAME"
echo
echo "The root local checkout now selects CLIENT_KEY=$CLIENT_KEY."
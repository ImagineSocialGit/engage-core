#!/usr/bin/env bash

set -euo pipefail

# Match the proven local Engage Core client permission baseline.
umask 0002

usage() {
  cat <<'USAGE'
Usage:
  ./scripts/init-client-local-env.sh client-key [options]

Options:
  --force       Replace an existing client local .env.
  --no-select   Do not update CLIENT_KEY in the root local .env.
  --dry-run     Validate and print the local environment plan without writing files.
  -h, --help    Show this help.

Local defaults:
  DB username:  devUser
  DB password:  password
  DB database:  engage_core_<client-key normalized with underscores>
  ROOT_DOMAIN:  engagecore.test
  APP_URL:      http://engagecore.test
  CRM_APP_URL:  http://crm.engagecore.test

Machine-specific overrides may be supplied without editing the script:
  ENGAGE_LOCAL_DB_USERNAME
  ENGAGE_LOCAL_DB_PASSWORD
  ENGAGE_LOCAL_DB_DATABASE
  ENGAGE_LOCAL_ROOT_DOMAIN
  ENGAGE_LOCAL_APP_URL
  ENGAGE_LOCAL_CRM_APP_URL

This helper is local-development only. It refuses to run unless the platform root
.env resolves APP_ENV=local. The generated client .env is ignored by Git.
USAGE
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

read_dotenv_value() {
  local file="$1"
  local key="$2"
  local value

  value="$(
    awk -v wanted="$key" '
      $0 !~ /^[[:space:]]*#/ {
        line = $0
        sub(/^[[:space:]]*export[[:space:]]+/, "", line)
        split(line, parts, "=")
        candidate = parts[1]
        gsub(/^[[:space:]]+|[[:space:]]+$/, "", candidate)
        if (candidate == wanted) {
          sub(/^[^=]*=/, "", line)
          print line
          exit
        }
      }
    ' "$file"
  )"

  value="$(printf '%s' "$value" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"

  if [[ ${#value} -ge 2 ]]; then
    if [[ "${value:0:1}" == '"' && "${value: -1}" == '"' ]]; then
      value="${value:1:${#value}-2}"
    elif [[ "${value:0:1}" == "'" && "${value: -1}" == "'" ]]; then
      value="${value:1:${#value}-2}"
    fi
  fi

  printf '%s' "$value"
}

set_dotenv_value() {
  local file="$1"
  local key="$2"
  local value="$3"
  local temporary

  temporary="$(mktemp "${file}.tmp.XXXXXX")"

  awk -v wanted="$key" -v replacement="$key=$value" '
    BEGIN { replaced = 0 }
    {
      line = $0
      candidate = line
      sub(/^[[:space:]]*export[[:space:]]+/, "", candidate)
      split(candidate, parts, "=")
      name = parts[1]
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", name)

      if (name == wanted && line !~ /^[[:space:]]*#/) {
        if (! replaced) {
          print replacement
          replaced = 1
        }
        next
      }

      print line
    }
    END {
      if (! replaced) {
        print replacement
      }
    }
  ' "$file" > "$temporary"

  chmod --reference="$file" "$temporary" 2>/dev/null || chmod 0640 "$temporary"
  chgrp --reference="$file" "$temporary" 2>/dev/null || true
  mv "$temporary" "$file"
}

dotenv_value() {
  local value="$1"

  if [[ -z "$value" || "$value" =~ [[:space:]#=\"\\] ]]; then
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    printf '"%s"' "$value"
    return
  fi

  printf '%s' "$value"
}

CLIENT_KEY=""
FORCE=false
SELECT_CLIENT=true
DRY_RUN=false

while (($#)); do
  case "$1" in
    --force)
      FORCE=true
      shift
      ;;
    --no-select)
      SELECT_CLIENT=false
      shift
      ;;
    --dry-run)
      DRY_RUN=true
      shift
      ;;
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
  usage
  exit 2
}

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  fail "Client key contains unsupported characters: $CLIENT_KEY"
fi

command -v php >/dev/null 2>&1 || fail "PHP is required."

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ROOT_ENV="$ROOT_DIR/.env"
CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY"
CLIENT_CONFIG="$CLIENT_DIR/config/client.php"
CLIENT_MODULES="$CLIENT_DIR/config/modules.php"
CLIENT_ENV="$CLIENT_DIR/.env"
ROOT_MODULES="$ROOT_DIR/config/modules.php"

[[ -f "$ROOT_DIR/artisan" ]] || fail "Run this helper from an Engage Core checkout."
[[ -f "$ROOT_ENV" ]] || fail "Root .env is required before initializing a local client environment."
[[ -f "$CLIENT_CONFIG" ]] || fail "Client config is missing: $CLIENT_CONFIG"
[[ -f "$CLIENT_MODULES" ]] || fail "Client module config is missing: $CLIENT_MODULES"
[[ -f "$ROOT_MODULES" ]] || fail "Root module definitions are missing: $ROOT_MODULES"

APP_ENV_VALUE="$(read_dotenv_value "$ROOT_ENV" APP_ENV)"
[[ "$APP_ENV_VALUE" == "local" ]] \
  || fail "Local client environment initialization requires root APP_ENV=local; resolved [$APP_ENV_VALUE]."

CONFIG_CLIENT_KEY="$(php -r '
$config = require $argv[1];
$key = trim((string) ($config["key"] ?? ""));
echo $key;
' "$CLIENT_CONFIG")"

[[ "$CONFIG_CLIENT_KEY" == "$CLIENT_KEY" ]] \
  || fail "Client config key [$CONFIG_CLIENT_KEY] does not match requested client [$CLIENT_KEY]."

if [[ -f "$CLIENT_ENV" && "$FORCE" != true ]]; then
  fail "Client local .env already exists: $CLIENT_ENV (use --force only when replacement is intentional)."
fi

NORMALIZED_KEY="$(printf '%s' "$CLIENT_KEY" | tr '-' '_' | tr -cd '[:alnum:]_')"
PREFIX_STEM="${NORMALIZED_KEY}_local"

DB_USERNAME="${ENGAGE_LOCAL_DB_USERNAME:-devUser}"
DB_PASSWORD="${ENGAGE_LOCAL_DB_PASSWORD:-password}"
DB_DATABASE="${ENGAGE_LOCAL_DB_DATABASE:-engage_core_${NORMALIZED_KEY}}"
LOCAL_ROOT_DOMAIN="${ENGAGE_LOCAL_ROOT_DOMAIN:-engagecore.test}"
LOCAL_APP_URL="${ENGAGE_LOCAL_APP_URL:-http://${LOCAL_ROOT_DOMAIN}}"
LOCAL_CRM_APP_URL="${ENGAGE_LOCAL_CRM_APP_URL:-http://crm.${LOCAL_ROOT_DOMAIN}}"

php -r '
foreach (["APP_URL" => $argv[1], "CRM_APP_URL" => $argv[2]] as $key => $value) {
    $parts = parse_url($value);
    if (! is_array($parts)
        || ! in_array(strtolower((string) ($parts["scheme"] ?? "")), ["http", "https"], true)
        || trim((string) ($parts["host"] ?? "")) === ""
        || isset($parts["user"], $parts["pass"])
        || (($parts["path"] ?? "") !== "" && ($parts["path"] ?? "") !== "/")
        || isset($parts["query"])
        || isset($parts["fragment"])) {
        fwrite(STDERR, "Invalid local {$key} origin: {$value}\n");
        exit(1);
    }
}
' "$LOCAL_APP_URL" "$LOCAL_CRM_APP_URL"

[[ "$LOCAL_ROOT_DOMAIN" != *"://"* && "$LOCAL_ROOT_DOMAIN" != *"/"* && -n "$LOCAL_ROOT_DOMAIN" ]] \
  || fail "ENGAGE_LOCAL_ROOT_DOMAIN must be a bare domain/host without a scheme or path."

MESSAGING_ENABLED="$(php -r '
$root = require $argv[1];
$client = require $argv[2];
$definitions = is_array($root["modules"] ?? null) ? $root["modules"] : [];
$enabled = is_array($client["enabled"] ?? null) ? $client["enabled"] : [];
$resolved = [];
$resolving = [];
$add = function (string $key) use (&$add, &$resolved, &$resolving, $definitions): void {
    if (isset($resolved[$key]) || isset($resolving[$key])) {
        return;
    }
    $resolving[$key] = true;
    $definition = $definitions[$key] ?? [];
    foreach ((array) ($definition["depends_on"] ?? []) as $dependency) {
        if (is_string($dependency) && $dependency !== "") {
            $add($dependency);
        }
    }
    unset($resolving[$key]);
    $resolved[$key] = true;
};
foreach ($definitions as $key => $definition) {
    if (is_string($key) && is_array($definition) && ! empty($definition["always_on"])) {
        $add($key);
    }
}
foreach ($enabled as $key) {
    if (is_string($key) && $key !== "") {
        $add($key);
    }
}
echo isset($resolved["messaging"]) ? "yes" : "no";
' "$ROOT_MODULES" "$CLIENT_MODULES")"

if [[ "$DRY_RUN" == true ]]; then
  cat <<EOF_PLAN
Local client environment dry run
Client: $CLIENT_KEY
Root APP_ENV: $APP_ENV_VALUE
Client env: $CLIENT_ENV
Select in root .env: $SELECT_CLIENT
ROOT_DOMAIN: $LOCAL_ROOT_DOMAIN
APP_URL: $LOCAL_APP_URL
CRM_APP_URL: $LOCAL_CRM_APP_URL
DB_DATABASE: $DB_DATABASE
DB_USERNAME: $DB_USERNAME
DB_PASSWORD: (local default/override; value will be written but not printed)
CACHE_PREFIX: ${PREFIX_STEM}_cache_
REDIS_PREFIX: ${PREFIX_STEM}_
HORIZON_PREFIX: ${PREFIX_STEM}_horizon:
Messaging local defaults: $MESSAGING_ENABLED
EOF_PLAN
  exit 0
fi

# Local filesystem policy is based on the known-good Engage Core client layout:
# - root client/ only needs to remain traversable;
# - selected client directories are 0775-style (owner/group rwx, others rx);
# - selected client source/config files retain any existing execute/group-write bits,
#   while guaranteeing owner rw + group/other read;
# - .git is not touched;
# - .env is handled separately to mirror the already-working root .env.
chmod a+rx "$ROOT_DIR/client"
chmod u+rwx,g+rwx,o+rx "$CLIENT_DIR"

find "$CLIENT_DIR" \
  -path "$CLIENT_DIR/.git" -prune -o \
  -type d -exec chmod u+rwx,g+rwx,o+rx {} +

find "$CLIENT_DIR" \
  -path "$CLIENT_DIR/.git" -prune -o \
  -type f \
  ! -name '.env' \
  ! -name '.env.before-local-init' \
  -exec chmod u+rw,g+r,o+r {} +

if [[ -f "$CLIENT_ENV" ]]; then
  cp "$CLIENT_ENV" "$CLIENT_ENV.before-local-init"
  chmod 0600 "$CLIENT_ENV.before-local-init" 2>/dev/null || true
fi

{
  cat <<EOF_ENV
# Generated local development environment for $CLIENT_KEY.
# This file is intentionally ignored by Git. Never copy it to staging/production.

ROOT_DOMAIN=$(dotenv_value "$LOCAL_ROOT_DOMAIN")
APP_URL=$(dotenv_value "$LOCAL_APP_URL")
CRM_APP_URL=$(dotenv_value "$LOCAL_CRM_APP_URL")

DB_DATABASE=$(dotenv_value "$DB_DATABASE")
DB_USERNAME=$(dotenv_value "$DB_USERNAME")
DB_PASSWORD=$(dotenv_value "$DB_PASSWORD")

CACHE_PREFIX=$(dotenv_value "${PREFIX_STEM}_cache_")
REDIS_PREFIX=$(dotenv_value "${PREFIX_STEM}_")
HORIZON_PREFIX=$(dotenv_value "${PREFIX_STEM}_horizon:")
EOF_ENV

  if [[ "$MESSAGING_ENABLED" == "yes" ]]; then
    cat <<'EOF_MESSAGING'

# Local Messaging uses the development sink; no live provider credentials are required.
EMAIL_PROVIDER=resend
SMS_ENABLED=false
EOF_MESSAGING
  fi
} > "$CLIENT_ENV"

if ! chmod --reference="$ROOT_ENV" "$CLIENT_ENV" 2>/dev/null; then
  chmod 0664 "$CLIENT_ENV"
  printf 'WARNING: Could not copy the root .env mode to %s; using proven local-development fallback 0664.\n' "$CLIENT_ENV" >&2
fi

if ! chgrp --reference="$ROOT_ENV" "$CLIENT_ENV" 2>/dev/null; then
  printf 'WARNING: Could not copy the root .env group to %s. If local PHP-FPM cannot read it, align the file group with the local web/PHP-FPM group.\n' "$CLIENT_ENV" >&2
fi

if [[ "$SELECT_CLIENT" == true ]]; then
  set_dotenv_value "$ROOT_ENV" CLIENT_KEY "$(dotenv_value "$CLIENT_KEY")"
fi

if [[ -f "$ROOT_DIR/vendor/autoload.php" ]]; then
  if ! (cd "$ROOT_DIR" && php artisan config:clear >/dev/null); then
    printf 'WARNING: Could not clear Laravel config cache automatically. Run: php artisan config:clear\n' >&2
  fi

  if ! (cd "$ROOT_DIR" && php artisan engage:environment:sync --write-missing >/dev/null); then
    printf 'WARNING: Environment sync could not complete. The generated local base values were preserved; inspect with: php artisan engage:deployment-plan\n' >&2
  fi
fi

CLIENT_DIR_MODE="$(stat -c '%a' "$CLIENT_DIR" 2>/dev/null || echo unknown)"
CLIENT_ENV_MODE="$(stat -c '%a' "$CLIENT_ENV" 2>/dev/null || echo unknown)"
CLIENT_MODULES_MODE="$(stat -c '%a' "$CLIENT_MODULES" 2>/dev/null || echo unknown)"

cat <<EOF_DONE
Initialized local client environment: $CLIENT_ENV
Local client directory mode: $CLIENT_DIR_MODE
Local client .env mode: $CLIENT_ENV_MODE
Local client modules.php mode: $CLIENT_MODULES_MODE
Selected client in root .env: $([[ "$SELECT_CLIENT" == true ]] && echo "$CLIENT_KEY" || echo "unchanged")
Database: $DB_DATABASE
Database user: $DB_USERNAME
Messaging local defaults: $([[ "$MESSAGING_ENABLED" == "yes" ]] && echo "EMAIL_PROVIDER=resend, SMS_ENABLED=false (dev sink/no live credentials)" || echo "not needed")

The helper does not create/drop databases or run migrations.

Next:
  php artisan engage:deployment-plan

If the local database does not exist yet, create it with your local MySQL tooling, then install the selected client schema:
  php artisan engage:install --force --no-create-user

For a quick HTTP smoke after installation, use your normal local web server or:
  php artisan serve
EOF_DONE
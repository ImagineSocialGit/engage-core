#!/usr/bin/env bash

set -euo pipefail

usage() {
  cat <<'USAGE'
Usage:
  ./scripts/create-client.sh client-key timezone [options]

Options:
  --name "Client Name"
      Explicit human-readable client name.

  --preset PRESET
      Client product package. Supported presets:
        basic   Tasks + Workflow. Default.
        artist  Artist fan engagement: Messaging, Broadcasts, Campaigns,
                Forms, Integrations, and Reporting.

  -h, --help
      Show this help.

Environment overrides:
  ENGAGE_CORE_WEB_GROUP=www-data
  ENGAGE_CORE_GITHUB_OWNER=ImagineSocialGit

The command builds the selected client package, initializes it as a Git
repository, creates a private GitHub repository, pushes the initial main branch,
then bootstraps the new client as the active local development runtime.

Examples:
  ./scripts/create-client.sh example-client America/Chicago

  ./scripts/create-client.sh stevie-woodward-crm America/Chicago \
    --name "Stevie Woodward" \
    --preset artist
USAGE
}

CLIENT_KEY=""
CLIENT_TIMEZONE=""
CLIENT_NAME=""
CLIENT_PRESET="basic"
WEB_GROUP="${ENGAGE_CORE_WEB_GROUP:-www-data}"
GITHUB_OWNER="${ENGAGE_CORE_GITHUB_OWNER:-ImagineSocialGit}"

while (($#)); do
  case "$1" in
    --name)
      [[ $# -ge 2 ]] || {
        echo "--name requires a value." >&2
        exit 1
      }
      CLIENT_NAME="$2"
      shift 2
      ;;
    --preset)
      [[ $# -ge 2 ]] || {
        echo "--preset requires a value." >&2
        exit 1
      }
      CLIENT_PRESET="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      echo "Unknown option: $1" >&2
      usage >&2
      exit 1
      ;;
    *)
      if [[ -z "$CLIENT_KEY" ]]; then
        CLIENT_KEY="$1"
      elif [[ -z "$CLIENT_TIMEZONE" ]]; then
        CLIENT_TIMEZONE="$1"
      else
        echo "Unexpected positional argument: $1" >&2
        usage >&2
        exit 1
      fi
      shift
      ;;
  esac
done

if [[ -z "$CLIENT_KEY" || -z "$CLIENT_TIMEZONE" ]]; then
  usage >&2
  exit 1
fi

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
  exit 1
fi

if [[ -n "$CLIENT_NAME" && "$CLIENT_NAME" =~ [[:cntrl:]] ]]; then
  echo "Client name must not contain control characters."
  exit 1
fi

if [[ ! "$CLIENT_PRESET" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Invalid client preset: $CLIENT_PRESET"
  exit 1
fi

if [[ ! "$GITHUB_OWNER" =~ ^[A-Za-z0-9][A-Za-z0-9-]*$ ]]; then
  echo "Invalid GitHub owner: $GITHUB_OWNER"
  exit 1
fi

for command in php git gh getent; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "$command is required to create a client and its GitHub repository."
    exit 1
  fi
done

if ! getent group "$WEB_GROUP" >/dev/null 2>&1; then
  echo "Web server group does not exist: $WEB_GROUP"
  echo "Set ENGAGE_CORE_WEB_GROUP when the PHP-FPM group is not www-data."
  exit 1
fi

if ! gh auth status --hostname github.com >/dev/null 2>&1; then
  echo "GitHub CLI is not authenticated for github.com."
  echo "Run: gh auth login"
  exit 1
fi

php -r '
$timezone = $argv[1] ?? "";

if (! in_array($timezone, timezone_identifiers_list(), true)) {
    fwrite(STDERR, "Invalid timezone: {$timezone}\n");
    exit(1);
}
' "$CLIENT_TIMEZONE"

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLIENTS_DIR="$ROOT_DIR/client"
CLIENT_DIR="$CLIENTS_DIR/$CLIENT_KEY"
TEMP_CLIENT_DIR=""
GITHUB_REPOSITORY="$GITHUB_OWNER/$CLIENT_KEY"
GITHUB_SSH_URL="git@github.com:${GITHUB_REPOSITORY}.git"
PRESET_TEMPLATES_DIR="$ROOT_DIR/docs/config-templates/client-presets"
PRESET_TEMPLATE_DIR="$PRESET_TEMPLATES_DIR/$CLIENT_PRESET"

if [[ ! -d "$PRESET_TEMPLATE_DIR" ]]; then
  echo "Unsupported client preset: $CLIENT_PRESET"
  echo
  echo "Available presets:"
  find "$PRESET_TEMPLATES_DIR" \
    -mindepth 1 \
    -maxdepth 1 \
    -type d \
    -printf '  %f\n' \
    | sort
  exit 1
fi

if [[ ! -f "$PRESET_TEMPLATE_DIR/config/modules.php" ]]; then
  echo "Client preset is missing config/modules.php: $PRESET_TEMPLATE_DIR"
  exit 1
fi

for reserved_path in \
  config/client.php \
  .env \
  .env.example \
  .gitignore \
  README.md
do
  if [[ -e "$PRESET_TEMPLATE_DIR/$reserved_path" ]]; then
    echo "Client preset must not own generated/reserved path: $reserved_path"
    echo "Preset: $CLIENT_PRESET"
    exit 1
  fi
done

if [[ -e "$CLIENT_DIR" ]]; then
  echo "Client already exists: $CLIENT_DIR"
  exit 1
fi

if gh repo view "$GITHUB_REPOSITORY" --json nameWithOwner >/dev/null 2>&1; then
  echo "GitHub repository already exists: $GITHUB_REPOSITORY"
  echo "Refusing to attach a new client scaffold to an existing remote repository."
  exit 1
fi

GIT_AUTHOR_NAME="$(git -C "$ROOT_DIR" config user.name 2>/dev/null || true)"
GIT_AUTHOR_EMAIL="$(git -C "$ROOT_DIR" config user.email 2>/dev/null || true)"

if [[ -z "$GIT_AUTHOR_NAME" || -z "$GIT_AUTHOR_EMAIL" ]]; then
  echo "Git author identity is required before creating a client repository."
  echo "Configure user.name and user.email in this Core checkout or globally."
  exit 1
fi

mkdir -p "$CLIENTS_DIR"
TEMP_CLIENT_DIR="$(mktemp -d "$CLIENTS_DIR/.${CLIENT_KEY}.creating.XXXXXX")"

cleanup() {
  if [[ -n "$TEMP_CLIENT_DIR" && -d "$TEMP_CLIENT_DIR" ]]; then
    rm -rf "$TEMP_CLIENT_DIR"
  fi
}

trap cleanup EXIT

if [[ -z "$CLIENT_NAME" ]]; then
  CLIENT_NAME="$(
    echo "$CLIENT_KEY" \
      | tr '_-' '  ' \
      | awk '{
          for (i = 1; i <= NF; i++) {
            $i = toupper(substr($i, 1, 1)) substr($i, 2)
          }
        } 1'
  )"
fi

CLIENT_NAME_PHP="$(php -r 'echo var_export($argv[1], true);' "$CLIENT_NAME")"
CLIENT_KEY_PHP="$(php -r 'echo var_export($argv[1], true);' "$CLIENT_KEY")"
CLIENT_TIMEZONE_PHP="$(php -r 'echo var_export($argv[1], true);' "$CLIENT_TIMEZONE")"
CLIENT_PRESET_PHP="$(php -r 'echo var_export($argv[1], true);' "$CLIENT_PRESET")"

mkdir -p "$TEMP_CLIENT_DIR/config"
mkdir -p "$TEMP_CLIENT_DIR/resources/views"
mkdir -p "$TEMP_CLIENT_DIR/resources/images/raw"

cp -R "$PRESET_TEMPLATE_DIR/." "$TEMP_CLIENT_DIR/"

cat > "$TEMP_CLIENT_DIR/.gitignore" <<'EOF_GITIGNORE'
.env
.env.*
!.env.example
EOF_GITIGNORE

cat > "$TEMP_CLIENT_DIR/config/client.php" <<EOF_CLIENT
<?php

return [
    'name' => $CLIENT_NAME_PHP,
    'key' => $CLIENT_KEY_PHP,

    'timezone' => $CLIENT_TIMEZONE_PHP,

    'preset' => $CLIENT_PRESET_PHP,
];
EOF_CLIENT

cat > "$TEMP_CLIENT_DIR/resources/images/manifest.json" <<'EOF_MANIFEST'
{}
EOF_MANIFEST

ENV_TEMPLATE="$ROOT_DIR/docs/config-templates/client-environment.example"

if [[ ! -f "$ENV_TEMPLATE" ]]; then
  echo "Canonical client environment reference is missing: $ENV_TEMPLATE"
  exit 1
fi

cp "$ENV_TEMPLATE" "$TEMP_CLIENT_DIR/.env.example"

CLIENT_MODULES="$(
  php -r '
$config = require $argv[1];
$enabled = $config["enabled"] ?? null;

if (! is_array($enabled) || $enabled === []) {
    fwrite(STDERR, "Preset modules.php must contain a non-empty enabled array.\n");
    exit(1);
}

foreach ($enabled as $module) {
    if (! is_string($module) || trim($module) === "") {
        fwrite(STDERR, "Preset modules.php contains an invalid enabled module.\n");
        exit(1);
    }
}

echo implode(", ", $enabled);
' "$TEMP_CLIENT_DIR/config/modules.php"
)"

cat > "$TEMP_CLIENT_DIR/README.md" <<EOF_README
# $CLIENT_NAME

Engage Core client configuration, content, views, and deployment-reference environment documentation.

Repository: \`$GITHUB_REPOSITORY\`

## Client package

Client key: \`$CLIENT_KEY\`

Timezone: \`$CLIENT_TIMEZONE\`

Preset: \`$CLIENT_PRESET\`

Explicit modules: \`$CLIENT_MODULES\`

The selected preset was generated from the canonical template at:

\`docs/config-templates/client-presets/$CLIENT_PRESET\`

Review client-specific business behavior before deployment, but do not replace the
selected starter package with another client's configuration.

## Environment model

\`.env.example\` is an exhaustive selected-client reference. It is not a runtime
template and must not be copied wholesale to \`.env\`.

The committed client/module configuration determines the runtime requirement set.
Select this client in the root runtime environment first. If root \`.env\` already
contains another \`CLIENT_KEY\`, change that one runtime value deliberately before
continuing. When root \`.env\` does not exist yet, an explicit process value can
bootstrap the first sync:

\`\`\`bash
CLIENT_KEY=$CLIENT_KEY php artisan engage:environment:sync --write-missing
\`\`\`

That command adds only missing required variable names to the correct root/client
environment file. It never invents secret values or overwrites existing values. A
persisted \`CLIENT_KEY\` that disagrees with the selected client is reported as a
blocking mismatch instead of being silently changed.

Populate the reported blank values. If \`APP_KEY\` was added blank, generate it
first:

\`\`\`bash
php artisan key:generate
\`\`\`

Then run:

\`\`\`bash
php artisan optimize:clear
php artisan engage:deployment-plan
php artisan engage:install
php artisan modules:status
\`\`\`

\`engage:install\` already runs setup validation; a second standalone
\`setup:validate\` is only needed when diagnosing setup state separately.

## File permissions

The client scaffold uses:

\`\`\`text
directories: 2750
files:       0640
group:       $WEB_GROUP
\`\`\`

The setgid directory bit keeps newly created files in the PHP-FPM group. Runtime
\`.env\` files created by the environment synchronizer default to mode \`0640\`;
deployment must also ensure the deploy user and PHP-FPM identity can read them
without making secrets world-readable.

## Configuration ownership

\`config/**\`
: Stable client product behavior and version-controlled overrides authored in development.

Client \`.env\`
: Deployment-specific client values and secrets required by the committed build.
The generated \`.gitignore\` excludes runtime environment files while retaining
\`.env.example\`.

Root \`.env\`
: Application/process infrastructure and the active \`CLIENT_KEY\`.

Staging and production are deployment targets. Do not edit source/config there;
deploy committed development changes and reconcile only runtime environment/host
state.
EOF_README

php -l "$TEMP_CLIENT_DIR/config/client.php" >/dev/null

while IFS= read -r php_file; do
  php -l "$php_file" >/dev/null
done < <(
  find "$TEMP_CLIENT_DIR/config" \
    -type f \
    -name '*.php' \
    -print \
    | sort
)

php -r '
$json = file_get_contents($argv[1]);
json_decode($json, true, 512, JSON_THROW_ON_ERROR);
' "$TEMP_CLIENT_DIR/resources/images/manifest.json"

# mktemp creates the temporary client directory as 0700. Before publishing the
# client, make it traversable/readable by the PHP-FPM group while keeping it
# closed to all other users. The setgid bit preserves the web group on new files.
if ! chgrp -R "$WEB_GROUP" "$TEMP_CLIENT_DIR" 2>/dev/null; then
  if ! command -v sudo >/dev/null 2>&1; then
    echo "Unable to assign the client directory to group '$WEB_GROUP'."
    echo "Install sudo, run as root, or add the current user to that group."
    exit 1
  fi

  sudo chgrp -R "$WEB_GROUP" "$TEMP_CLIENT_DIR"
fi

find "$TEMP_CLIENT_DIR" -type d -exec chmod 2750 {} +
find "$TEMP_CLIENT_DIR" -type f -exec chmod 0640 {} +

# Initialize and commit while the complete selected preset is still in the
# temporary location. A failed GitHub repository create therefore does not
# publish a partial client under client/.
git -C "$TEMP_CLIENT_DIR" init -q
git -C "$TEMP_CLIENT_DIR" config user.name "$GIT_AUTHOR_NAME"
git -C "$TEMP_CLIENT_DIR" config user.email "$GIT_AUTHOR_EMAIL"
git -C "$TEMP_CLIENT_DIR" add .
git -C "$TEMP_CLIENT_DIR" commit -q -m "feat: initialize client"
git -C "$TEMP_CLIENT_DIR" branch -M main

echo
echo "Creating private GitHub repository: $GITHUB_REPOSITORY"
if ! gh repo create "$GITHUB_REPOSITORY" --private; then
  echo "GitHub repository creation failed. No client directory was published."
  exit 1
fi

git -C "$TEMP_CLIENT_DIR" remote add origin "$GITHUB_SSH_URL"

if ! git -C "$TEMP_CLIENT_DIR" push -u origin main; then
  echo
  echo "GitHub repository was created, but the initial push failed."
  echo "Preserving the complete client package locally so the push can be repaired without recreating it."

  mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"
  TEMP_CLIENT_DIR=""
  trap - EXIT

  echo "Client: $CLIENT_DIR"
  echo "Preset: $CLIENT_PRESET"
  echo "Repository: $GITHUB_SSH_URL"
  echo "Retry after fixing Git authentication:"
  echo "  git -C client/$CLIENT_KEY push -u origin main"
  exit 1
fi

mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"
TEMP_CLIENT_DIR=""
trap - EXIT

LOCAL_BOOTSTRAP="$ROOT_DIR/scripts/bootstrap-client-local.sh"

if [[ ! -x "$LOCAL_BOOTSTRAP" ]]; then
  echo
  echo "Client source and GitHub repository were created successfully, but the local bootstrap script is missing or not executable:"
  echo "  $LOCAL_BOOTSTRAP"
  echo "Repair the Core checkout, then run:"
  echo "  ./scripts/bootstrap-client-local.sh $CLIENT_KEY"
  exit 1
fi

echo
echo "Bootstrapping local runtime for $CLIENT_KEY..."
if ! "$LOCAL_BOOTSTRAP" "$CLIENT_KEY"; then
  echo
  echo "Client source and GitHub repository remain intact."
  echo "After fixing the reported local-runtime issue, retry:"
  echo "  ./scripts/bootstrap-client-local.sh $CLIENT_KEY"
  exit 1
fi

cat <<EOF_DONE
Created client: $CLIENT_DIR
Name: $CLIENT_NAME
Timezone: $CLIENT_TIMEZONE
Preset: $CLIENT_PRESET
Modules: $CLIENT_MODULES
Repository: $GITHUB_SSH_URL
Branch: main
Visibility: private
Permissions: directories 2750; files 0640; group $WEB_GROUP
Local runtime: ready and selected

Next:
  # Review the client locally.
  # Then use the deployment launcher to create the first staging/production runtime.
EOF_DONE
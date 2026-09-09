#!/usr/bin/env bash

set -euo pipefail

# Proven local development baseline: group-writable source, world-readable/traversable.
umask 0002

usage() {
  cat <<'EOF'
Usage:
  ./scripts/create-client.sh client-key timezone [module ...] [options]

Examples:
  ./scripts/create-client.sh sample-client-core America/Chicago
  ./scripts/create-client.sh sample-client-core America/Chicago tasks scheduling messaging
  ./scripts/create-client.sh sample-client-core America/Chicago --name "Sample Client" --create-repo
  ./scripts/create-client.sh sample-client-core America/Chicago --repo-url git@github.com:ImagineSocialGit/sample-client-core.git
  ./scripts/create-client.sh sample-client-core America/Chicago --no-local-env

Options:
  --name NAME           Client display name. Defaults to a title-cased client key.
  --preset KEY          Initial client preset. Defaults to basic.
  --create-repo         Create a private GitHub repository, make the initial commit,
                        add origin, and push the initial branch.
  --github-owner OWNER  GitHub owner used by --create-repo. Defaults to ImagineSocialGit.
  --repo-url URL        Add an already-existing repository URL as origin instead of
                        creating a GitHub repository.
  --branch NAME         Initial client repository branch. Defaults to main.
  --local-env           Require local client .env initialization after scaffold creation.
  --no-local-env        Skip local client .env initialization. By default it runs
                        automatically when the root .env exists with APP_ENV=local.
  --dry-run             Validate and print the planned scaffold without writing files.
  -h, --help            Show this help.

Notes:
  - The client key is also the repository slug. Existing --repo-url basenames must
    match the client key so deployment identity remains deterministic.
  - --create-repo always creates a private repository.
  - Core/always-on modules are never written to the client's enabled list.
  - Optional modules may be supplied here or added later with add-client-modules.sh.
  - On a configured local checkout, an ignored client .env is initialized with safe
    local defaults so the new client can be smoke-tested immediately.
  - This script creates development/source configuration only. Runtime environment,
    database, provider, Nginx, TLS, Horizon, and Scheduler setup belongs to
    scripts/operations/launch-client-environment.sh.
EOF
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

repo_slug_from_url() {
  local value="${1%/}"
  local tail="${value##*/}"
  tail="${tail%.git}"
  printf '%s' "$tail"
}

CLIENT_KEY=""
CLIENT_TIMEZONE=""
CLIENT_NAME=""
CLIENT_PRESET="basic"
CREATE_REPO=false
GITHUB_OWNER="ImagineSocialGit"
REPO_URL=""
GIT_BRANCH="main"
LOCAL_ENV_MODE="auto"
DRY_RUN=false
REQUESTED_MODULES=()

while (($#)); do
  case "$1" in
    --name)
      (($# >= 2)) || fail "--name requires a value."
      CLIENT_NAME="$2"
      shift 2
      ;;
    --preset)
      (($# >= 2)) || fail "--preset requires a value."
      CLIENT_PRESET="$2"
      shift 2
      ;;
    --create-repo)
      CREATE_REPO=true
      shift
      ;;
    --github-owner)
      (($# >= 2)) || fail "--github-owner requires a value."
      GITHUB_OWNER="$2"
      shift 2
      ;;
    --repo-url)
      (($# >= 2)) || fail "--repo-url requires a value."
      REPO_URL="$2"
      shift 2
      ;;
    --branch)
      (($# >= 2)) || fail "--branch requires a value."
      GIT_BRANCH="$2"
      shift 2
      ;;
    --local-env)
      LOCAL_ENV_MODE="required"
      shift
      ;;
    --no-local-env)
      LOCAL_ENV_MODE="disabled"
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
      if [[ -z "$CLIENT_KEY" ]]; then
        CLIENT_KEY="$1"
      elif [[ -z "$CLIENT_TIMEZONE" ]]; then
        CLIENT_TIMEZONE="$1"
      else
        REQUESTED_MODULES+=("$1")
      fi
      shift
      ;;
  esac
done

if [[ -z "$CLIENT_KEY" || -z "$CLIENT_TIMEZONE" ]]; then
  usage
  exit 2
fi

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  fail "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
fi

if [[ ! "$CLIENT_PRESET" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  fail "Preset key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
fi

if [[ ! "$GIT_BRANCH" =~ ^[A-Za-z0-9._/-]+$ ]]; then
  fail "Git branch contains unsupported characters: $GIT_BRANCH"
fi

if [[ ! "$GITHUB_OWNER" =~ ^[A-Za-z0-9][A-Za-z0-9_-]*$ ]]; then
  fail "GitHub owner contains unsupported characters: $GITHUB_OWNER"
fi

if [[ "$CREATE_REPO" == true && -n "$REPO_URL" ]]; then
  fail "Use either --create-repo or --repo-url, not both."
fi

if [[ -n "$REPO_URL" ]]; then
  REPO_SLUG="$(repo_slug_from_url "$REPO_URL")"
  [[ "$REPO_SLUG" == "$CLIENT_KEY" ]] \
    || fail "Repository basename [$REPO_SLUG] must match client key [$CLIENT_KEY]."
fi

command -v php >/dev/null 2>&1 || fail "PHP is required."
command -v git >/dev/null 2>&1 || fail "Git is required."

if [[ "$CREATE_REPO" == true ]]; then
  command -v gh >/dev/null 2>&1 \
    || fail "GitHub CLI (gh) is required for --create-repo. Install/authenticate gh or use --repo-url with an existing repository."

  gh auth status -h github.com >/dev/null 2>&1 \
    || fail "GitHub CLI is not authenticated for github.com. Run: gh auth login"

  if gh repo view "$GITHUB_OWNER/$CLIENT_KEY" >/dev/null 2>&1; then
    fail "GitHub repository already exists: $GITHUB_OWNER/$CLIENT_KEY"
  fi
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
ROOT_MODULES_FILE="$ROOT_DIR/config/modules.php"
ENV_TEMPLATE="$ROOT_DIR/docs/config-templates/client-environment.example"
LOCAL_ENV_SCRIPT="$ROOT_DIR/scripts/init-client-local-env.sh"

[[ -f "$ROOT_DIR/artisan" ]] || fail "Run this script from an Engage Core checkout."
[[ -f "$ROOT_MODULES_FILE" ]] || fail "Root module config is missing: $ROOT_MODULES_FILE"
[[ -f "$ENV_TEMPLATE" ]] || fail "Canonical client environment reference is missing: $ENV_TEMPLATE"
[[ -f "$LOCAL_ENV_SCRIPT" ]] || fail "Local client environment helper is missing: $LOCAL_ENV_SCRIPT"
[[ ! -e "$CLIENT_DIR" ]] || fail "Client already exists: $CLIENT_DIR"

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

CLIENT_NAME="$(printf '%s' "$CLIENT_NAME" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
[[ -n "$CLIENT_NAME" ]] || fail "Client display name cannot be blank."

LOCAL_ENV_PLANNED=false
LOCAL_ENV_REASON="disabled by --no-local-env"

if [[ "$LOCAL_ENV_MODE" != "disabled" ]]; then
  if [[ -f "$ROOT_DIR/.env" ]]; then
    ROOT_APP_ENV="$(
      awk -F= '
        $1 ~ /^[[:space:]]*APP_ENV[[:space:]]*$/ {
          value = substr($0, index($0, "=") + 1)
          gsub(/^[[:space:]]+|[[:space:]]+$/, "", value)
          gsub(/^"|"$/, "", value)
          gsub(/^\047|\047$/, "", value)
          print value
          exit
        }
      ' "$ROOT_DIR/.env"
    )"

    if [[ "$ROOT_APP_ENV" == "local" ]]; then
      LOCAL_ENV_PLANNED=true
      LOCAL_ENV_REASON="root APP_ENV=local"
    elif [[ "$LOCAL_ENV_MODE" == "required" ]]; then
      fail "--local-env requires root .env APP_ENV=local; resolved [$ROOT_APP_ENV]."
    else
      LOCAL_ENV_REASON="root APP_ENV is not local"
    fi
  elif [[ "$LOCAL_ENV_MODE" == "required" ]]; then
    fail "--local-env requires an existing root .env with APP_ENV=local."
  else
    LOCAL_ENV_REASON="root .env is not present"
  fi
fi

if [[ "$CREATE_REPO" == true ]]; then
  DEPLOY_REPO_URL="git@github.com:$GITHUB_OWNER/$CLIENT_KEY.git"
elif [[ -n "$REPO_URL" ]]; then
  DEPLOY_REPO_URL="$REPO_URL"
else
  DEPLOY_REPO_URL="<client-repo-url>"
fi

MODULE_RESULT="$(mktemp)"
TEMP_CLIENT_DIR=""

cleanup() {
  rm -f "$MODULE_RESULT"

  if [[ -n "$TEMP_CLIENT_DIR" && -d "$TEMP_CLIENT_DIR" ]]; then
    rm -rf "$TEMP_CLIENT_DIR"
  fi
}

trap cleanup EXIT

if ! php -r '
array_shift($argv);
$configFile = array_shift($argv);
$requested = array_values(array_unique(array_filter(
    $argv,
    static fn (mixed $value): bool => is_string($value) && trim($value) !== ""
)));

$config = require $configFile;
$definitions = $config["modules"] ?? null;

if (! is_array($definitions) || $definitions === []) {
    fwrite(STDERR, "Root module definitions are missing.\n");
    exit(1);
}

$selectable = [];

foreach ($definitions as $key => $definition) {
    if (! is_string($key) || $key === "") {
        continue;
    }

    if (is_array($definition) && ! empty($definition["always_on"])) {
        continue;
    }

    $selectable[] = $key;
}

foreach ($requested as $key) {
    if (! in_array($key, $selectable, true)) {
        fwrite(STDERR, "Unknown or always-on module: {$key}\n");
        fwrite(STDERR, "Available optional modules: ".implode(", ", $selectable)."\n");
        exit(1);
    }
}

$lookup = array_fill_keys($requested, true);

foreach ($selectable as $key) {
    if (isset($lookup[$key])) {
        echo $key.PHP_EOL;
    }
}
' "$ROOT_MODULES_FILE" "${REQUESTED_MODULES[@]}" > "$MODULE_RESULT"; then
  exit 1
fi

mapfile -t INITIAL_MODULES < "$MODULE_RESULT"

if [[ "$DRY_RUN" == true ]]; then
  cat <<EOF
Client scaffold dry run
Client key: $CLIENT_KEY
Name: $CLIENT_NAME
Timezone: $CLIENT_TIMEZONE
Preset: $CLIENT_PRESET
Optional modules: $([[ ${#INITIAL_MODULES[@]} -eq 0 ]] && echo "(none; Core only)" || printf '%s' "${INITIAL_MODULES[*]}")
Git branch: $GIT_BRANCH
GitHub create: $CREATE_REPO
GitHub owner: $GITHUB_OWNER
Origin/deployment repo: $DEPLOY_REPO_URL
Visibility: $([[ "$CREATE_REPO" == true ]] && echo "private" || echo "not changed by this script")
Local env initialization: $LOCAL_ENV_PLANNED ($LOCAL_ENV_REASON)
Target: $CLIENT_DIR
EOF
  exit 0
fi

mkdir -p "$CLIENTS_DIR"
TEMP_CLIENT_DIR="$(mktemp -d "$CLIENTS_DIR/.${CLIENT_KEY}.creating.XXXXXX")"
# mktemp creates the top-level directory as 0700 regardless of umask. The known-good
# local client baseline uses a 0775 client root so PHP-FPM can traverse client source.
chmod 0775 "$TEMP_CLIENT_DIR"

mkdir -p "$TEMP_CLIENT_DIR/config"
mkdir -p "$TEMP_CLIENT_DIR/resources/views"
mkdir -p "$TEMP_CLIENT_DIR/resources/images/raw"

php -r '
[$path, $name, $key, $timezone, $preset] = array_slice($argv, 1);

$content = "<?php\n\nreturn [\n"
    ."    '\''name'\'' => ".var_export($name, true).",\n"
    ."    '\''key'\'' => ".var_export($key, true).",\n\n"
    ."    '\''timezone'\'' => ".var_export($timezone, true).",\n\n"
    ."    '\''preset'\'' => ".var_export($preset, true).",\n"
    ."];\n";

file_put_contents($path, $content);
' \
  "$TEMP_CLIENT_DIR/config/client.php" \
  "$CLIENT_NAME" \
  "$CLIENT_KEY" \
  "$CLIENT_TIMEZONE" \
  "$CLIENT_PRESET"

{
  printf '%s\n' '<?php'
  printf '\n'
  printf '%s\n' 'return ['
  printf '%s\n' "    'enabled' => ["

  for module in "${INITIAL_MODULES[@]}"; do
    printf "        '%s',\n" "$module"
  done

  printf '%s\n' '    ],'
  printf '%s\n' '];'
} > "$TEMP_CLIENT_DIR/config/modules.php"

cat > "$TEMP_CLIENT_DIR/resources/images/manifest.json" <<'EOF_MANIFEST'
{}
EOF_MANIFEST

cp "$ENV_TEMPLATE" "$TEMP_CLIENT_DIR/.env.example"

cat > "$TEMP_CLIENT_DIR/.gitignore" <<'EOF_GITIGNORE'
.env
.env.*
!.env.example
.DS_Store
EOF_GITIGNORE

{
  printf '# %s\n\n' "$CLIENT_NAME"
  cat <<EOF_README
Client-owned configuration, content, views, and deployment-reference environment documentation.

## Identity

- Client key: \`$CLIENT_KEY\`
- Timezone: \`$CLIENT_TIMEZONE\`
- Preset: \`$CLIENT_PRESET\`
- Repository slug: \`$CLIENT_KEY\`

## Optional modules

The client starts with only the optional modules explicitly selected during creation. Core/always-on modules are resolved by the platform and do not belong in \`config/modules.php\`.

From the Engage Core repository root:

\`\`\`bash
./scripts/add-client-modules.sh $CLIENT_KEY --list
./scripts/add-client-modules.sh $CLIENT_KEY module [module ...]
\`\`\`

Module selection is source configuration. Provider credentials and deployment environment requirements are resolved later by the deployment plan and environment launcher.

## Environment model

\`.env.example\` is the canonical selected-client environment reference. Do not commit a runtime \`.env\`.

Stable client behavior belongs in \`config/**\`. Deployment-specific values and secrets belong in the selected-client runtime \`.env\`.

## Local development

When this client is created from a configured local Core checkout, \`create-client.sh\` initializes an ignored local \`.env\` automatically. For an existing client or a skipped initialization, run from the Core repository root:

\`\`\`bash
./scripts/init-client-local-env.sh $CLIENT_KEY
\`\`\`

The local helper selects the client in the root \`.env\`, uses the standard local MySQL defaults (\`devUser\` / \`password\`), derives a client-specific database and runtime prefixes, and uses the Messaging development sink when Messaging is enabled. It never creates/drops a database or runs migrations.

## Repository workflow

This directory is its own Git repository and is versioned independently from Engage Core.

Repository identity must stay aligned with the client key:

\`\`\`text
client key: $CLIENT_KEY
repo slug:  $CLIENT_KEY
\`\`\`

When the repository was not created/pushed by create-client.sh, review the scaffold, commit it, connect the private remote, and push the branch before staging deployment.

## Staging / production

Do not edit source/config directly on staging or production.

After this client repository is committed and pushed, start the current Core deployment orchestrator from the Core checkout:

\`\`\`bash
bash scripts/operations/launch-client-environment.sh new \\
  --environment staging \\
  --client-repo $DEPLOY_REPO_URL \\
  --root-domain <staging-root-domain> \\
  --topology core_services_only
\`\`\`

Use \`--topology managed_main_site --main-site-type seo\` when the organization also owns the root website through the SEO platform.
EOF_README
} > "$TEMP_CLIENT_DIR/README.md"

php -l "$TEMP_CLIENT_DIR/config/client.php" >/dev/null
php -l "$TEMP_CLIENT_DIR/config/modules.php" >/dev/null

php -r '
$json = file_get_contents($argv[1]);
json_decode($json, true, 512, JSON_THROW_ON_ERROR);
' "$TEMP_CLIENT_DIR/resources/images/manifest.json"

if git init -q -b "$GIT_BRANCH" "$TEMP_CLIENT_DIR" 2>/dev/null; then
  :
else
  git init -q "$TEMP_CLIENT_DIR"
  git -C "$TEMP_CLIENT_DIR" checkout -q -b "$GIT_BRANCH"
fi

if [[ -n "$REPO_URL" ]]; then
  git -C "$TEMP_CLIENT_DIR" remote add origin "$REPO_URL"
fi

mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"
chmod 0775 "$CLIENT_DIR"
TEMP_CLIENT_DIR=""
trap - EXIT
rm -f "$MODULE_RESULT"

LOCAL_ENV_CREATED=false

if [[ "$LOCAL_ENV_PLANNED" == true ]]; then
  echo
  echo "Initializing ignored local client environment..."

  if ! bash "$LOCAL_ENV_SCRIPT" "$CLIENT_KEY"; then
    cat >&2 <<EOF_LOCAL_ENV_FAIL

The client source scaffold was preserved at:
  $CLIENT_DIR

Local environment initialization failed before any GitHub repository was created.
Fix the reported local-environment issue, then run:
  ./scripts/init-client-local-env.sh $CLIENT_KEY
EOF_LOCAL_ENV_FAIL
    exit 1
  fi

  LOCAL_ENV_CREATED=true
fi

if [[ "$CREATE_REPO" == true ]]; then
  echo
  echo "Creating initial client commit..."
  git -C "$CLIENT_DIR" add .

  if ! git -C "$CLIENT_DIR" commit -m "chore: initialize $CLIENT_KEY client"; then
    cat >&2 <<EOF_COMMIT_FAIL

The client scaffold was preserved at:
  $CLIENT_DIR

Git could not create the initial commit. Configure your Git author identity, then run:
  git -C "$CLIENT_DIR" add .
  git -C "$CLIENT_DIR" commit -m "chore: initialize $CLIENT_KEY client"

No GitHub repository was created.
EOF_COMMIT_FAIL
    exit 1
  fi

  echo
  echo "Creating private GitHub repository: $GITHUB_OWNER/$CLIENT_KEY"

  if ! gh repo create "$GITHUB_OWNER/$CLIENT_KEY" \
    --private \
    --source "$CLIENT_DIR" \
    --remote origin \
    --push \
    --description "$CLIENT_NAME Core client configuration"; then
    cat >&2 <<EOF_GITHUB_FAIL

The local client repository and initial commit were preserved at:
  $CLIENT_DIR

GitHub repository creation/push did not complete cleanly. Check whether the remote
was created before retrying:
  gh repo view "$GITHUB_OWNER/$CLIENT_KEY"
  git -C "$CLIENT_DIR" remote -v

If the remote exists, finish with:
  git -C "$CLIENT_DIR" push -u origin "$GIT_BRANCH"
EOF_GITHUB_FAIL
    exit 1
  fi
fi

ORIGIN="$(git -C "$CLIENT_DIR" remote get-url origin 2>/dev/null || true)"

cat <<EOF_DONE
Created client source repository: $CLIENT_DIR
Name: $CLIENT_NAME
Timezone: $CLIENT_TIMEZONE
Preset: $CLIENT_PRESET
Optional modules: $([[ ${#INITIAL_MODULES[@]} -eq 0 ]] && echo "(none; Core only)" || printf '%s' "${INITIAL_MODULES[*]}")
Branch: $GIT_BRANCH
Origin: ${ORIGIN:-"(not configured)"}
GitHub private repo created/pushed: $CREATE_REPO
Local .env initialized: $LOCAL_ENV_CREATED

Next:
  ./scripts/add-client-modules.sh $CLIENT_KEY --list
  ./scripts/add-client-modules.sh $CLIENT_KEY module [module ...]

For local smoke testing (if the local .env was skipped or this client already existed):
  ./scripts/init-client-local-env.sh $CLIENT_KEY

After any module/config changes:
  git -C "$CLIENT_DIR" status
  git -C "$CLIENT_DIR" add .
  git -C "$CLIENT_DIR" commit -m "chore: configure $CLIENT_KEY"
  git -C "$CLIENT_DIR" push

Then launch staging with scripts/operations/launch-client-environment.sh new.
EOF_DONE
#!/usr/bin/env bash

set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./scripts/add-client-modules.sh client-key module [module ...]
  ./scripts/add-client-modules.sh client-key --list

Examples:
  ./scripts/add-client-modules.sh sample-musician messaging campaigns
  ./scripts/add-client-modules.sh sample-musician webinars broadcasts
  ./scripts/add-client-modules.sh sample-musician --list

Options:
  --list              Show available modules and the client's current selection.
  --dry-run           Show the resulting enabled-module list without writing it.
  --skip-validation   Save the change without running setup:validate.
  -h, --help          Show this help.

Environment:
  ENGAGE_CORE_WEB_GROUP
      PHP-FPM/web-server group. Defaults to www-data.
EOF
}

CLIENT_KEY="${1:-}"

if [[ -z "$CLIENT_KEY" ]]; then
  usage
  exit 1
fi

if [[ "$CLIENT_KEY" == "-h" || "$CLIENT_KEY" == "--help" ]]; then
  usage
  exit 0
fi

shift

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  echo "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
  exit 1
fi

LIST_ONLY=false
DRY_RUN=false
RUN_VALIDATION=true
REQUESTED_MODULES=()

for argument in "$@"; do
  case "$argument" in
    --list)
      LIST_ONLY=true
      ;;
    --dry-run)
      DRY_RUN=true
      ;;
    --skip-validation)
      RUN_VALIDATION=false
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      echo "Unknown option: $argument"
      usage
      exit 1
      ;;
    *)
      REQUESTED_MODULES+=("$argument")
      ;;
  esac
done

if [[ "$LIST_ONLY" == false && ${#REQUESTED_MODULES[@]} -eq 0 ]]; then
  echo "Provide at least one module key, or use --list."
  usage
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "PHP is required."
  exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY"
MODULES_FILE="$CLIENT_DIR/config/modules.php"
ARTISAN="$ROOT_DIR/artisan"
WEB_GROUP="${ENGAGE_CORE_WEB_GROUP:-www-data}"

if [[ ! -d "$CLIENT_DIR" ]]; then
  echo "Client does not exist: $CLIENT_DIR"
  exit 1
fi

if [[ ! -f "$MODULES_FILE" ]]; then
  echo "Client modules config does not exist: $MODULES_FILE"
  exit 1
fi

if [[ ! -f "$ROOT_DIR/vendor/autoload.php" ]]; then
  echo "Composer dependencies are missing. Run composer install first."
  exit 1
fi

if [[ ! -f "$ROOT_DIR/bootstrap/app.php" || ! -f "$ARTISAN" ]]; then
  echo "Run this script from an Engage Core checkout with bootstrap/app.php and artisan."
  exit 1
fi

if ! getent group "$WEB_GROUP" >/dev/null 2>&1; then
  echo "Web server group does not exist: $WEB_GROUP"
  echo "Set ENGAGE_CORE_WEB_GROUP when PHP-FPM uses a different group."
  exit 1
fi

HELPER_FILE="$(mktemp)"
RESULT_FILE="$(mktemp)"
BACKUP_FILE=""

cleanup() {
  rm -f "$HELPER_FILE" "$RESULT_FILE"

  if [[ -n "$BACKUP_FILE" && -f "$BACKUP_FILE" ]]; then
    rm -f "$BACKUP_FILE"
  fi
}

trap cleanup EXIT

cat > "$HELPER_FILE" <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;

array_shift($argv); // Script path.
$rootDir = (string) array_shift($argv);
$clientKey = (string) array_shift($argv);
$modulesFile = (string) array_shift($argv);
$resultFile = (string) array_shift($argv);
$dryRunValue = (string) array_shift($argv);
$listOnlyValue = (string) array_shift($argv);
$requestedModules = array_values($argv);

$dryRun = $dryRunValue === '1';
$listOnly = $listOnlyValue === '1';

putenv("CLIENT_KEY={$clientKey}");
$_ENV['CLIENT_KEY'] = $clientKey;
$_SERVER['CLIENT_KEY'] = $clientKey;

require $rootDir.'/vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require $rootDir.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$definitions = config('modules.modules', []);

if (! is_array($definitions) || $definitions === []) {
    fwrite(STDERR, "No module definitions were loaded from config('modules.modules').\n");
    exit(1);
}

$availableKeys = array_keys($definitions);
$selectableKeys = array_values(array_filter(
    $availableKeys,
    static fn (string $key): bool => $key !== 'core'
));

$currentConfig = require $modulesFile;
$currentEnabled = $currentConfig['enabled'] ?? [];

if (! is_array($currentEnabled)) {
    fwrite(STDERR, "Client modules.php must contain an enabled array.\n");
    exit(1);
}

foreach ($currentEnabled as $moduleKey) {
    if (! is_string($moduleKey) || $moduleKey === '') {
        fwrite(STDERR, "Client modules.php contains an invalid enabled module value.\n");
        exit(1);
    }

    if (! in_array($moduleKey, $selectableKeys, true)) {
        fwrite(STDERR, "Client modules.php contains an unknown module: {$moduleKey}\n");
        exit(1);
    }
}

$currentEnabled = array_values(array_unique($currentEnabled));

if ($listOnly) {
    $enabledLookup = array_fill_keys($currentEnabled, true);

    echo "Available modules for {$clientKey}:\n\n";

    foreach ($selectableKeys as $moduleKey) {
        $definition = $definitions[$moduleKey] ?? [];
        $name = (string) ($definition['name'] ?? $moduleKey);
        $dependencies = array_values(array_filter(
            (array) ($definition['depends_on'] ?? []),
            static fn (mixed $dependency): bool => is_string($dependency) && $dependency !== 'core'
        ));

        printf(
            "  %-26s %-34s %s%s\n",
            $moduleKey,
            $name,
            isset($enabledLookup[$moduleKey]) ? '[enabled]' : '[available]',
            $dependencies === [] ? '' : ' depends on: '.implode(', ', $dependencies)
        );
    }

    file_put_contents($resultFile, json_encode([
        'changed' => false,
        'enabled' => $currentEnabled,
    ], JSON_THROW_ON_ERROR));

    exit(0);
}

$requestedModules = array_values(array_unique($requestedModules));

foreach ($requestedModules as $moduleKey) {
    if ($moduleKey === 'core') {
        fwrite(STDERR, "Core is always enabled and must not be added to the client enabled list.\n");
        exit(1);
    }

    if (! in_array($moduleKey, $selectableKeys, true)) {
        fwrite(STDERR, "Unknown module: {$moduleKey}\n");
        fwrite(STDERR, 'Available modules: '.implode(', ', $selectableKeys)."\n");
        exit(1);
    }
}

$requestedLookup = array_fill_keys($requestedModules, true);
$currentLookup = array_fill_keys($currentEnabled, true);
$combinedLookup = $currentLookup + $requestedLookup;

$nextEnabled = [];

foreach ($selectableKeys as $moduleKey) {
    if (isset($combinedLookup[$moduleKey])) {
        $nextEnabled[] = $moduleKey;
    }
}

$added = array_values(array_filter(
    $nextEnabled,
    static fn (string $moduleKey): bool => ! isset($currentLookup[$moduleKey])
));

$alreadyEnabled = array_values(array_filter(
    $requestedModules,
    static fn (string $moduleKey): bool => isset($currentLookup[$moduleKey])
));

$changed = $nextEnabled !== $currentEnabled;

echo "Client: {$clientKey}\n";
echo 'Current modules: '.($currentEnabled === [] ? '(none)' : implode(', ', $currentEnabled))."\n";
echo 'Requested modules: '.implode(', ', $requestedModules)."\n";
echo 'Resulting modules: '.($nextEnabled === [] ? '(none)' : implode(', ', $nextEnabled))."\n";

if ($added !== []) {
    echo 'Added: '.implode(', ', $added)."\n";
}

if ($alreadyEnabled !== []) {
    echo 'Already enabled: '.implode(', ', $alreadyEnabled)."\n";
}

if (! $changed) {
    echo "No config change is required.\n";
} elseif ($dryRun) {
    echo "Dry run only; modules.php was not changed.\n";
} else {
    $lines = [
        '<?php',
        '',
        'return [',
        "    'enabled' => [",
    ];

    foreach ($nextEnabled as $moduleKey) {
        $lines[] = "        '{$moduleKey}',";
    }

    array_push(
        $lines,
        '    ],',
        '];',
        ''
    );

    $content = implode(PHP_EOL, $lines);
    $temporaryFile = tempnam(dirname($modulesFile), '.modules.php.');

    if ($temporaryFile === false) {
        fwrite(STDERR, "Unable to create a temporary modules config file.\n");
        exit(1);
    }

    try {
        if (file_put_contents($temporaryFile, $content, LOCK_EX) === false) {
            fwrite(STDERR, "Unable to write the temporary modules config file.\n");
            exit(1);
        }

        if (! rename($temporaryFile, $modulesFile)) {
            fwrite(STDERR, "Unable to replace the client modules config atomically.\n");
            exit(1);
        }
    } finally {
        if (is_file($temporaryFile)) {
            @unlink($temporaryFile);
        }
    }
}

file_put_contents($resultFile, json_encode([
    'changed' => $changed && ! $dryRun,
    'enabled' => $nextEnabled,
    'added' => $added,
    'already_enabled' => $alreadyEnabled,
], JSON_THROW_ON_ERROR));
PHP

cd "$ROOT_DIR"

# Remove stale effective config before booting the target client's module registry.
CLIENT_KEY="$CLIENT_KEY" php "$ARTISAN" optimize:clear >/dev/null

if [[ "$DRY_RUN" == false && "$LIST_ONLY" == false ]]; then
  BACKUP_FILE="$(mktemp "$CLIENT_DIR/config/.modules.php.backup.XXXXXX")"
  cp -p "$MODULES_FILE" "$BACKUP_FILE"
fi

php "$HELPER_FILE" \
  "$ROOT_DIR" \
  "$CLIENT_KEY" \
  "$MODULES_FILE" \
  "$RESULT_FILE" \
  "$([[ "$DRY_RUN" == true ]] && echo 1 || echo 0)" \
  "$([[ "$LIST_ONLY" == true ]] && echo 1 || echo 0)" \
  "${REQUESTED_MODULES[@]}"

CHANGED="$(
  php -r '
$result = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo ! empty($result["changed"]) ? "1" : "0";
' "$RESULT_FILE"
)"

if [[ "$LIST_ONLY" == true || "$DRY_RUN" == true || "$CHANGED" != "1" ]]; then
  exit 0
fi

set_web_group() {
  local path="$1"

  if chgrp "$WEB_GROUP" "$path" 2>/dev/null; then
    return
  fi

  if ! command -v sudo >/dev/null 2>&1; then
    echo "Unable to assign $path to group '$WEB_GROUP'."
    return 1
  fi

  sudo chgrp "$WEB_GROUP" "$path"
}

set_web_group "$MODULES_FILE"
chmod 0640 "$MODULES_FILE"

php -l "$MODULES_FILE" >/dev/null
CLIENT_KEY="$CLIENT_KEY" php "$ARTISAN" optimize:clear >/dev/null

if [[ "$RUN_VALIDATION" == true ]]; then
  echo
  echo "Running setup validation for $CLIENT_KEY..."

  if ! CLIENT_KEY="$CLIENT_KEY" php "$ARTISAN" setup:validate; then
    echo
    echo "Validation failed. Restoring the previous modules.php."

    cp -p "$BACKUP_FILE" "$MODULES_FILE"
    set_web_group "$MODULES_FILE"
    chmod 0640 "$MODULES_FILE"
    CLIENT_KEY="$CLIENT_KEY" php "$ARTISAN" optimize:clear >/dev/null

    echo "Previous module configuration restored."
    exit 1
  fi
else
  echo
  echo "Skipped setup validation by request."
fi

rm -f "$BACKUP_FILE"
BACKUP_FILE=""

echo
echo "Updated: $MODULES_FILE"
echo "No preset groups or module-specific client config files were activated automatically."

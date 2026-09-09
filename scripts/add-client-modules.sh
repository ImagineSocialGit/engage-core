#!/usr/bin/env bash

set -euo pipefail

usage() {
  cat <<'EOF'
Usage:
  ./scripts/add-client-modules.sh client-key module [module ...]
  ./scripts/add-client-modules.sh client-key --list

Examples:
  ./scripts/add-client-modules.sh sample-client scheduling tasks messaging
  ./scripts/add-client-modules.sh sample-client forms reporting
  ./scripts/add-client-modules.sh sample-client --list

Options:
  --list       Show always-on modules, optional modules, and the client's current selection.
  --dry-run    Show the resulting enabled-module list without writing it.
  -h, --help   Show this help.

Notes:
  - Core/always-on modules are never written to the client's enabled list.
  - Required provider dependencies are resolved by ModuleManager at runtime.
  - This command changes source config only. Runtime/provider readiness is checked
    later by the deployment plan and staging/production launcher.
EOF
}

fail() {
  printf 'ERROR: %s\n' "$*" >&2
  exit 1
}

CLIENT_KEY="${1:-}"

if [[ -z "$CLIENT_KEY" ]]; then
  usage
  exit 2
fi

if [[ "$CLIENT_KEY" == "-h" || "$CLIENT_KEY" == "--help" ]]; then
  usage
  exit 0
fi

shift

if [[ ! "$CLIENT_KEY" =~ ^[a-z0-9][a-z0-9_-]*$ ]]; then
  fail "Client key must start with a lowercase letter or number and contain only lowercase letters, numbers, hyphens, and underscores."
fi

LIST_ONLY=false
DRY_RUN=false
REQUESTED_MODULES=()

for argument in "$@"; do
  case "$argument" in
    --list)
      LIST_ONLY=true
      ;;
    --dry-run)
      DRY_RUN=true
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    --*)
      fail "Unknown option: $argument"
      ;;
    *)
      REQUESTED_MODULES+=("$argument")
      ;;
  esac
done

if [[ "$LIST_ONLY" == true && ${#REQUESTED_MODULES[@]} -gt 0 ]]; then
  fail "--list cannot be combined with module keys."
fi

if [[ "$LIST_ONLY" == false && ${#REQUESTED_MODULES[@]} -eq 0 ]]; then
  fail "Provide at least one module key, or use --list."
fi

command -v php >/dev/null 2>&1 || fail "PHP is required."

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLIENT_DIR="$ROOT_DIR/client/$CLIENT_KEY"
CLIENT_CONFIG="$CLIENT_DIR/config/client.php"
MODULES_FILE="$CLIENT_DIR/config/modules.php"
ROOT_MODULES_FILE="$ROOT_DIR/config/modules.php"
RESULT_FILE="$(mktemp)"

cleanup() {
  rm -f "$RESULT_FILE"
}

trap cleanup EXIT

[[ -d "$CLIENT_DIR" ]] || fail "Client does not exist: $CLIENT_DIR"
[[ -f "$CLIENT_CONFIG" ]] || fail "Client config does not exist: $CLIENT_CONFIG"
[[ -f "$MODULES_FILE" ]] || fail "Client modules config does not exist: $MODULES_FILE"
[[ -f "$ROOT_MODULES_FILE" ]] || fail "Root module config does not exist: $ROOT_MODULES_FILE"

if ! php -r '
array_shift($argv);
$clientKey = (string) array_shift($argv);
$rootModulesFile = (string) array_shift($argv);
$clientConfigFile = (string) array_shift($argv);
$modulesFile = (string) array_shift($argv);
$resultFile = (string) array_shift($argv);
$dryRun = ((string) array_shift($argv)) === "1";
$listOnly = ((string) array_shift($argv)) === "1";
$requestedModules = array_values(array_unique($argv));

$clientConfig = require $clientConfigFile;
$storedClientKey = $clientConfig["key"] ?? null;

if (! is_string($storedClientKey) || $storedClientKey !== $clientKey) {
    fwrite(
        STDERR,
        "Client identity mismatch: requested [{$clientKey}], config/client.php contains ["
            .(is_scalar($storedClientKey) ? (string) $storedClientKey : "missing")
            ."].\n"
    );
    exit(1);
}

$rootConfig = require $rootModulesFile;
$definitions = $rootConfig["modules"] ?? null;

if (! is_array($definitions) || $definitions === []) {
    fwrite(STDERR, "Root module definitions are missing.\n");
    exit(1);
}

$alwaysOn = [];
$selectable = [];

foreach ($definitions as $key => $definition) {
    if (! is_string($key) || $key === "") {
        continue;
    }

    if (is_array($definition) && ! empty($definition["always_on"])) {
        $alwaysOn[] = $key;
        continue;
    }

    $selectable[] = $key;
}

$currentConfig = require $modulesFile;
$currentEnabled = $currentConfig["enabled"] ?? [];

if (! is_array($currentEnabled)) {
    fwrite(STDERR, "Client modules.php must contain an enabled array.\n");
    exit(1);
}

foreach ($currentEnabled as $moduleKey) {
    if (! is_string($moduleKey) || trim($moduleKey) === "") {
        fwrite(STDERR, "Client modules.php contains an invalid enabled module value.\n");
        exit(1);
    }

    if (! in_array($moduleKey, $selectable, true)) {
        fwrite(STDERR, "Client modules.php contains an unknown or always-on module: {$moduleKey}\n");
        exit(1);
    }
}

$currentEnabled = array_values(array_unique($currentEnabled));

if ($listOnly) {
    $enabledLookup = array_fill_keys($currentEnabled, true);

    echo "Always-on modules:\n";
    foreach ($alwaysOn as $moduleKey) {
        $definition = is_array($definitions[$moduleKey] ?? null) ? $definitions[$moduleKey] : [];
        $name = (string) ($definition["name"] ?? $moduleKey);
        printf("  %-26s %s\n", $moduleKey, $name);
    }

    echo "\nOptional modules for {$clientKey}:\n\n";

    foreach ($selectable as $moduleKey) {
        $definition = is_array($definitions[$moduleKey] ?? null) ? $definitions[$moduleKey] : [];
        $name = (string) ($definition["name"] ?? $moduleKey);
        $dependencies = array_values(array_filter(
            (array) ($definition["depends_on"] ?? []),
            static fn (mixed $dependency): bool =>
                is_string($dependency)
                && $dependency !== ""
                && ! in_array($dependency, $alwaysOn, true)
        ));

        printf(
            "  %-26s %-34s %s%s\n",
            $moduleKey,
            $name,
            isset($enabledLookup[$moduleKey]) ? "[enabled]" : "[available]",
            $dependencies === [] ? "" : " depends on: ".implode(", ", $dependencies)
        );
    }

    file_put_contents($resultFile, json_encode([
        "changed" => false,
        "enabled" => $currentEnabled,
    ], JSON_THROW_ON_ERROR));

    exit(0);
}

foreach ($requestedModules as $moduleKey) {
    if (! in_array($moduleKey, $selectable, true)) {
        fwrite(STDERR, "Unknown or always-on module: {$moduleKey}\n");
        fwrite(STDERR, "Available optional modules: ".implode(", ", $selectable)."\n");
        exit(1);
    }
}

$currentLookup = array_fill_keys($currentEnabled, true);
$requestedLookup = array_fill_keys($requestedModules, true);
$combinedLookup = $currentLookup + $requestedLookup;
$nextEnabled = [];

foreach ($selectable as $moduleKey) {
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
echo "Current optional modules: ".($currentEnabled === [] ? "(none)" : implode(", ", $currentEnabled))."\n";
echo "Requested modules: ".implode(", ", $requestedModules)."\n";
echo "Resulting optional modules: ".($nextEnabled === [] ? "(none)" : implode(", ", $nextEnabled))."\n";

if ($added !== []) {
    echo "Added: ".implode(", ", $added)."\n";
}

if ($alreadyEnabled !== []) {
    echo "Already enabled: ".implode(", ", $alreadyEnabled)."\n";
}

if (! $changed) {
    echo "No config change is required.\n";
} elseif ($dryRun) {
    echo "Dry run only; modules.php was not changed.\n";
} else {
    $lines = [
        "<?php",
        "",
        "return [",
        "    '\''enabled'\'' => [",
    ];

    foreach ($nextEnabled as $moduleKey) {
        $lines[] = "        ".var_export($moduleKey, true).",";
    }

    array_push(
        $lines,
        "    ],",
        "];",
        ""
    );

    $content = implode(PHP_EOL, $lines);
    $temporaryFile = tempnam(dirname($modulesFile), ".modules.php.");

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
    "changed" => $changed && ! $dryRun,
    "enabled" => $nextEnabled,
    "added" => $added,
    "already_enabled" => $alreadyEnabled,
], JSON_THROW_ON_ERROR));
' \
  "$CLIENT_KEY" \
  "$ROOT_MODULES_FILE" \
  "$CLIENT_CONFIG" \
  "$MODULES_FILE" \
  "$RESULT_FILE" \
  "$([[ "$DRY_RUN" == true ]] && echo 1 || echo 0)" \
  "$([[ "$LIST_ONLY" == true ]] && echo 1 || echo 0)" \
  "${REQUESTED_MODULES[@]}"; then
  exit 1
fi

if [[ "$LIST_ONLY" == true || "$DRY_RUN" == true ]]; then
  exit 0
fi

CHANGED="$(
  php -r '
$result = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
echo ! empty($result["changed"]) ? "1" : "0";
' "$RESULT_FILE"
)"

if [[ "$CHANGED" != "1" ]]; then
  exit 0
fi

php -l "$MODULES_FILE" >/dev/null

echo
echo "Updated source config: $MODULES_FILE"
echo "No runtime environment, database, presets, provider credentials, or server state were changed."
echo "Commit/push the client repository before staging/production deployment."
#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/EngageCore_system_deployment_context_dump.txt
#
# Purpose: create a read-only handoff artifact for another development or
# deployment thread. The script never reads or appends .env files, credentials,
# private keys, database rows, Redis contents, or Project State export payloads.
# High-level client identifiers and validation diagnostics are intentionally
# included, so review the generated file before sharing it outside the project.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/file_dumps"
OUTPUT_FILE="$OUTPUT_DIR/EngageCore_system_deployment_context_dump.txt"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    echo "Error: artisan was not found under: $ROOT_DIR" >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -f "$ROOT_DIR/vendor/autoload.php" ]]; then
    echo "Error: vendor/autoload.php was not found." >&2
    echo "Run composer install before using this script." >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

FILES_LIST="$TMP_DIR/files.txt"
MISSING_LIST="$TMP_DIR/missing.txt"
: > "$FILES_LIST"
: > "$MISSING_LIST"

add_file() {
    local relative="$1"
    local absolute="$ROOT_DIR/$relative"

    if [[ -f "$absolute" ]]; then
        printf '%s\n' "$relative" >> "$FILES_LIST"
    else
        printf '%s\n' "$relative" >> "$MISSING_LIST"
    fi
}

# Command and migration architecture.
for relative in \
    bootstrap/providers.php \
    app/Providers/PlatformMigrationServiceProvider.php \
    app/Console/Commands/EngageInstallCommand.php \
    app/Console/Commands/ModulesStatusCommand.php \
    app/Console/Commands/ModulesInstallCommand.php \
    app/Console/Commands/ModulesMigrateCommand.php \
    app/Console/Commands/ModulesReconcileCommand.php \
    app/Console/Commands/SyncPresetsCommand.php \
    app/Console/Commands/ValidateSetupCommand.php \
    app/Support/Modules/ModuleManager.php \
    app/Support/Modules/Migrations/ModuleMigrationPathPolicy.php \
    app/Support/Modules/Migrations/ModuleMigrationRegistry.php \
    app/Support/Modules/Migrations/ModuleMigrationPlanner.php \
    app/Support/Modules/Migrations/ModuleMigrationExecutor.php \
    app/Support/Modules/Migrations/ModuleInstallationRepository.php \
    config/modules.php \
    config/module_migrations.php \
    config/presets.php
 do
    add_file "$relative"
 done

# Existing setup helpers are included when present so another thread can see
# where repository/client scaffolding ends and database installation begins.
for relative in \
    scripts/create-client.sh \
    scripts/add-client-modules.sh \
    scripts/dump-client-files.sh
 do
    add_file "$relative"
 done

# Canonical operational documentation.
for relative in \
    docs/operations/deployment-command-workflow.md \
    docs/modular-migrations.md \
    docs/client-staging-production-setup-checklist.md \
    docs/deployment-safety-and-troubleshooting.md \
    docs/operations/project-state-transfer-runbook.md
 do
    add_file "$relative"
 done

sort -u "$FILES_LIST" -o "$FILES_LIST"
sort -u "$MISSING_LIST" -o "$MISSING_LIST"

GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
FILE_COUNT="$(wc -l < "$FILES_LIST" | tr -d ' ')"
MISSING_COUNT="$(wc -l < "$MISSING_LIST" | tr -d ' ')"

run_capture() {
    local title="$1"
    shift
    local status=0

    echo
    echo "$title"
    printf '%*s\n' "${#title}" '' | tr ' ' '='

    if "$@" 2>&1; then
        status=0
    else
        status=$?
    fi

    echo
    echo "[exit code: $status]"
}

{
    echo "Engage Core System / Deployment Context Dump"
    echo "============================================="
    echo
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included source/doc files: $FILE_COUNT"
    echo "Expected-but-missing optional files: $MISSING_COUNT"
    echo
    echo "Purpose:"
    echo "  Provide another Engage Core thread with the current installation, migration,"
    echo "  deployment, Project State, and command architecture without reconstructing it"
    echo "  from unrelated feature files."
    echo
    echo "Safety boundary:"
    echo "  - no .env files are read or appended"
    echo "  - no credentials/private keys are read"
    echo "  - no database rows or Redis contents are dumped"
    echo "  - no Project State export payload is included"
    echo "  - high-level client identifiers and validation diagnostics may be included"
    echo

    echo "REPOSITORY IDENTITY"
    echo "==================="
    if command -v git >/dev/null 2>&1 && git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "Commit: $(git -C "$ROOT_DIR" rev-parse HEAD 2>/dev/null || echo unknown)"
        echo "Branch: $(git -C "$ROOT_DIR" branch --show-current 2>/dev/null || echo unknown)"
        echo "Uncommitted path count: $(git -C "$ROOT_DIR" status --porcelain 2>/dev/null | wc -l | tr -d ' ')"
    else
        echo "Git repository metadata unavailable."
    fi

    echo
    echo "RUNTIME VERSIONS"
    echo "================"
    php -v 2>/dev/null | head -n 1 || true
    composer --version 2>/dev/null || true
    php "$ROOT_DIR/artisan" --version 2>/dev/null || true

    echo
    echo "EFFECTIVE HIGH-LEVEL CLIENT CONFIG"
    echo "=================================="
    if php "$ROOT_DIR/artisan" list --raw 2>/dev/null | grep -Eq '^tinker([[:space:]]|$)'; then
        php "$ROOT_DIR/artisan" tinker --execute="dump(['env' => app()->environment(), 'client_key' => config('client.key'), 'client_preset' => config('client.preset'), 'client_timezone' => config('client.timezone'), 'enabled_modules' => config('modules.enabled')]);" 2>&1 || true
    else
        echo "Tinker command unavailable; effective client config snapshot skipped."
    fi

    echo
    echo "RELEVANT ARTISAN COMMANDS"
    echo "========================="
    php "$ROOT_DIR/artisan" list --raw 2>/dev/null \
        | grep -E '^(engage:install|modules:(status|install|migrate|reconcile)|presets:sync|setup:validate)([[:space:]]|$)' \
        || true

    run_capture "MODULE MIGRATION STATUS (READ-ONLY)" php "$ROOT_DIR/artisan" modules:status
    run_capture "SETUP VALIDATION (READ-ONLY)" php "$ROOT_DIR/artisan" setup:validate
    run_capture "SCHEDULER INVENTORY (READ-ONLY)" php "$ROOT_DIR/artisan" schedule:list

    echo
    echo "EXPECTED FILES NOT PRESENT"
    echo "=========================="
    if [[ -s "$MISSING_LIST" ]]; then
        cat "$MISSING_LIST"
    else
        echo "None."
    fi

    echo
    echo "FILE INDEX"
    echo "=========="
    cat "$FILES_LIST"

    echo
    echo "FILE CONTENTS"
    echo "============="

    while IFS= read -r relative; do
        [[ -n "$relative" ]] || continue
        absolute="$ROOT_DIR/$relative"
        echo
        echo "===== BEGIN FILE: $relative ====="
        cat -- "$absolute"
        if [[ -s "$absolute" ]] && [[ -n "$(tail -c 1 "$absolute" 2>/dev/null || true)" ]]; then
            echo
        fi
        echo "===== END FILE: $relative ====="
    done < "$FILES_LIST"
} > "$OUTPUT_FILE"

echo "Created: $OUTPUT_FILE"
echo "Included files: $FILE_COUNT"
echo "Missing optional files recorded: $MISSING_COUNT"
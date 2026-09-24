#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/ModulePlatformInfrastructure_dependency_cone_dump.txt
#
# Purpose:
#   Capture the shared infrastructure that defines how Engage Core modules are
#   registered, enabled, installed, migrated, reconciled, validated, described,
#   and exposed to deployment/install tooling.
#
# Intentional boundary:
#   - shared module/platform infrastructure is first-class and recursively traced
#   - module-owned Providers are included as integration evidence only
#   - feature-module internals are NOT recursively expanded
#   - feature-owned migrations are NOT included unless reached through the shared
#     platform runtime or they own a table used by that runtime
#   - Project State, CRM, Automation, and other cross-module surfaces remain in
#     their own dedicated dependency cones

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/file_dumps"
OUTPUT_FILE="$OUTPUT_DIR/ModulePlatformInfrastructure_dependency_cone_dump.txt"

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

SEED_FILES="$TMP_DIR/seed-files.txt"
INTEGRATION_FILES="$TMP_DIR/integration-files.txt"
REFERENCE_FILES="$TMP_DIR/reference-files.txt"
BASELINE_FILES="$TMP_DIR/baseline-files.txt"
DOC_FILES="$TMP_DIR/doc-files.txt"
TEST_FILES="$TMP_DIR/test-files.txt"
IMPORT_FILES="$TMP_DIR/import-files.txt"
IMPORT_ONLY_FILES="$TMP_DIR/import-only-files.txt"
CONFIG_FILES="$TMP_DIR/config-files.txt"
MIGRATION_FILES="$TMP_DIR/migration-files.txt"
MIGRATION_TABLE_INDEX="$TMP_DIR/migration-table-index.tsv"
MODEL_TABLES="$TMP_DIR/model-tables.txt"
DB_TABLES="$TMP_DIR/db-tables.txt"
QUEUE_FILES="$TMP_DIR/import-queue.txt"
PROCESSED_FILES="$TMP_DIR/import-processed.txt"
RUNTIME_QUEUE="$TMP_DIR/runtime-queue.txt"
RUNTIME_PROCESSED="$TMP_DIR/runtime-processed.txt"
FINAL_FILES="$TMP_DIR/final-files.txt"

for file in \
    "$SEED_FILES" \
    "$INTEGRATION_FILES" \
    "$REFERENCE_FILES" \
    "$BASELINE_FILES" \
    "$DOC_FILES" \
    "$TEST_FILES" \
    "$IMPORT_FILES" \
    "$IMPORT_ONLY_FILES" \
    "$CONFIG_FILES" \
    "$MIGRATION_FILES" \
    "$MIGRATION_TABLE_INDEX" \
    "$MODEL_TABLES" \
    "$DB_TABLES" \
    "$QUEUE_FILES" \
    "$PROCESSED_FILES" \
    "$RUNTIME_QUEUE" \
    "$RUNTIME_PROCESSED" \
    "$FINAL_FILES"
do
    : > "$file"
done

is_forbidden_file() {
    local file="$1"
    local relative="${file#$ROOT_DIR/}"
    local base
    base="$(basename "$file")"

    case "$relative" in
        .git/*|vendor/*|node_modules/*|storage/*|bootstrap/cache/*|public/build/*|public/hot/*|file_dumps/*)
            return 0
            ;;
    esac

    if [[ "$base" == ".env" ]]; then
        return 0
    fi

    if [[ "$base" == .env.* && "$base" != ".env.example" ]]; then
        return 0
    fi

    case "${base,,}" in
        *.sqlite|*.db|*.png|*.jpg|*.jpeg|*.gif|*.webp|*.avif|*.ico|*.pdf|*.zip|*.gz|*.tar|*.tgz|*.7z|*.woff|*.woff2|*.ttf|*.otf|*.eot|*.mp3|*.mp4|*.mov|*.avi|*.pem|*.key|*.p12|*.pfx)
            return 0
            ;;
    esac

    return 1
}

add_file() {
    local file="$1"
    local destination="$2"

    [[ -f "$file" ]] || return 0
    is_forbidden_file "$file" && return 0
    printf '%s\n' "$file" >> "$destination"
}

add_directory() {
    local directory="$1"
    local destination="$2"

    [[ -d "$directory" ]] || return 0

    while IFS= read -r file; do
        add_file "$file" "$destination"
    done < <(find "$directory" -type f -print)
}

class_to_file() {
    local class="$1"
    local relative=""

    class="${class#\\}"
    class="${class%;}"
    class="${class%% as *}"
    class="$(printf '%s' "$class" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"

    case "$class" in
        App\\*)
            relative="app/${class#App\\}"
            ;;
        Database\\Factories\\*)
            relative="database/factories/${class#Database\\Factories\\}"
            ;;
        Database\\Seeders\\*)
            relative="database/seeders/${class#Database\\Seeders\\}"
            ;;
        Tests\\*)
            relative="tests/${class#Tests\\}"
            ;;
        *)
            return 1
            ;;
    esac

    relative="${relative//\\//}.php"
    printf '%s/%s\n' "$ROOT_DIR" "$relative"
}

extract_same_namespace_project_classes() {
    local file="$1"

    php -d display_errors=1 -r '
$root = rtrim($argv[1], DIRECTORY_SEPARATOR);
$file = $argv[2];
$source = file_get_contents($file);

if (!is_string($source) || $source === "") {
    exit(0);
}

if (!preg_match("/^\\s*namespace\\s+([^;]+);/m", $source, $namespaceMatch)) {
    exit(0);
}

$namespace = trim($namespaceMatch[1]);

if (!preg_match("/^(?:App|Database\\\\Factories|Database\\\\Seeders|Tests)(?:\\\\|$)/", $namespace)) {
    exit(0);
}

$tokens = token_get_all($source);
$seen = [];

foreach ($tokens as $token) {
    if (!is_array($token) || $token[0] !== T_STRING) {
        continue;
    }

    $name = $token[1];

    if (!preg_match("/^[A-Z][A-Za-z0-9_]*$/", $name)) {
        continue;
    }

    $class = $namespace."\\\\".$name;

    if (str_starts_with($class, "App\\\\")) {
        $relative = "app".DIRECTORY_SEPARATOR.str_replace("\\\\", DIRECTORY_SEPARATOR, substr($class, 4)).".php";
    } elseif (str_starts_with($class, "Database\\\\Factories\\\\")) {
        $relative = "database".DIRECTORY_SEPARATOR."factories".DIRECTORY_SEPARATOR.str_replace("\\\\", DIRECTORY_SEPARATOR, substr($class, 19)).".php";
    } elseif (str_starts_with($class, "Database\\\\Seeders\\\\")) {
        $relative = "database".DIRECTORY_SEPARATOR."seeders".DIRECTORY_SEPARATOR.str_replace("\\\\", DIRECTORY_SEPARATOR, substr($class, 17)).".php";
    } elseif (str_starts_with($class, "Tests\\\\")) {
        $relative = "tests".DIRECTORY_SEPARATOR.str_replace("\\\\", DIRECTORY_SEPARATOR, substr($class, 6)).".php";
    } else {
        continue;
    }

    if (is_file($root.DIRECTORY_SEPARATOR.$relative)) {
        $seen[$class] = true;
    }
}

foreach (array_keys($seen) as $class) {
    echo $class."\n";
}
' "$ROOT_DIR" "$file"
}

extract_project_classes() {
    local file="$1"

    grep -Eho '^[[:space:]]*use[[:space:]]+(App|Database\\(Factories|Seeders)|Tests)\\[^;]+' "$file" 2>/dev/null \
        | sed -E 's/^[[:space:]]*use[[:space:]]+//' \
        | grep -v '{' \
        || true

    grep -Eho '\\?(App|Database\\(Factories|Seeders)|Tests)\\[A-Za-z_][A-Za-z0-9_\\]*' "$file" 2>/dev/null \
        | sed 's/^\\//' \
        || true

    while IFS= read -r grouped; do
        prefix="${grouped%%\{*}"
        members="${grouped#*\{}"
        members="${members%\}*}"

        IFS=',' read -ra parts <<< "$members"
        for member in "${parts[@]}"; do
            member="$(printf '%s' "$member" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
            member="${member%% as *}"
            [[ -n "$member" ]] && printf '%s%s\n' "$prefix" "$member"
        done
    done < <(
        grep -Eho '^[[:space:]]*use[[:space:]]+(App|Database\\(Factories|Seeders)|Tests)\\[^;]*\{[^;]+\}' "$file" 2>/dev/null \
            | sed -E 's/^[[:space:]]*use[[:space:]]+//' \
            || true
    )

    extract_same_namespace_project_classes "$file"
}

extract_config_roots() {
    local file="$1"

    grep -Eho "config\\([[:space:]]*['\"][A-Za-z0-9_.-]+['\"]" "$file" 2>/dev/null \
        | sed -E "s/^config\\([[:space:]]*['\"]//; s/['\"]$//" \
        | cut -d. -f1 \
        | sort -u \
        || true
}

extract_database_tables() {
    local file="$1"

    grep -Eho "(Schema::(create|table|hasTable)|DB::table|from|join|leftJoin|rightJoin|updateOrInsert|insertOrIgnore)\\([[:space:]]*['\"][A-Za-z0-9_]+['\"]" "$file" 2>/dev/null \
        | sed -E "s/^.*\\([[:space:]]*['\"]//; s/['\"]$//" \
        | sort -u \
        || true
}

build_migration_table_index() {
    php -d display_errors=1 -r '
$root = rtrim($argv[1], DIRECTORY_SEPARATOR);
$directory = $root.DIRECTORY_SEPARATOR."database".DIRECTORY_SEPARATOR."migrations";

if (!is_dir($directory)) {
    exit(0);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
);

$files = [];
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== "php") {
        continue;
    }
    $files[] = $fileInfo->getPathname();
}

sort($files, SORT_STRING);

foreach ($files as $file) {
    $source = file_get_contents($file);

    if (!is_string($source) || $source === "") {
        continue;
    }

    $tables = [];
    $singleTablePattern = "/Schema\\s*::\\s*(?:connection\\s*\\([^;]*?\\)\\s*->\\s*)?(?:create|table|drop|dropIfExists)\\s*\\(\\s*([\\x27\\x22])([A-Za-z0-9_]+)\\1/s";

    if (preg_match_all($singleTablePattern, $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tables[$match[2]] = true;
        }
    }

    $renamePattern = "/Schema\\s*::\\s*(?:connection\\s*\\([^;]*?\\)\\s*->\\s*)?rename\\s*\\(\\s*([\\x27\\x22])([A-Za-z0-9_]+)\\1\\s*,\\s*([\\x27\\x22])([A-Za-z0-9_]+)\\3/s";

    if (preg_match_all($renamePattern, $source, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $tables[$match[2]] = true;
            $tables[$match[4]] = true;
        }
    }

    foreach (array_keys($tables) as $table) {
        echo $table."\t".$file."\n";
    }
}
' "$ROOT_DIR" | sort -u > "$MIGRATION_TABLE_INDEX"
}

add_migrations_for_table() {
    local table="$1"

    [[ -n "$table" ]] || return 0

    while IFS=$'\t' read -r indexed_table migration; do
        [[ "$indexed_table" == "$table" ]] || continue
        add_file "$migration" "$MIGRATION_FILES"
    done < "$MIGRATION_TABLE_INDEX"
}

resolve_non_class_dependencies() {
    local file="$1"
    local config_root
    local table

    while IFS= read -r config_root; do
        [[ -n "$config_root" ]] || continue
        add_file "$ROOT_DIR/config/${config_root}.php" "$CONFIG_FILES"
        add_directory "$ROOT_DIR/config/${config_root}" "$CONFIG_FILES"
    done < <(extract_config_roots "$file")

    while IFS= read -r table; do
        [[ -n "$table" ]] || continue
        printf '%s\n' "$table" >> "$DB_TABLES"
        add_migrations_for_table "$table"
    done < <(extract_database_tables "$file")
}

# ---------------------------------------------------------------------------
# 1. First-class shared module/platform roots
# ---------------------------------------------------------------------------

FIRST_CLASS_DIRS=(
    "app/Support/Modules"
    "app/Support/ModuleFacts"
    "app/Support/Deployment"
    "app/Support/Presets"
    "app/Support/SetupValidation"
    "app/Support/ConfigContracts"
)

for relative_dir in "${FIRST_CLASS_DIRS[@]}"; do
    add_directory "$ROOT_DIR/$relative_dir" "$SEED_FILES"
done

FIRST_CLASS_PATHS=(
    "app/Console/Commands/EngageDeploymentPlanCommand.php"
    "app/Console/Commands/EngageInstallCommand.php"
    "app/Console/Commands/EngageRefreshCommand.php"
    "app/Console/Commands/ModulesStatusCommand.php"
    "app/Console/Commands/ModulesInstallCommand.php"
    "app/Console/Commands/ModulesMigrateCommand.php"
    "app/Console/Commands/ModulesReconcileCommand.php"
    "app/Console/Commands/SyncPresetsCommand.php"
    "app/Console/Commands/ValidateSetupCommand.php"
    "app/Console/Concerns/InteractsWithDeploymentPlan.php"
    "app/Providers/PlatformMigrationServiceProvider.php"
)

for relative_path in "${FIRST_CLASS_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$SEED_FILES"
done

# Providers are important registration evidence, but generic providers are not
# recursive roots because AppServiceProvider-style wiring can touch much of the app.
add_directory "$ROOT_DIR/app/Providers" "$BASELINE_FILES"

# ---------------------------------------------------------------------------
# 2. Module-owned provider integration perimeter
# ---------------------------------------------------------------------------

if [[ -d "$ROOT_DIR/app/Modules" ]]; then
    while IFS= read -r provider_dir; do
        add_directory "$provider_dir" "$INTEGRATION_FILES"
    done < <(find "$ROOT_DIR/app/Modules" -type d -path '*/Providers' -print)
fi

# ---------------------------------------------------------------------------
# 3. Baseline config/bootstrap files
# ---------------------------------------------------------------------------

BASELINE_PATHS=(
    "artisan"
    "bootstrap/app.php"
    "bootstrap/providers.php"
    "composer.json"
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/TestCase.php"
    "config/app.php"
    "config/modules.php"
    "config/module_migrations.php"
    "config/presets.php"
    ".env.example"
)

for relative_path in "${BASELINE_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$BASELINE_FILES"
done

# ---------------------------------------------------------------------------
# 4. Strong explicit reference matches outside first-class roots
# ---------------------------------------------------------------------------

SEARCH_ROOTS=(
    "app"
    "bootstrap"
    "config"
    "database"
    "docs"
    "routes"
    "tests"
)

MATCH_PATTERNS=(
    "ModuleManager"
    "ModuleMigration"
    "module_migrations"
    "modules:status"
    "modules:install"
    "modules:migrate"
    "modules:reconcile"
    "engage:install"
    "engage:refresh"
    "EngageInstallCommand"
    "EngageRefreshCommand"
    "DeploymentPlan"
    "ModuleFacts"
    "ModuleFact"
    "SetupValidation"
    "ValidateSetupCommand"
    "ConfigContract"
    "presets:sync"
    "SyncPresetsCommand"
)

SEARCH_PATHS=()
for relative_root in "${SEARCH_ROOTS[@]}"; do
    [[ -d "$ROOT_DIR/$relative_root" ]] && SEARCH_PATHS+=("$ROOT_DIR/$relative_root")
done

# Use one ripgrep process for the repository-wide reference perimeter. The old
# per-file/per-pattern grep loop could spawn tens of thousands of processes in a
# mature repository and made this cone unnecessarily slow.
if command -v rg >/dev/null 2>&1 && (( ${#SEARCH_PATHS[@]} > 0 )); then
    RG_ARGS=(rg -l -I)
    for pattern in "${MATCH_PATTERNS[@]}"; do
        RG_ARGS+=(-F -e "$pattern")
    done

    while IFS= read -r file; do
        [[ -n "$file" ]] || continue
        is_forbidden_file "$file" && continue
        printf '%s\n' "$file" >> "$REFERENCE_FILES"
    done < <("${RG_ARGS[@]}" "${SEARCH_PATHS[@]}" 2>/dev/null || true)
else
    PATTERN_FILE="$TMP_DIR/reference-patterns.txt"
    printf '%s\n' "${MATCH_PATTERNS[@]}" > "$PATTERN_FILE"

    for absolute_root in "${SEARCH_PATHS[@]}"; do
        while IFS= read -r file; do
            is_forbidden_file "$file" && continue

            relative_file="${file#$ROOT_DIR/}"
            if grep -Fq -f "$PATTERN_FILE" <<< "$relative_file" \
                || grep -IFq -f "$PATTERN_FILE" "$file" 2>/dev/null
            then
                printf '%s\n' "$file" >> "$REFERENCE_FILES"
            fi
        done < <(find "$absolute_root" -type f -print)
    done
fi

sort -u "$REFERENCE_FILES" -o "$REFERENCE_FILES"

# ---------------------------------------------------------------------------
# 5. Focused platform/module tests
# ---------------------------------------------------------------------------

if [[ -d "$ROOT_DIR/tests" ]]; then
    while IFS= read -r test_file; do
        is_forbidden_file "$test_file" && continue

        relative="${test_file#$ROOT_DIR/}"
        base="$(basename "$test_file")"
        include=false

        case "$relative" in
            tests/Feature/Install/*|tests/Feature/Modules/*|tests/Feature/SetupValidation/*|tests/Unit/Support/Modules/*|tests/Unit/Support/ModuleFacts/*|tests/Unit/Support/Deployment/*|tests/Unit/Support/Presets/*|tests/Unit/Support/SetupValidation/*)
                include=true
                ;;
        esac

        if [[ "$include" == false ]]; then
            case "$base" in
                *EngageInstall*Test.php|*EngageRefresh*Test.php|*ModuleManager*Test.php|*ModuleMigration*Test.php|*ModuleFacts*Test.php|*DeploymentPlan*Test.php|*SetupValidation*Test.php|*ValidateSetup*Test.php|*SyncPresets*Test.php)
                    include=true
                    ;;
            esac
        fi

        if [[ "$include" == false ]] \
            && grep -IElq -- 'modules:(status|install|migrate|reconcile)|engage:(install|refresh)|ModuleManager|ModuleMigration|ModuleFacts|DeploymentPlan|SetupValidation|presets:sync' "$test_file" 2>/dev/null
        then
            include=true
        fi

        if [[ "$include" == true ]]; then
            add_file "$test_file" "$TEST_FILES"
        fi
    done < <(find "$ROOT_DIR/tests" -type f -name '*.php' -print)
fi

# ---------------------------------------------------------------------------
# 6. Platform/module documentation
# ---------------------------------------------------------------------------

DOC_PATHS=(
    "docs/modular-migrations.md"
    "docs/module-boundaries.md"
    "docs/module-surfaces.md"
    "docs/project-organization.md"
    "docs/operations/deployment-command-workflow.md"
    "docs/client-staging-production-setup-checklist.md"
    "docs/deployment-safety-and-troubleshooting.md"
)

for relative_path in "${DOC_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$DOC_FILES"
done

# ---------------------------------------------------------------------------
# 7. Recursive project-local dependencies from first-class implementation only
# ---------------------------------------------------------------------------

build_migration_table_index

cat "$SEED_FILES" \
    | sort -u \
    | grep -E '\.php$' > "$QUEUE_FILES" || true

while [[ -s "$QUEUE_FILES" ]]; do
    CURRENT_QUEUE="$TMP_DIR/current-queue.txt"
    NEXT_QUEUE="$TMP_DIR/next-queue.txt"
    mv "$QUEUE_FILES" "$CURRENT_QUEUE"
    : > "$NEXT_QUEUE"

    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        grep -Fxq "$file" "$PROCESSED_FILES" && continue
        printf '%s\n' "$file" >> "$PROCESSED_FILES"

        while IFS= read -r class; do
            [[ -n "$class" ]] || continue
            resolved="$(class_to_file "$class" 2>/dev/null || true)"
            [[ -n "$resolved" && -f "$resolved" ]] || continue
            is_forbidden_file "$resolved" && continue

            printf '%s\n' "$resolved" >> "$IMPORT_FILES"

            if [[ "$resolved" == *.php ]] \
                && ! grep -Fxq "$resolved" "$PROCESSED_FILES"
            then
                printf '%s\n' "$resolved" >> "$NEXT_QUEUE"
            fi
        done < <(extract_project_classes "$file" | sort -u)
    done < "$CURRENT_QUEUE"

    sort -u "$NEXT_QUEUE" > "$QUEUE_FILES"
done

# Runtime-only traversal drives config and direct DB table dependency discovery.
while IFS= read -r file; do
    [[ -f "$file" && "$file" == *.php ]] || continue

    case "${file#$ROOT_DIR/}" in
        app/*|bootstrap/*)
            printf '%s\n' "$file"
            ;;
    esac
done < "$PROCESSED_FILES" | sort -u > "$RUNTIME_QUEUE"

while [[ -s "$RUNTIME_QUEUE" ]]; do
    CURRENT_RUNTIME_QUEUE="$TMP_DIR/current-runtime-queue.txt"
    NEXT_RUNTIME_QUEUE="$TMP_DIR/next-runtime-queue.txt"
    mv "$RUNTIME_QUEUE" "$CURRENT_RUNTIME_QUEUE"
    : > "$NEXT_RUNTIME_QUEUE"

    while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        grep -Fxq "$file" "$RUNTIME_PROCESSED" && continue
        printf '%s\n' "$file" >> "$RUNTIME_PROCESSED"

        resolve_non_class_dependencies "$file"

        while IFS= read -r class; do
            [[ -n "$class" ]] || continue
            resolved="$(class_to_file "$class" 2>/dev/null || true)"
            [[ -n "$resolved" && -f "$resolved" ]] || continue
            is_forbidden_file "$resolved" && continue

            case "${resolved#$ROOT_DIR/}" in
                app/*|bootstrap/*)
                    if ! grep -Fxq "$resolved" "$RUNTIME_PROCESSED"; then
                        printf '%s\n' "$resolved" >> "$NEXT_RUNTIME_QUEUE"
                    fi
                    ;;
            esac
        done < <(extract_project_classes "$file" | sort -u)
    done < "$CURRENT_RUNTIME_QUEUE"

    sort -u "$NEXT_RUNTIME_QUEUE" > "$RUNTIME_QUEUE"
done

sort -u "$DB_TABLES" -o "$DB_TABLES"

# Resolve Eloquent table ownership for models reached through shared platform code.
php -d display_errors=1 -r '
require $argv[1]."/vendor/autoload.php";
$files = file($argv[2], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

foreach ($files as $file) {
    if (!is_file($file) || pathinfo($file, PATHINFO_EXTENSION) !== "php") {
        continue;
    }

    $source = file_get_contents($file);
    if (!is_string($source) || $source === "") {
        continue;
    }

    if (!preg_match("/^\\s*namespace\\s+([^;]+);/m", $source, $namespaceMatch)) {
        continue;
    }

    if (!preg_match("/^\\s*(?:(?:final|abstract|readonly)\\s+)*class\\s+([A-Za-z_][A-Za-z0-9_]*)/m", $source, $classMatch)) {
        continue;
    }

    $class = trim($namespaceMatch[1])."\\\\".$classMatch[1];

    if (!class_exists($class) || !is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
        continue;
    }

    $reflection = new ReflectionClass($class);
    if ($reflection->isAbstract()) {
        continue;
    }

    try {
        $model = $reflection->newInstanceWithoutConstructor();
        $table = $model->getTable();
    } catch (Throwable) {
        continue;
    }

    if (is_string($table) && trim($table) !== "") {
        echo trim($table)."\n";
    }
}
' "$ROOT_DIR" "$RUNTIME_PROCESSED" | sort -u > "$MODEL_TABLES"

while IFS= read -r table; do
    add_migrations_for_table "$table"
done < "$MODEL_TABLES"

# ---------------------------------------------------------------------------
# 8. Final assembly
# ---------------------------------------------------------------------------

cat \
    "$SEED_FILES" \
    "$INTEGRATION_FILES" \
    "$REFERENCE_FILES" \
    "$BASELINE_FILES" \
    "$DOC_FILES" \
    "$TEST_FILES" \
    "$IMPORT_FILES" \
    "$CONFIG_FILES" \
    "$MIGRATION_FILES" \
    | sort -u \
    | while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        is_forbidden_file "$file" && continue
        printf '%s\n' "$file"
    done > "$FINAL_FILES"

comm -23 \
    <(sort -u "$IMPORT_FILES") \
    <(cat "$SEED_FILES" "$INTEGRATION_FILES" "$REFERENCE_FILES" "$BASELINE_FILES" "$DOC_FILES" "$TEST_FILES" "$CONFIG_FILES" "$MIGRATION_FILES" | sort -u) \
    > "$IMPORT_ONLY_FILES"

FILE_COUNT="$(wc -l < "$FINAL_FILES" | tr -d ' ')"
SEED_COUNT="$(sort -u "$SEED_FILES" | wc -l | tr -d ' ')"
INTEGRATION_COUNT="$(sort -u "$INTEGRATION_FILES" | wc -l | tr -d ' ')"
REFERENCE_COUNT="$(sort -u "$REFERENCE_FILES" | wc -l | tr -d ' ')"
BASELINE_COUNT="$(sort -u "$BASELINE_FILES" | wc -l | tr -d ' ')"
DOC_COUNT="$(sort -u "$DOC_FILES" | wc -l | tr -d ' ')"
TEST_COUNT="$(sort -u "$TEST_FILES" | wc -l | tr -d ' ')"
IMPORT_COUNT="$(sort -u "$IMPORT_FILES" | wc -l | tr -d ' ')"
IMPORT_ONLY_COUNT="$(wc -l < "$IMPORT_ONLY_FILES" | tr -d ' ')"
CONFIG_COUNT="$(sort -u "$CONFIG_FILES" | wc -l | tr -d ' ')"
DB_TABLE_COUNT="$(sort -u "$DB_TABLES" | wc -l | tr -d ' ')"
MODEL_TABLE_COUNT="$(sort -u "$MODEL_TABLES" | wc -l | tr -d ' ')"
MIGRATION_COUNT="$(sort -u "$MIGRATION_FILES" | wc -l | tr -d ' ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Module / Platform Infrastructure Dependency Cone Dump"
    echo "================================================================"
    echo
    echo "Surface: shared module lifecycle, installation, migration, deployment, presets, facts, and setup validation"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included files: $FILE_COUNT"
    echo "First-class shared infrastructure files: $SEED_COUNT"
    echo "Module-owned provider integration files: $INTEGRATION_COUNT"
    echo "Explicit platform reference matches: $REFERENCE_COUNT"
    echo "Baseline files: $BASELINE_COUNT"
    echo "Focused tests: $TEST_COUNT"
    echo "Documentation files: $DOC_COUNT"
    echo "Import-resolved candidates: $IMPORT_COUNT"
    echo "Files added only through import resolution: $IMPORT_ONLY_COUNT"
    echo "Config dependencies: $CONFIG_COUNT"
    echo "Direct DB tables resolved: $DB_TABLE_COUNT"
    echo "Runtime Eloquent model tables resolved: $MODEL_TABLE_COUNT"
    echo "Migration ownership dependencies: $MIGRATION_COUNT"
    echo
    echo "First-class roots:"
    for relative_dir in "${FIRST_CLASS_DIRS[@]}"; do
        printf '  - %s/**\n' "$relative_dir"
    done
    for relative_path in "${FIRST_CLASS_PATHS[@]}"; do
        [[ -f "$ROOT_DIR/$relative_path" ]] && printf '  - %s\n' "$relative_path"
    done
    echo
    echo "Collection strategy:"
    echo "  - complete shared Modules, ModuleFacts, Deployment, Presets, SetupValidation, and ConfigContracts trees"
    echo "  - module/install/migration/reconcile/refresh/setup commands and deployment-plan concern"
    echo "  - PlatformMigrationServiceProvider as a first-class runtime root"
    echo "  - all app/Providers included as registration evidence"
    echo "  - all app/Modules/*/Providers trees included as integration evidence only"
    echo "  - config/modules.php, config/module_migrations.php, config/presets.php, bootstrap, and test baseline"
    echo "  - focused module/platform tests and canonical platform documentation"
    echo "  - repository-wide strong identifier matches as evidence"
    echo "  - recursive project-local PHP imports from first-class shared roots only"
    echo "  - runtime config(...) and direct DB table dependency resolution"
    echo "  - runtime Eloquent model table ownership resolution"
    echo "  - recursive migration indexing across database/migrations/**"
    echo "  - feature-module internals are not recursively expanded through provider evidence"
    echo "  - all environment files excluded except .env.example"
    echo
    echo "REPOSITORY STATE"
    echo "================"
    if command -v git >/dev/null 2>&1 && git -C "$ROOT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        echo "Branch: $(git -C "$ROOT_DIR" branch --show-current 2>/dev/null || true)"
        echo "Commit: $(git -C "$ROOT_DIR" rev-parse HEAD 2>/dev/null || true)"
        echo "Status:"
        git -C "$ROOT_DIR" status --short 2>/dev/null || true
    else
        echo "Git repository metadata unavailable."
    fi
    echo
    echo "RUNTIME TABLES"
    echo "=============="
    if [[ "$DB_TABLE_COUNT" -eq 0 ]]; then
        echo "No direct DB tables resolved."
    else
        while IFS= read -r table; do
            printf '  - direct: %s\n' "$table"
        done < "$DB_TABLES"
    fi
    if [[ "$MODEL_TABLE_COUNT" -eq 0 ]]; then
        echo "No Eloquent model tables resolved."
    else
        while IFS= read -r table; do
            printf '  - model: %s\n' "$table"
        done < "$MODEL_TABLES"
    fi
    echo
    echo "MODULE PROVIDER INTEGRATION PERIMETER"
    echo "====================================="
    if [[ "$INTEGRATION_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < <(sort -u "$INTEGRATION_FILES")
    fi
    echo
    echo "FILE INDEX"
    echo "=========="
    while IFS= read -r file; do
        echo "${file#$ROOT_DIR/}"
    done < "$FINAL_FILES"
    echo
    echo "FILE CONTENTS"
    echo "============="

    while IFS= read -r file; do
        relative_file="${file#$ROOT_DIR/}"
        echo
        echo "===== $relative_file ====="
        echo

        if [[ -s "$file" ]]; then
            cat "$file"
            [[ "$(tail -c 1 "$file" 2>/dev/null || true)" == "" ]] || echo
        else
            echo "[EMPTY FILE]"
        fi
    done < "$FINAL_FILES"
} > "$OUTPUT_FILE"

echo
echo "Created: $OUTPUT_FILE"
echo "Files included: $FILE_COUNT"
echo "First-class shared infrastructure files: $SEED_COUNT"
echo "Module provider integration files: $INTEGRATION_COUNT"
echo "Explicit platform reference matches: $REFERENCE_COUNT"
echo "Focused tests: $TEST_COUNT"
echo "Import-resolved candidates: $IMPORT_COUNT"
echo "Config dependencies: $CONFIG_COUNT"
echo "Runtime Eloquent model tables resolved: $MODEL_TABLE_COUNT"
echo "Migration ownership dependencies: $MIGRATION_COUNT"
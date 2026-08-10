#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/Automation_dependency_cone_dump.txt
#
# Automation is shared app-level infrastructure rather than an app/Modules
# module. This cone treats these as first-class roots:
#   - app/Support/AutomationCapabilities
#   - app/Support/AutomationEvents
#
# AutomationOpportunities is intentionally NOT a first-class root. Files from
# AutomationOpportunities (and modules) are included only when they explicitly
# reference the shared automation infrastructure.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/file_dumps"
OUTPUT_FILE="$OUTPUT_DIR/Automation_dependency_cone_dump.txt"

CAPABILITIES_DIR="$ROOT_DIR/app/Support/AutomationCapabilities"
EVENTS_DIR="$ROOT_DIR/app/Support/AutomationEvents"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    echo "Error: artisan was not found under: $ROOT_DIR" >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$CAPABILITIES_DIR" ]]; then
    echo "Error: app/Support/AutomationCapabilities was not found under: $ROOT_DIR" >&2
    exit 1
fi

if [[ ! -d "$EVENTS_DIR" ]]; then
    echo "Error: app/Support/AutomationEvents was not found under: $ROOT_DIR" >&2
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
MATCHED_FILES="$TMP_DIR/matched-files.txt"
INTEGRATION_FILES="$TMP_DIR/integration-files.txt"
BASELINE_FILES="$TMP_DIR/baseline-files.txt"
IMPORT_FILES="$TMP_DIR/import-files.txt"
IMPORT_ONLY_FILES="$TMP_DIR/import-only-files.txt"
CONFIG_FILES="$TMP_DIR/config-files.txt"
MIGRATION_FILES="$TMP_DIR/migration-files.txt"
MIGRATION_TABLE_INDEX="$TMP_DIR/migration-table-index.tsv"
MODEL_TABLES="$TMP_DIR/model-tables.txt"
QUEUE_FILES="$TMP_DIR/import-queue.txt"
PROCESSED_FILES="$TMP_DIR/import-processed.txt"
RUNTIME_FILES="$TMP_DIR/runtime-files.txt"
RUNTIME_QUEUE="$TMP_DIR/runtime-queue.txt"
RUNTIME_PROCESSED="$TMP_DIR/runtime-processed.txt"
FINAL_FILES="$TMP_DIR/final-files.txt"

for file in \
    "$SEED_FILES" \
    "$MATCHED_FILES" \
    "$INTEGRATION_FILES" \
    "$BASELINE_FILES" \
    "$IMPORT_FILES" \
    "$IMPORT_ONLY_FILES" \
    "$CONFIG_FILES" \
    "$MIGRATION_FILES" \
    "$MIGRATION_TABLE_INDEX" \
    "$MODEL_TABLES" \
    "$QUEUE_FILES" \
    "$PROCESSED_FILES" \
    "$RUNTIME_FILES" \
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
        *.sqlite|*.db|*.png|*.jpg|*.jpeg|*.gif|*.webp|*.avif|*.ico|*.pdf|*.zip|*.gz|*.tar|*.tgz|*.7z|*.woff|*.woff2|*.ttf|*.otf|*.eot|*.mp3|*.mp4|*.mov|*.avi)
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

# Complete first-class shared automation roots.
add_directory "$CAPABILITIES_DIR" "$SEED_FILES"
add_directory "$EVENTS_DIR" "$SEED_FILES"

# Repository-wide explicit references define the integration perimeter. These
# files are evidence-only: they are included, but they do not recursively pull
# their entire module/support dependency graphs into this cone.
SEARCH_ROOTS=(
    "app"
    "bootstrap"
    "config"
    "database"
    "docs"
    "resources"
    "routes"
    "tests"
)

MATCH_PATTERNS=(
    "AutomationCapabilities"
    "AutomationEvents"
    "AutomationCapabilityRegistry"
    "AutomationActionRegistry"
    "AutomationPointDefinitionRegistry"
    "AutomationPointAuthoringRegistry"
    "AutomationCapabilityContributor"
    "AutomationActionHandler"
    "AutomationPointDefinitionContributor"
    "AutomationPointAuthoringContributor"
    "AutomationEventData"
    "AutomationEventRecorded"
    "AutomationEventConsumer"
    "AutomationEventOutbox"
    "PublishAutomationEventOutboxEventsJob"
    "automation.capability_contributors"
    "automation.action_handlers"
    "automation.point_definition_contributors"
    "automation.point_authoring_contributors"
    "automation_events"
    "automation-event"
)

for relative_root in "${SEARCH_ROOTS[@]}"; do
    absolute_root="$ROOT_DIR/$relative_root"
    [[ -d "$absolute_root" ]] || continue

    while IFS= read -r file; do
        is_forbidden_file "$file" && continue

        relative_file="${file#$ROOT_DIR/}"
        matched=false

        for pattern in "${MATCH_PATTERNS[@]}"; do
            if [[ "$relative_file" == *"$pattern"* ]] \
                || grep -IFlq -- "$pattern" "$file" 2>/dev/null
            then
                matched=true
                break
            fi
        done

        if [[ "$matched" == true ]]; then
            printf '%s\n' "$file" >> "$MATCHED_FILES"
        fi
    done < <(find "$absolute_root" -type f -print)
done

sort -u "$MATCHED_FILES" -o "$MATCHED_FILES"

# Integration references are matched files outside the two first-class roots.
while IFS= read -r file; do
    case "$file" in
        "$CAPABILITIES_DIR"/*|"$EVENTS_DIR"/*)
            ;;
        *)
            printf '%s\n' "$file" >> "$INTEGRATION_FILES"
            ;;
    esac
done < "$MATCHED_FILES"

sort -u "$INTEGRATION_FILES" -o "$INTEGRATION_FILES"

BASELINE_PATHS=(
    "artisan"
    "bootstrap/app.php"
    "bootstrap/providers.php"
    "composer.json"
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/TestCase.php"
    "app/Providers/AppServiceProvider.php"
    "config/app.php"
    "config/modules.php"
    "config/module_migrations.php"
    ".env.example"
)

for relative_path in "${BASELINE_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$BASELINE_FILES"
done

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

resolve_non_class_dependencies() {
    local file="$1"
    local config_root
    local config_file
    local table

    while IFS= read -r config_root; do
        [[ -n "$config_root" ]] || continue
        config_file="$ROOT_DIR/config/${config_root}.php"
        add_file "$config_file" "$CONFIG_FILES"
    done < <(extract_config_roots "$file")

    while IFS= read -r table; do
        add_migrations_for_table "$table"
    done < <(extract_database_tables "$file")
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

    $class = $namespace."\\".$name;

    if (str_starts_with($class, "App\\")) {
        $relative = "app".DIRECTORY_SEPARATOR.str_replace("\\", DIRECTORY_SEPARATOR, substr($class, 4)).".php";
    } elseif (str_starts_with($class, "Database\\Factories\\")) {
        $relative = "database".DIRECTORY_SEPARATOR."factories".DIRECTORY_SEPARATOR.str_replace("\\", DIRECTORY_SEPARATOR, substr($class, 19)).".php";
    } elseif (str_starts_with($class, "Database\\Seeders\\")) {
        $relative = "database".DIRECTORY_SEPARATOR."seeders".DIRECTORY_SEPARATOR.str_replace("\\", DIRECTORY_SEPARATOR, substr($class, 17)).".php";
    } elseif (str_starts_with($class, "Tests\\")) {
        $relative = "tests".DIRECTORY_SEPARATOR.str_replace("\\", DIRECTORY_SEPARATOR, substr($class, 6)).".php";
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

build_migration_table_index

# Only first-class shared automation implementation files seed recursive class
# traversal. Integration matches remain evidence-only to prevent this dump from
# expanding into complete module cones.
find "$CAPABILITIES_DIR" "$EVENTS_DIR" -type f -name '*.php' -print \
    | sort -u > "$QUEUE_FILES"

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
        printf '%s\n' "$file" >> "$RUNTIME_FILES"

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

sort -u "$RUNTIME_FILES" -o "$RUNTIME_FILES"

resolve_model_tables() {
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

    $class = trim($namespaceMatch[1])."\\".$classMatch[1];

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
}

resolve_model_tables

# Safety net for the shared Automation Events platform migrations. Table/model
# resolution should already find these, but filename matching keeps the cone
# useful if those models are refactored later.
if [[ -d "$ROOT_DIR/database/migrations" ]]; then
    while IFS= read -r migration; do
        add_file "$migration" "$MIGRATION_FILES"
    done < <(
        find "$ROOT_DIR/database/migrations" -type f -iname '*automation_event*.php' -print 2>/dev/null || true
    )
fi

# If an explicit automation-events config file exists, retain it even when the
# implementation currently relies only on config() defaults.
add_file "$ROOT_DIR/config/automation_events.php" "$CONFIG_FILES"

cat \
    "$SEED_FILES" \
    "$MATCHED_FILES" \
    "$BASELINE_FILES" \
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
    <(cat "$SEED_FILES" "$MATCHED_FILES" "$BASELINE_FILES" "$CONFIG_FILES" "$MIGRATION_FILES" | sort -u) \
    > "$IMPORT_ONLY_FILES"

FILE_COUNT="$(wc -l < "$FINAL_FILES" | tr -d ' ')"
SEED_COUNT="$(sort -u "$SEED_FILES" | wc -l | tr -d ' ')"
MATCHED_COUNT="$(sort -u "$MATCHED_FILES" | wc -l | tr -d ' ')"
INTEGRATION_COUNT="$(sort -u "$INTEGRATION_FILES" | wc -l | tr -d ' ')"
BASELINE_COUNT="$(sort -u "$BASELINE_FILES" | wc -l | tr -d ' ')"
IMPORT_CANDIDATE_COUNT="$(sort -u "$IMPORT_FILES" | wc -l | tr -d ' ')"
IMPORT_ONLY_COUNT="$(wc -l < "$IMPORT_ONLY_FILES" | tr -d ' ')"
CONFIG_DEPENDENCY_COUNT="$(sort -u "$CONFIG_FILES" | wc -l | tr -d ' ')"
MODEL_TABLE_COUNT="$(sort -u "$MODEL_TABLES" | wc -l | tr -d ' ')"
MIGRATION_DEPENDENCY_COUNT="$(sort -u "$MIGRATION_FILES" | wc -l | tr -d ' ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Shared Automation Dependency Cone Dump"
    echo "=================================================="
    echo
    echo "Surface: Shared Automation infrastructure"
    echo "First-class roots:"
    echo "  - app/Support/AutomationCapabilities"
    echo "  - app/Support/AutomationEvents"
    echo "Excluded as a first-class root: app/Support/AutomationOpportunities"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included files: $FILE_COUNT"
    echo "First-class automation seed files: $SEED_COUNT"
    echo "Explicit automation reference matches: $MATCHED_COUNT"
    echo "Integration-perimeter files outside first-class roots: $INTEGRATION_COUNT"
    echo "Baseline candidates included: $BASELINE_COUNT"
    echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
    echo "Files added only through import resolution: $IMPORT_ONLY_COUNT"
    echo "Config files included: $CONFIG_DEPENDENCY_COUNT"
    echo "Runtime Eloquent model tables resolved: $MODEL_TABLE_COUNT"
    echo "Migrations added through table ownership resolution: $MIGRATION_DEPENDENCY_COUNT"
    echo
    echo "Search identifiers:"
    for pattern in "${MATCH_PATTERNS[@]}"; do
        printf '  - %s\n' "$pattern"
    done
    echo
    echo "Collection strategy:"
    echo "  - complete app/Support/AutomationCapabilities tree"
    echo "  - complete app/Support/AutomationEvents tree"
    echo "  - AutomationOpportunities is not seeded as a first-class root"
    echo "  - repository-wide explicit references expose module/support integration perimeter"
    echo "  - integration matches are evidence-only and do not recursively pull complete module cones"
    echo "  - recursive project-local PHP imports from first-class shared automation roots"
    echo "  - runtime config(...) root resolution"
    echo "  - runtime explicit DB table resolution"
    echo "  - runtime Eloquent model getTable() resolution"
    echo "  - recursive migration indexing across database/migrations/**"
    echo "  - migrations that create, alter, rename, or drop discovered tables"
    echo "  - automation-event migration filename fallback"
    echo "  - all environment files excluded except .env.example"
    echo
    echo "RUNTIME MODEL TABLES"
    echo "===================="

    if [[ "$MODEL_TABLE_COUNT" -eq 0 ]]; then
        echo "None resolved."
    else
        while IFS= read -r table; do
            printf '  - %s\n' "$table"
        done < "$MODEL_TABLES"
    fi

    echo
    echo "INTEGRATION PERIMETER"
    echo "====================="
    echo "Files outside the two first-class roots that explicitly reference shared automation infrastructure."
    echo

    if [[ "$INTEGRATION_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < "$INTEGRATION_FILES"
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
echo "First-class automation seeds: $SEED_COUNT"
echo "Explicit automation matches: $MATCHED_COUNT"
echo "Integration-perimeter files: $INTEGRATION_COUNT"
echo "Baseline candidates: $BASELINE_COUNT"
echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
echo "Files added only through imports: $IMPORT_ONLY_COUNT"
echo "Config dependencies: $CONFIG_DEPENDENCY_COUNT"
echo "Runtime Eloquent model tables resolved: $MODEL_TABLE_COUNT"
echo "Migration ownership dependencies: $MIGRATION_DEPENDENCY_COUNT"

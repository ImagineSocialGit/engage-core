#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/ProcessHighway_dependency_cone_dump.txt
# Revision: 1
#
# Process Highway is a shared, cross-module read/composition surface rather than
# an app/Modules module. This cone deliberately gives recursive treatment only
# to the Highway implementation itself. Module-owned files are admitted as an
# integration perimeter when they expose facts, filters, automation contracts,
# campaign lifecycle state, or Flow Route presentation that the Highway may
# compose.
#
# First-class roots:
#   - app/Support/ProcessHighway/**
#   - app/Http/Controllers/CRM/ProcessHighwayController.php
#   - resources/views/crm/process-highway/**
#   - routes/crm/core.php (registration evidence only; not a recursive runtime root)
#   - docs/process-highway.md
#   - ProcessHighway-focused tests
#
# Intentional boundary:
#   - first-class Highway support/controller PHP imports/config/table dependencies are recursive
#   - the shared CRM route file is included without recursively traversing its unrelated controllers
#   - Blade dependencies from Highway views are recursively resolved
#   - module/support integration matches are evidence-only
#   - integration matches DO NOT recursively pull full module dependency cones
#   - use make-module-dependency-cone-dump.sh for deep owning-module internals

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/file_dumps"
OUTPUT_FILE="$OUTPUT_DIR/ProcessHighway_dependency_cone_dump.txt"

HIGHWAY_SUPPORT_DIR="$ROOT_DIR/app/Support/ProcessHighway"
HIGHWAY_CONTROLLER="$ROOT_DIR/app/Http/Controllers/CRM/ProcessHighwayController.php"
HIGHWAY_VIEW_DIR="$ROOT_DIR/resources/views/crm/process-highway"
HIGHWAY_ROUTE_FILE="$ROOT_DIR/routes/crm/core.php"
HIGHWAY_DOC="$ROOT_DIR/docs/process-highway.md"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    echo "Error: artisan was not found under: $ROOT_DIR" >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$HIGHWAY_SUPPORT_DIR" ]]; then
    echo "Error: app/Support/ProcessHighway was not found under: $ROOT_DIR" >&2
    exit 1
fi

if [[ ! -f "$HIGHWAY_CONTROLLER" ]]; then
    echo "Error: ProcessHighwayController.php was not found under: $ROOT_DIR" >&2
    exit 1
fi

if [[ ! -d "$HIGHWAY_VIEW_DIR" ]]; then
    echo "Error: resources/views/crm/process-highway was not found under: $ROOT_DIR" >&2
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
RUNTIME_FILES="$TMP_DIR/runtime-files.txt"
RUNTIME_QUEUE="$TMP_DIR/runtime-queue.txt"
RUNTIME_PROCESSED="$TMP_DIR/runtime-processed.txt"
HIGHWAY_VIEW_FILES="$TMP_DIR/highway-view-files.txt"
BLADE_DEPENDENCY_FILES="$TMP_DIR/blade-dependency-files.txt"
BLADE_QUEUE="$TMP_DIR/blade-queue.txt"
BLADE_PROCESSED="$TMP_DIR/blade-processed.txt"
FINAL_FILES="$TMP_DIR/final-files.txt"

for file in \
    "$SEED_FILES" \
    "$MATCHED_FILES" \
    "$INTEGRATION_FILES" \
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
    "$RUNTIME_FILES" \
    "$RUNTIME_QUEUE" \
    "$RUNTIME_PROCESSED" \
    "$HIGHWAY_VIEW_FILES" \
    "$BLADE_DEPENDENCY_FILES" \
    "$BLADE_QUEUE" \
    "$BLADE_PROCESSED" \
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

view_name_to_file() {
    local view_name="$1"
    local relative

    [[ -n "$view_name" ]] || return 1
    [[ "$view_name" == *"::"* ]] && return 1

    relative="${view_name//./\/}.blade.php"
    printf '%s/resources/views/%s\n' "$ROOT_DIR" "$relative"
}

component_name_to_file() {
    local component="$1"
    local relative

    [[ -n "$component" ]] || return 1
    [[ "$component" == *'{'* || "$component" == *'$'* ]] && return 1

    relative="${component//./\/}.blade.php"
    printf '%s/resources/views/components/%s\n' "$ROOT_DIR" "$relative"
}

extract_blade_references() {
    local file="$1"

    php -d display_errors=1 -r '
$file = $argv[1];
$source = file_get_contents($file);

if (!is_string($source) || $source === "") {
    exit(0);
}

$views = [];
$components = [];

$viewPatterns = [
    "/@(?:extends|include|includeIf|includeWhen|includeUnless|includeFirst|component)\\s*\\(\\s*[\\x27\\x22]([A-Za-z0-9_.:-]+)[\\x27\\x22]/",
    "/\\bview\\s*\\(\\s*[\\x27\\x22]([A-Za-z0-9_.:-]+)[\\x27\\x22]/",
    "/\\bView\\s*::\\s*make\\s*\\(\\s*[\\x27\\x22]([A-Za-z0-9_.:-]+)[\\x27\\x22]/",
];

foreach ($viewPatterns as $pattern) {
    if (preg_match_all($pattern, $source, $matches)) {
        foreach ($matches[1] as $view) {
            $views[$view] = true;
        }
    }
}

if (preg_match_all("/<x-([A-Za-z0-9_.:-]+)/", $source, $matches)) {
    foreach ($matches[1] as $component) {
        $component = preg_replace("/(?::.*)$/", "", $component);
        if (is_string($component) && $component !== "") {
            $components[$component] = true;
        }
    }
}

foreach (array_keys($views) as $view) {
    echo "VIEW\t{$view}\n";
}

foreach (array_keys($components) as $component) {
    echo "COMPONENT\t{$component}\n";
}
' "$file"
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
        if [[ -d "$ROOT_DIR/config/${config_root}" ]]; then
            add_directory "$ROOT_DIR/config/${config_root}" "$CONFIG_FILES"
        fi
    done < <(extract_config_roots "$file")

    while IFS= read -r table; do
        [[ -n "$table" ]] || continue
        printf '%s\n' "$table" >> "$DB_TABLES"
        add_migrations_for_table "$table"
    done < <(extract_database_tables "$file")
}

# ---------------------------------------------------------------------------
# 1. First-class Process Highway roots
# ---------------------------------------------------------------------------

add_directory "$HIGHWAY_SUPPORT_DIR" "$SEED_FILES"
add_file "$HIGHWAY_CONTROLLER" "$SEED_FILES"
add_directory "$HIGHWAY_VIEW_DIR" "$HIGHWAY_VIEW_FILES"
add_directory "$HIGHWAY_VIEW_DIR" "$SEED_FILES"
add_file "$HIGHWAY_ROUTE_FILE" "$SEED_FILES"
add_file "$HIGHWAY_DOC" "$DOC_FILES"
add_file "$HIGHWAY_DOC" "$SEED_FILES"

if [[ -d "$ROOT_DIR/tests" ]]; then
    while IFS= read -r test_file; do
        is_forbidden_file "$test_file" && continue

        relative="${test_file#$ROOT_DIR/}"
        base="$(basename "$test_file")"
        include=false

        case "$relative" in
            tests/*/ProcessHighway*|tests/*/*/ProcessHighway*|tests/*/*/*/ProcessHighway*)
                include=true
                ;;
        esac

        if [[ "$base" == *ProcessHighway* ]]; then
            include=true
        fi

        if [[ "$include" == false ]] \
            && grep -IElq -- 'ProcessHighway|process-highway|Process Highway|crm\.process-highway' "$test_file" 2>/dev/null
        then
            include=true
        fi

        if [[ "$include" == true ]]; then
            add_file "$test_file" "$TEST_FILES"
            add_file "$test_file" "$SEED_FILES"
        fi
    done < <(find "$ROOT_DIR/tests" -type f -print)
fi

# ---------------------------------------------------------------------------
# 2. Integration perimeter
# ---------------------------------------------------------------------------
#
# These identifiers intentionally describe the seams the Highway is expected to
# compose. Matches are evidence-only. They do not seed recursive import traversal.

SEARCH_ROOTS=(
    "app"
    "bootstrap"
    "config"
    "docs"
    "resources"
    "routes"
    "tests"
)

MATCH_PATTERNS=(
    "ProcessHighway"
    "process-highway"
    "process_highway"
    "Process Highway"
    "ContactFilterCriterion"
    "ContactFilterCriterionRegistry"
    "ContactFilterResolver"
    "StatusContactFilterCriterion"
    "RelationshipContactFilterCriterion"
    "CampaignEligibility"
    "CampaignEnrollmentPolicy"
    "CampaignEnrollment"
    "EnrollContactInCampaignAction"
    "CancelCampaignEnrollmentAction"
    "PauseCampaignEnrollmentAction"
    "ResumeCampaignEnrollmentAction"
    "CampaignTouchProgram"
    "FlowRoutePresentationResolver"
    "FlowRouteEditorCatalog"
    "FlowRoutePointAuthoringService"
    "ContactWorkflowStatusChanged"
    "ChangeContactRelationshipStageAction"
    "AutomationCapabilityRegistry"
    "AutomationActionRegistry"
    "AutomationPointDefinitionRegistry"
    "AutomationPointAuthoringRegistry"
    "AutomationEventData"
    "AutomationEventRecorded"
    "automation.capability_contributors"
    "automation.action_handlers"
    "automation.point_definition_contributors"
    "automation.point_authoring_contributors"
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

while IFS= read -r file; do
    case "$file" in
        "$HIGHWAY_SUPPORT_DIR"/*|"$HIGHWAY_CONTROLLER"|"$HIGHWAY_VIEW_DIR"/*|"$HIGHWAY_ROUTE_FILE"|"$HIGHWAY_DOC")
            ;;
        *)
            if ! grep -Fxq "$file" "$TEST_FILES" 2>/dev/null; then
                printf '%s\n' "$file" >> "$INTEGRATION_FILES"
            fi
            ;;
    esac
done < "$MATCHED_FILES"

# Keep the current known composition seams even when a class/file does not yet
# mention ProcessHighway explicitly. Missing candidates are harmless and are
# skipped, so this list can cover optional modules without creating a runtime
# dependency from the Highway itself.
INTEGRATION_PATHS=(
    "app/Modules/Core/Contracts/Contacts/ContactFilterCriterion.php"
    "app/Modules/Core/Support/Contacts/ContactFilterCriterionRegistry.php"
    "app/Modules/Core/Services/Contacts/ContactFilterResolver.php"
    "app/Modules/Core/Services/Contacts/Filters/SourceContactFilterCriterion.php"
    "app/Modules/Core/Services/Contacts/Filters/SubsourceContactFilterCriterion.php"
    "app/Modules/Core/Services/Contacts/Filters/TagContactFilterCriterion.php"
    "app/Modules/Workflow/Services/Contacts/Filters/StatusContactFilterCriterion.php"
    "app/Modules/Workflow/Events/ContactWorkflowStatusChanged.php"
    "app/Modules/Workflow/Actions/TransitionContactWorkflowStatusAction.php"
    "app/Modules/Relationships/Services/Contacts/Filters/RelationshipContactFilterCriterion.php"
    "app/Modules/Relationships/Actions/ChangeContactRelationshipStageAction.php"
    "app/Modules/FlowRoutes/Services/FlowRoutePresentationResolver.php"
    "app/Modules/FlowRoutes/Services/FlowRouteEditorCatalog.php"
    "app/Modules/FlowRoutes/Services/FlowRoutePointAuthoringService.php"
    "app/Modules/FlowRoutes/Models/FlowRoute.php"
    "app/Modules/FlowRoutes/Models/FlowRoutePoint.php"
    "app/Modules/Campaigns/Models/Campaign.php"
    "app/Modules/Campaigns/Models/CampaignEnrollment.php"
    "app/Modules/Campaigns/Models/CampaignTouchProgram.php"
    "app/Modules/Campaigns/Actions/EnrollContactInCampaignAction.php"
    "app/Modules/Campaigns/Actions/CancelCampaignEnrollmentAction.php"
    "app/Modules/Campaigns/Actions/PauseCampaignEnrollmentAction.php"
    "app/Modules/Campaigns/Actions/ResumeCampaignEnrollmentAction.php"
    "app/Modules/Campaigns/Services/CampaignWorkspacePresenter.php"
    "app/Modules/Campaigns/Services/ContactShow/ContactCampaignsVisibilityDataProvider.php"
)

for relative_path in "${INTEGRATION_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$INTEGRATION_FILES"
done

# Shared Automation is an important Highway composition seam. Include the small
# shared contract/registry trees as evidence, but do not recursively expand the
# modules that consume them.
add_directory "$ROOT_DIR/app/Support/AutomationCapabilities" "$INTEGRATION_FILES"
add_directory "$ROOT_DIR/app/Support/AutomationEvents" "$INTEGRATION_FILES"

sort -u "$INTEGRATION_FILES" -o "$INTEGRATION_FILES"

# ---------------------------------------------------------------------------
# 3. Shared baseline and explicit documentation
# ---------------------------------------------------------------------------

BASELINE_PATHS=(
    "artisan"
    "bootstrap/app.php"
    "bootstrap/providers.php"
    "composer.json"
    "package.json"
    "vite.config.js"
    "vite.config.ts"
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/TestCase.php"
    "app/Http/Controllers/Controller.php"
    "app/Providers/AppServiceProvider.php"
    "config/app.php"
    "config/client.php"
    "config/domains.php"
    "config/modules.php"
    "routes/crm.php"
    ".env.example"
    "resources/css/app.css"
    "resources/js/app.js"
    "resources/js/bootstrap.js"
)

for relative_path in "${BASELINE_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$BASELINE_FILES"
done

DOC_PATHS=(
    "docs/process-highway.md"
    "docs/module-boundaries.md"
    "docs/module-surfaces.md"
    "docs/project-organization.md"
    "docs/ui-ux-guide.md"
    "docs/automation-opportunities.md"
)

for relative_path in "${DOC_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$DOC_FILES"
done

# ---------------------------------------------------------------------------
# 4. Recursive PHP dependencies from first-class Highway implementation only
# ---------------------------------------------------------------------------

build_migration_table_index

{
    find "$HIGHWAY_SUPPORT_DIR" -type f -name '*.php' -print
    [[ -f "$HIGHWAY_CONTROLLER" ]] && printf '%s\n' "$HIGHWAY_CONTROLLER"
} | sort -u > "$QUEUE_FILES"

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

# Runtime traversal is limited to PHP files reached from the Highway support/controller
# graph. The shared CRM route file is deliberately excluded from traversal because it
# registers unrelated Dashboard, Project State, and Contacts controllers.
while IFS= read -r file; do
    [[ -f "$file" && "$file" == *.php ]] || continue

    case "${file#$ROOT_DIR/}" in
        app/*|bootstrap/*|routes/*)
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
                app/*|bootstrap/*|routes/*)
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
sort -u "$DB_TABLES" -o "$DB_TABLES"

# Resolve Eloquent table ownership for models reached through the first-class
# runtime graph. This is usually small for Process Highway, but keeps the cone
# correct if the read service later moves from DB::table() to read-only models.
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

# ---------------------------------------------------------------------------
# 5. Recursively resolve Blade dependencies from Highway views only
# ---------------------------------------------------------------------------

sort -u "$HIGHWAY_VIEW_FILES" | grep -E '\.blade\.php$' > "$BLADE_QUEUE" || true

while [[ -s "$BLADE_QUEUE" ]]; do
    CURRENT_QUEUE="$TMP_DIR/current-blade-queue.txt"
    NEXT_QUEUE="$TMP_DIR/next-blade-queue.txt"

    mv "$BLADE_QUEUE" "$CURRENT_QUEUE"
    : > "$NEXT_QUEUE"

    while IFS= read -r blade_file; do
        [[ -f "$blade_file" ]] || continue
        grep -Fxq "$blade_file" "$BLADE_PROCESSED" && continue
        printf '%s\n' "$blade_file" >> "$BLADE_PROCESSED"

        while IFS=$'\t' read -r kind name; do
            [[ -n "$kind" && -n "$name" ]] || continue
            resolved=""

            case "$kind" in
                VIEW)
                    resolved="$(view_name_to_file "$name" 2>/dev/null || true)"
                    ;;
                COMPONENT)
                    resolved="$(component_name_to_file "$name" 2>/dev/null || true)"
                    ;;
            esac

            [[ -n "$resolved" && -f "$resolved" ]] || continue
            is_forbidden_file "$resolved" && continue

            add_file "$resolved" "$BLADE_DEPENDENCY_FILES"

            if ! grep -Fxq "$resolved" "$BLADE_PROCESSED"; then
                printf '%s\n' "$resolved" >> "$NEXT_QUEUE"
            fi
        done < <(extract_blade_references "$blade_file" | sort -u)
    done < "$CURRENT_QUEUE"

    sort -u "$NEXT_QUEUE" > "$BLADE_QUEUE"
done

# ---------------------------------------------------------------------------
# 6. Final assembly
# ---------------------------------------------------------------------------

cat \
    "$SEED_FILES" \
    "$MATCHED_FILES" \
    "$INTEGRATION_FILES" \
    "$BASELINE_FILES" \
    "$DOC_FILES" \
    "$TEST_FILES" \
    "$IMPORT_FILES" \
    "$CONFIG_FILES" \
    "$MIGRATION_FILES" \
    "$BLADE_DEPENDENCY_FILES" \
    | sort -u \
    | while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        is_forbidden_file "$file" && continue
        printf '%s\n' "$file"
    done > "$FINAL_FILES"

comm -23 \
    <(sort -u "$IMPORT_FILES") \
    <(cat "$SEED_FILES" "$MATCHED_FILES" "$INTEGRATION_FILES" "$BASELINE_FILES" "$DOC_FILES" "$TEST_FILES" "$CONFIG_FILES" "$MIGRATION_FILES" "$BLADE_DEPENDENCY_FILES" | sort -u) \
    > "$IMPORT_ONLY_FILES"

FILE_COUNT="$(wc -l < "$FINAL_FILES" | tr -d ' ')"
SEED_COUNT="$(sort -u "$SEED_FILES" | wc -l | tr -d ' ')"
MATCHED_COUNT="$(sort -u "$MATCHED_FILES" | wc -l | tr -d ' ')"
INTEGRATION_COUNT="$(sort -u "$INTEGRATION_FILES" | wc -l | tr -d ' ')"
BASELINE_COUNT="$(sort -u "$BASELINE_FILES" | wc -l | tr -d ' ')"
DOC_COUNT="$(sort -u "$DOC_FILES" | wc -l | tr -d ' ')"
TEST_COUNT="$(sort -u "$TEST_FILES" | wc -l | tr -d ' ')"
IMPORT_CANDIDATE_COUNT="$(sort -u "$IMPORT_FILES" | wc -l | tr -d ' ')"
IMPORT_ONLY_COUNT="$(wc -l < "$IMPORT_ONLY_FILES" | tr -d ' ')"
CONFIG_DEPENDENCY_COUNT="$(sort -u "$CONFIG_FILES" | wc -l | tr -d ' ')"
DB_TABLE_COUNT="$(sort -u "$DB_TABLES" | wc -l | tr -d ' ')"
MODEL_TABLE_COUNT="$(sort -u "$MODEL_TABLES" | wc -l | tr -d ' ')"
MIGRATION_DEPENDENCY_COUNT="$(sort -u "$MIGRATION_FILES" | wc -l | tr -d ' ')"
BLADE_DEPENDENCY_COUNT="$(sort -u "$BLADE_DEPENDENCY_FILES" | wc -l | tr -d ' ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Process Highway Dependency Cone Dump"
    echo "================================================"
    echo
    echo "Surface: Process Highway cross-module composition/read model"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included files: $FILE_COUNT"
    echo "First-class Highway seed files: $SEED_COUNT"
    echo "Explicit Highway/integration reference matches: $MATCHED_COUNT"
    echo "Integration-perimeter files outside first-class roots: $INTEGRATION_COUNT"
    echo "Highway-focused tests: $TEST_COUNT"
    echo "Explicit Highway/support documentation files: $DOC_COUNT"
    echo "Baseline candidates included: $BASELINE_COUNT"
    echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
    echo "Files added only through import resolution: $IMPORT_ONLY_COUNT"
    echo "Config files included: $CONFIG_DEPENDENCY_COUNT"
    echo "Direct DB tables resolved from Highway runtime: $DB_TABLE_COUNT"
    echo "Runtime Eloquent model tables resolved: $MODEL_TABLE_COUNT"
    echo "Migrations added through table ownership resolution: $MIGRATION_DEPENDENCY_COUNT"
    echo "Blade dependencies outside the Highway view tree: $BLADE_DEPENDENCY_COUNT"
    echo
    echo "First-class roots:"
    echo "  - app/Support/ProcessHighway/**"
    echo "  - app/Http/Controllers/CRM/ProcessHighwayController.php"
    echo "  - resources/views/crm/process-highway/**"
    echo "  - routes/crm/core.php (registration evidence only)"
    echo "  - docs/process-highway.md"
    echo "  - ProcessHighway-focused tests"
    echo
    echo "Search identifiers:"
    for pattern in "${MATCH_PATTERNS[@]}"; do
        printf '  - %s\n' "$pattern"
    done
    echo
    echo "Collection strategy:"
    echo "  - complete first-class Process Highway implementation roots"
    echo "  - recursive project-local PHP imports from Highway support/controller runtime files"
    echo "  - shared CRM route registration included without traversing unrelated route imports"
    echo "  - runtime config(...) root resolution from the first-class runtime graph"
    echo "  - runtime explicit DB table resolution from the first-class runtime graph"
    echo "  - runtime Eloquent model getTable() resolution for reached read models"
    echo "  - recursive migration indexing across database/migrations/**"
    echo "  - migrations that create, alter, rename, or drop discovered Highway-read tables"
    echo "  - recursive static Blade @include/@extends/@component/view references"
    echo "  - recursive anonymous Blade <x-...> component references"
    echo "  - repository-wide evidence matching for Campaign eligibility/enrollment, Core filters, Workflow, Relationships, Flow Routes, and shared Automation seams"
    echo "  - module/support integration matches are evidence-only and are not recursively expanded"
    echo "  - all environment files excluded except .env.example"
    echo
    echo "Intentional boundary:"
    echo "  - Process Highway remains a composition/read surface, not a new source of truth"
    echo "  - owning modules remain authoritative for Campaigns, Flow Routes, Workflow, Relationships, Messaging, Tasks, Webinars, Scheduling, Reporting, and future contributors"
    echo "  - integration matches do not recursively pull complete module cones"
    echo "  - use make-module-dependency-cone-dump.sh for deep module-owned runtime/schema dependencies"
    echo "  - client-specific configuration is not recursively collected unless explicitly referenced by first-class runtime config"
    echo
    echo "Static-resolution limitations:"
    echo "  - dynamic table/view/component names cannot be resolved statically"
    echo "  - future Highway contributor registries should be added to MATCH_PATTERNS when introduced"
    echo "  - repository-wide integration evidence is intentionally broader than the current V1 implementation"
    echo
    echo "HIGHWAY RUNTIME DB TABLES"
    echo "========================="

    if [[ "$DB_TABLE_COUNT" -eq 0 ]]; then
        echo "None resolved."
    else
        while IFS= read -r table; do
            printf '  - %s\n' "$table"
        done < "$DB_TABLES"
    fi

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
    echo "Files outside first-class Highway roots that expose facts/contracts/actions the Highway may compose."
    echo

    if [[ "$INTEGRATION_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < "$INTEGRATION_FILES"
    fi

    echo
    echo "BLADE DEPENDENCIES OUTSIDE resources/views/crm/process-highway"
    echo "=============================================================="

    outside_count=0
    while IFS= read -r file; do
        relative="${file#$ROOT_DIR/}"

        case "$relative" in
            resources/views/crm/process-highway/*)
                continue
                ;;
        esac

        echo "$relative"
        outside_count=$((outside_count + 1))
    done < <(sort -u "$BLADE_DEPENDENCY_FILES")

    if [[ "$outside_count" -eq 0 ]]; then
        echo "None detected."
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
echo "First-class Highway seeds: $SEED_COUNT"
echo "Explicit Highway/integration matches: $MATCHED_COUNT"
echo "Integration-perimeter files: $INTEGRATION_COUNT"
echo "Highway-focused tests: $TEST_COUNT"
echo "Documentation files: $DOC_COUNT"
echo "Baseline candidates: $BASELINE_COUNT"
echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
echo "Files added only through imports: $IMPORT_ONLY_COUNT"
echo "Config dependencies: $CONFIG_DEPENDENCY_COUNT"
echo "Direct Highway DB tables: $DB_TABLE_COUNT"
echo "Runtime Eloquent model tables: $MODEL_TABLE_COUNT"
echo "Migration ownership dependencies: $MIGRATION_DEPENDENCY_COUNT"
echo "Recursive Blade dependencies: $BLADE_DEPENDENCY_COUNT"

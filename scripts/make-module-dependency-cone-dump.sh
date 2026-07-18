#!/usr/bin/env bash
set -Eeuo pipefail

# Run from the Engage Core repository root.
# Produces: file_dumps/<ModuleName>_dependency_cone_dump.txt

ROOT_DIR="$(pwd)"
MODULES_DIR="$ROOT_DIR/app/Modules"
MODULE_CONFIG="$ROOT_DIR/config/modules.php"
OUTPUT_DIR="$ROOT_DIR/file_dumps"

if [[ ! -d "$MODULES_DIR" ]]; then
    echo "Error: app/Modules was not found under: $ROOT_DIR" >&2
    echo "Run this script from the Engage Core repository root." >&2
    exit 1
fi

if [[ ! -f "$MODULE_CONFIG" ]]; then
    echo "Error: config/modules.php was not found under: $ROOT_DIR" >&2
    exit 1
fi

if [[ ! -f "$ROOT_DIR/vendor/autoload.php" ]]; then
    echo "Error: vendor/autoload.php was not found." >&2
    echo "Run composer install before using this script." >&2
    exit 1
fi

mkdir -p "$OUTPUT_DIR"

snake_case() {
    printf '%s' "$1" \
        | sed -E 's/([A-Z]+)([A-Z][a-z])/\1_\2/g; s/([a-z0-9])([A-Z])/\1_\2/g' \
        | tr '[:upper:]' '[:lower:]'
}

mapfile -t MODULES < <(
    find "$MODULES_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort
)

if [[ ${#MODULES[@]} -eq 0 ]]; then
    echo "Error: no module directories were found in app/Modules." >&2
    exit 1
fi

declare -A MODULE_DIR_BY_KEY=()
declare -A MODULE_KEY_BY_DIR=()

for module_dir in "${MODULES[@]}"; do
    module_key="$(snake_case "$module_dir")"
    MODULE_DIR_BY_KEY["$module_key"]="$module_dir"
    MODULE_KEY_BY_DIR["$module_dir"]="$module_key"
done

echo "Select a module dependency cone to dump:"
echo

select MODULE_NAME in "${MODULES[@]}" "Quit"; do
    if [[ "$MODULE_NAME" == "Quit" ]]; then
        exit 0
    fi

    if [[ -n "${MODULE_NAME:-}" ]]; then
        break
    fi

    echo "Invalid selection. Try again." >&2
done

MODULE_PATH="$MODULES_DIR/$MODULE_NAME"
MODULE_KEY="${MODULE_KEY_BY_DIR[$MODULE_NAME]}"
OUTPUT_FILE="$OUTPUT_DIR/${MODULE_NAME}_dependency_cone_dump.txt"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

ALL_FILES="$TMP_DIR/all-files.txt"
MATCHED_FILES="$TMP_DIR/matched-files.txt"
BASELINE_FILES="$TMP_DIR/baseline-files.txt"
IMPORT_FILES="$TMP_DIR/import-files.txt"
IMPORT_ONLY_FILES="$TMP_DIR/import-only-files.txt"
FINAL_FILES="$TMP_DIR/final-files.txt"
QUEUE_FILES="$TMP_DIR/import-queue.txt"
PROCESSED_FILES="$TMP_DIR/import-processed.txt"
ALLOWED_MODULE_KEYS="$TMP_DIR/allowed-module-keys.txt"
ALLOWED_MODULE_DIRS="$TMP_DIR/allowed-module-dirs.txt"
BOUNDARY_VIOLATIONS="$TMP_DIR/boundary-violations.tsv"
BOUNDARY_VIOLATION_KEYS="$TMP_DIR/boundary-violation-keys.txt"
MODULE_DEPENDENCIES="$TMP_DIR/module-dependencies.tsv"

: > "$ALL_FILES"
: > "$MATCHED_FILES"
: > "$BASELINE_FILES"
: > "$IMPORT_FILES"
: > "$IMPORT_ONLY_FILES"
: > "$QUEUE_FILES"
: > "$PROCESSED_FILES"
: > "$BOUNDARY_VIOLATIONS"
: > "$BOUNDARY_VIOLATION_KEYS"

# Read config/modules.php through the project's Composer autoloader and emit:
#   1) the selected module's recursive dependency closure
#   2) every configured module's direct depends_on list
php -d display_errors=1 -r '
require $argv[1]."/vendor/autoload.php";
$config = require $argv[1]."/config/modules.php";
$modules = is_array($config["modules"] ?? null) ? $config["modules"] : [];
$selected = $argv[2];
if (!array_key_exists($selected, $modules)) {
    fwrite(STDERR, "Selected module [{$selected}] is missing from config/modules.php.\n");
    exit(2);
}
$seen = [];
$visit = function (string $key) use (&$visit, &$seen, $modules): void {
    if (isset($seen[$key])) {
        return;
    }
    if (!array_key_exists($key, $modules)) {
        fwrite(STDERR, "Configured dependency [{$key}] is missing from config/modules.php.\n");
        exit(3);
    }
    $seen[$key] = true;
    foreach (($modules[$key]["depends_on"] ?? []) as $dependency) {
        if (is_string($dependency) && trim($dependency) !== "") {
            $visit(trim($dependency));
        }
    }
};
$visit($selected);
foreach (array_keys($seen) as $key) {
    echo "ALLOW\t{$key}\n";
}
foreach ($modules as $key => $definition) {
    $dependencies = array_values(array_filter(
        $definition["depends_on"] ?? [],
        fn ($value) => is_string($value) && trim($value) !== ""
    ));
    echo "DEPS\t{$key}\t".implode(",", $dependencies)."\n";
}
' "$ROOT_DIR" "$MODULE_KEY" > "$TMP_DIR/module-config-data.tsv"

awk -F '\t' '$1 == "ALLOW" { print $2 }' "$TMP_DIR/module-config-data.tsv" | sort -u > "$ALLOWED_MODULE_KEYS"
awk -F '\t' '$1 == "DEPS" { print $2 "\t" $3 }' "$TMP_DIR/module-config-data.tsv" > "$MODULE_DEPENDENCIES"

while IFS= read -r key; do
    dir="${MODULE_DIR_BY_KEY[$key]:-}"
    if [[ -z "$dir" ]]; then
        echo "Error: configured module [$key] has no matching app/Modules directory." >&2
        exit 1
    fi
    printf '%s\n' "$dir" >> "$ALLOWED_MODULE_DIRS"
done < "$ALLOWED_MODULE_KEYS"
sort -u "$ALLOWED_MODULE_DIRS" -o "$ALLOWED_MODULE_DIRS"

is_allowed_module_dir() {
    local dir="$1"
    grep -Fxq "$dir" "$ALLOWED_MODULE_DIRS"
}

module_dir_from_file() {
    local relative="${1#$ROOT_DIR/}"
    case "$relative" in
        app/Modules/*/*)
            relative="${relative#app/Modules/}"
            printf '%s\n' "${relative%%/*}"
            ;;
        *)
            return 1
            ;;
    esac
}

module_dir_from_class() {
    local class="${1#\\}"
    case "$class" in
        App\\Modules\\*)
            class="${class#App\\Modules\\}"
            printf '%s\n' "${class%%\\*}"
            ;;
        *)
            return 1
            ;;
    esac
}

# Calculate a source module's own transitive closure. Results are cached in files.
module_closure_file() {
    local module_key="$1"
    local cache_file="$TMP_DIR/closure-${module_key}.txt"

    if [[ -f "$cache_file" ]]; then
        printf '%s\n' "$cache_file"
        return 0
    fi

    php -r '
$rows = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$map = [];
foreach ($rows as $row) {
    [$key, $csv] = array_pad(explode("\t", $row, 2), 2, "");
    $map[$key] = $csv === "" ? [] : array_values(array_filter(explode(",", $csv)));
}
$selected = $argv[2];
$seen = [];
$visit = function (string $key) use (&$visit, &$seen, $map): void {
    if (isset($seen[$key])) return;
    $seen[$key] = true;
    foreach (($map[$key] ?? []) as $dependency) $visit($dependency);
};
$visit($selected);
foreach (array_keys($seen) as $key) echo $key."\n";
' "$MODULE_DEPENDENCIES" "$module_key" | sort -u > "$cache_file"

    printf '%s\n' "$cache_file"
}

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

    if [[ "$base" == ".env" || "$base" == .env.* ]]; then
        return 0
    fi

    case "${base,,}" in
        *.sqlite|*.db|*.png|*.jpg|*.jpeg|*.gif|*.webp|*.avif|*.ico|*.pdf|*.zip|*.gz|*.tar|*.tgz|*.7z|*.woff|*.woff2|*.ttf|*.otf|*.eot|*.mp3|*.mp4|*.mov|*.avi)
            return 0
            ;;
    esac

    return 1
}

is_allowed_cone_file() {
    local file="$1"
    local module_dir

    module_dir="$(module_dir_from_file "$file" 2>/dev/null || true)"
    [[ -z "$module_dir" ]] && return 0
    is_allowed_module_dir "$module_dir"
}

add_file() {
    local file="$1"
    local destination="$2"

    [[ -f "$file" ]] || return 0
    is_forbidden_file "$file" && return 0
    is_allowed_cone_file "$file" || return 0
    printf '%s\n' "$file" >> "$destination"
}

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

for relative_root in "${SEARCH_ROOTS[@]}"; do
    absolute_root="$ROOT_DIR/$relative_root"
    [[ -e "$absolute_root" ]] || continue

    while IFS= read -r file; do
        is_forbidden_file "$file" && continue
        is_allowed_cone_file "$file" || continue
        printf '%s\n' "$file" >> "$ALL_FILES"
    done < <(find "$absolute_root" -type f -print)
done

sort -u "$ALL_FILES" -o "$ALL_FILES"

SNAKE_NAME="$MODULE_KEY"
KEBAB_NAME="${SNAKE_NAME//_/-}"
LOWER_NAME="$(printf '%s' "$MODULE_NAME" | tr '[:upper:]' '[:lower:]')"
NAMESPACE="App\\Modules\\${MODULE_NAME}"

PATTERNS=(
    "$MODULE_NAME"
    "$SNAKE_NAME"
    "$KEBAB_NAME"
    "$LOWER_NAME"
    "$NAMESPACE"
    "module:${SNAKE_NAME}"
    "module('$SNAKE_NAME')"
    "module(\"$SNAKE_NAME\")"
)

while IFS= read -r file; do
    relative_file="${file#$ROOT_DIR/}"
    matched=false

    for pattern in "${PATTERNS[@]}"; do
        if [[ "$relative_file" == *"$pattern"* ]] || grep -IFlq -- "$pattern" "$file" 2>/dev/null; then
            matched=true
            break
        fi
    done

    if [[ "$matched" == true ]]; then
        printf '%s\n' "$file" >> "$MATCHED_FILES"
    fi
done < "$ALL_FILES"

# Include every file in the selected module and all recursively declared dependencies.
while IFS= read -r allowed_dir; do
    while IFS= read -r file; do
        add_file "$file" "$MATCHED_FILES"
    done < <(find "$MODULES_DIR/$allowed_dir" -type f -print)
done < "$ALLOWED_MODULE_DIRS"

BASELINE_PATHS=(
    "artisan"
    "bootstrap/app.php"
    "bootstrap/providers.php"
    "composer.json"
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/TestCase.php"
    "app/Http/Controllers/Controller.php"
    "app/Http/Middleware/EnsureModuleEnabled.php"
    "app/Support/Modules/ModuleManager.php"
    "config/app.php"
    "config/modules.php"
)

for relative_path in "${BASELINE_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$BASELINE_FILES"
done

# Include providers and routes for context, but they are not privileged to pull
# disallowed modules into the cone.
for baseline_dir in "$ROOT_DIR/app/Providers" "$ROOT_DIR/routes"; do
    [[ -d "$baseline_dir" ]] || continue
    while IFS= read -r file; do
        add_file "$file" "$BASELINE_FILES"
    done < <(find "$baseline_dir" -type f -print)
done

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
}

record_possible_boundary_violation() {
    local source_file="$1"
    local class="$2"
    local source_module_dir="$3"
    local target_module_dir="$4"
    local source_module_key="${MODULE_KEY_BY_DIR[$source_module_dir]:-}"
    local target_module_key="${MODULE_KEY_BY_DIR[$target_module_dir]:-}"
    local closure_file
    local key

    [[ -n "$source_module_key" && -n "$target_module_key" ]] || return 0

    closure_file="$(module_closure_file "$source_module_key")"
    grep -Fxq "$target_module_key" "$closure_file" && return 0

    key="${source_file}"$'\t'"${class}"$'\t'"${source_module_dir}"$'\t'"${target_module_dir}"
    grep -Fxq "$key" "$BOUNDARY_VIOLATION_KEYS" && return 0
    printf '%s\n' "$key" >> "$BOUNDARY_VIOLATION_KEYS"

    printf '%s\t%s\t%s\t%s\t%s\n' \
        "$source_file" \
        "$class" \
        "$source_module_dir" \
        "$target_module_dir" \
        "$(paste -sd ',' "$closure_file")" \
        >> "$BOUNDARY_VIOLATIONS"
}

cat "$MATCHED_FILES" "$BASELINE_FILES" \
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

        source_module_dir="$(module_dir_from_file "$file" 2>/dev/null || true)"

        while IFS= read -r class; do
            [[ -n "$class" ]] || continue

            target_module_dir="$(module_dir_from_class "$class" 2>/dev/null || true)"
            if [[ -n "$source_module_dir" && -n "$target_module_dir" ]]; then
                record_possible_boundary_violation \
                    "${file#$ROOT_DIR/}" \
                    "$class" \
                    "$source_module_dir" \
                    "$target_module_dir"
            fi

            resolved="$(class_to_file "$class" 2>/dev/null || true)"
            [[ -n "$resolved" && -f "$resolved" ]] || continue
            is_forbidden_file "$resolved" && continue

            # Out-of-closure modules are reported when applicable, but never pulled
            # into the selected dependency cone.
            is_allowed_cone_file "$resolved" || continue

            printf '%s\n' "$resolved" >> "$IMPORT_FILES"

            if [[ "$resolved" == *.php ]] && ! grep -Fxq "$resolved" "$PROCESSED_FILES"; then
                printf '%s\n' "$resolved" >> "$NEXT_QUEUE"
            fi
        done < <(extract_project_classes "$file" | sort -u)
    done < "$CURRENT_QUEUE"

    sort -u "$NEXT_QUEUE" > "$QUEUE_FILES"
done

cat "$MATCHED_FILES" "$BASELINE_FILES" "$IMPORT_FILES" \
    | sort -u \
    | while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        is_forbidden_file "$file" && continue
        is_allowed_cone_file "$file" || continue
        printf '%s\n' "$file"
    done > "$FINAL_FILES"

comm -23 \
    <(sort -u "$IMPORT_FILES") \
    <(cat "$MATCHED_FILES" "$BASELINE_FILES" | sort -u) \
    > "$IMPORT_ONLY_FILES"

FILE_COUNT="$(wc -l < "$FINAL_FILES" | tr -d ' ')"
BASELINE_COUNT="$(sort -u "$BASELINE_FILES" | wc -l | tr -d ' ')"
IMPORT_CANDIDATE_COUNT="$(sort -u "$IMPORT_FILES" | wc -l | tr -d ' ')"
IMPORT_ONLY_COUNT="$(wc -l < "$IMPORT_ONLY_FILES" | tr -d ' ')"
BOUNDARY_VIOLATION_COUNT="$(wc -l < "$BOUNDARY_VIOLATIONS" | tr -d ' ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core Module Dependency Cone Dump"
    echo "========================================"
    echo
    echo "Module: $MODULE_NAME"
    echo "Module key: $MODULE_KEY"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included files: $FILE_COUNT"
    echo "Baseline candidates included: $BASELINE_COUNT"
    echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
    echo "Files added only through import resolution: $IMPORT_ONLY_COUNT"
    echo "Possible module boundary violations: $BOUNDARY_VIOLATION_COUNT"
    echo
    echo "Allowed module dependency closure:"
    while IFS= read -r allowed_dir; do
        printf '  - %s (%s)\n' "$allowed_dir" "${MODULE_KEY_BY_DIR[$allowed_dir]}"
    done < "$ALLOWED_MODULE_DIRS"
    echo
    echo "Search identifiers:"
    for pattern in "${PATTERNS[@]}"; do
        printf '  - %s\n' "$pattern"
    done
    echo
    echo "Collection strategy:"
    printf '  - app/Modules/%s plus recursively declared depends_on modules (complete)\n' "$MODULE_NAME"
    echo "  - module-name and namespace references across non-module project roots"
    echo "  - shared routing, middleware, provider, module, and test infrastructure"
    echo "  - recursive project-local PHP import resolution"
    echo "  - imports into out-of-closure modules reported but not included"
    echo "  - all .env and .env.* files excluded"
    echo
    echo "POSSIBLE MODULE BOUNDARY VIOLATIONS"
    echo "==================================="

    if [[ "$BOUNDARY_VIOLATION_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS=$'\t' read -r source_file class source_module target_module allowed_csv; do
            echo
            echo "$source_file"
            echo "  references: $class"
            echo "  source module: $source_module"
            echo "  target module: $target_module"
            echo "  source module allowed closure: ${allowed_csv//,/", "}"
        done < <(sort -u "$BOUNDARY_VIOLATIONS")
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
echo "Allowed module closure: $(paste -sd ',' "$ALLOWED_MODULE_DIRS" | sed 's/,/, /g')"
echo "Baseline candidates: $BASELINE_COUNT"
echo "Import-resolved candidates found: $IMPORT_CANDIDATE_COUNT"
echo "Files added only through imports: $IMPORT_ONLY_COUNT"
echo "Possible module boundary violations: $BOUNDARY_VIOLATION_COUNT"
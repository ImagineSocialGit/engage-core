#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
# Produces: file_dumps/CRM_dependency_cone_dump.txt
#
# CRM is a cross-module application surface, not an app/Modules module.
#
# This cone is intentionally presentation/routing focused:
#   - complete CRM route surface
#   - complete CRM Blade surface
#   - CRM controllers plus controllers/middleware directly imported by CRM routes
#   - Blade partial/component/layout dependencies recursively referenced by CRM views
#   - views directly referenced by admitted CRM controllers
#   - routing/bootstrap/frontend baseline
#   - CRM/auth/route-related tests
#   - repository-wide explicit CRM integration references
#
# It intentionally does NOT recursively traverse every controller into models,
# services, actions, migrations, and transitive module dependencies. Doing that
# for a cross-module CRM surface would collapse into a near-full-project dump.
# Use make-module-dependency-cone-dump.sh for deeper module-owned internals.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$ROOT_DIR/file_dumps"
OUTPUT_FILE="$OUTPUT_DIR/CRM_dependency_cone_dump.txt"

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    echo "Error: artisan was not found under: $ROOT_DIR" >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
    exit 1
fi

if [[ ! -d "$ROOT_DIR/resources/views" ]]; then
    echo "Error: resources/views was not found under: $ROOT_DIR" >&2
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

ROUTE_FILES="$TMP_DIR/route-files.txt"
CRM_ROUTE_FILES="$TMP_DIR/crm-route-files.txt"
CRM_VIEW_FILES="$TMP_DIR/crm-view-files.txt"
CRM_CONTROLLER_FILES="$TMP_DIR/crm-controller-files.txt"
ROUTE_IMPORT_FILES="$TMP_DIR/route-import-files.txt"
CONTROLLER_VIEW_FILES="$TMP_DIR/controller-view-files.txt"
BLADE_DEPENDENCY_FILES="$TMP_DIR/blade-dependency-files.txt"
TEST_FILES="$TMP_DIR/test-files.txt"
INTEGRATION_FILES="$TMP_DIR/integration-files.txt"
BASELINE_FILES="$TMP_DIR/baseline-files.txt"
CONFIG_FILES="$TMP_DIR/config-files.txt"
FINAL_FILES="$TMP_DIR/final-files.txt"
BLADE_QUEUE="$TMP_DIR/blade-queue.txt"
BLADE_PROCESSED="$TMP_DIR/blade-processed.txt"

for file in \
    "$ROUTE_FILES" \
    "$CRM_ROUTE_FILES" \
    "$CRM_VIEW_FILES" \
    "$CRM_CONTROLLER_FILES" \
    "$ROUTE_IMPORT_FILES" \
    "$CONTROLLER_VIEW_FILES" \
    "$BLADE_DEPENDENCY_FILES" \
    "$TEST_FILES" \
    "$INTEGRATION_FILES" \
    "$BASELINE_FILES" \
    "$CONFIG_FILES" \
    "$FINAL_FILES" \
    "$BLADE_QUEUE" \
    "$BLADE_PROCESSED"
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

extract_config_roots() {
    local file="$1"

    grep -Eho "config\([[:space:]]*['\"][A-Za-z0-9_.-]+['\"]" "$file" 2>/dev/null \
        | sed -E "s/^config\([[:space:]]*['\"]//; s/['\"]$//" \
        | cut -d. -f1 \
        | sort -u \
        || true
}

resolve_config_dependencies() {
    local source_file="$1"
    local config_root

    while IFS= read -r config_root; do
        [[ -n "$config_root" ]] || continue

        add_file "$ROOT_DIR/config/${config_root}.php" "$CONFIG_FILES"

        if [[ -d "$ROOT_DIR/config/${config_root}" ]]; then
            add_directory "$ROOT_DIR/config/${config_root}" "$CONFIG_FILES"
        fi
    done < <(extract_config_roots "$source_file")
}

view_name_to_file() {
    local view_name="$1"
    local relative

    [[ -n "$view_name" ]] || return 1

    # Ignore package/vendor namespace views such as package::view.
    [[ "$view_name" == *"::"* ]] && return 1

    relative="${view_name//./\/}.blade.php"
    printf '%s/resources/views/%s\n' "$ROOT_DIR" "$relative"
}

component_name_to_file() {
    local component="$1"
    local relative

    [[ -n "$component" ]] || return 1

    # Dynamic component names cannot be resolved statically.
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
    "/@(?:extends|include|includeIf|includeWhen|includeUnless|includeFirst|component)\\s*\\(\\s*[\x27\x22]([A-Za-z0-9_.:-]+)[\x27\x22]/",
    "/\\bview\\s*\\(\\s*[\x27\x22]([A-Za-z0-9_.:-]+)[\x27\x22]/",
    "/\\bView\\s*::\\s*make\\s*\\(\\s*[\x27\x22]([A-Za-z0-9_.:-]+)[\x27\x22]/",
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

extract_php_view_references() {
    local file="$1"

    php -d display_errors=1 -r '
$file = $argv[1];
$source = file_get_contents($file);

if (!is_string($source) || $source === "") {
    exit(0);
}

$patterns = [
    "/\\bview\\s*\\(\\s*[\x27\x22]([A-Za-z0-9_.:-]+)[\x27\x22]/",
    "/\\bView\\s*::\\s*make\\s*\\(\\s*[\x27\x22]([A-Za-z0-9_.:-]+)[\x27\x22]/",
];

$seen = [];

foreach ($patterns as $pattern) {
    if (!preg_match_all($pattern, $source, $matches)) {
        continue;
    }

    foreach ($matches[1] as $view) {
        $seen[$view] = true;
    }
}

foreach (array_keys($seen) as $view) {
    echo $view."\n";
}
' "$file"
}

# ---------------------------------------------------------------------------
# 1. Routing/bootstrap baseline
# ---------------------------------------------------------------------------

add_directory "$ROOT_DIR/routes" "$ROUTE_FILES"

add_file "$ROOT_DIR/routes/crm.php" "$CRM_ROUTE_FILES"
add_directory "$ROOT_DIR/routes/crm" "$CRM_ROUTE_FILES"

BASELINE_PATHS=(
    "artisan"
    "bootstrap/app.php"
    "bootstrap/providers.php"
    "composer.json"
    "composer.lock"
    "package.json"
    "package-lock.json"
    "vite.config.js"
    "vite.config.ts"
    "phpunit.xml"
    "phpunit.xml.dist"
    "tests/TestCase.php"
    "app/Http/Controllers/Controller.php"
    "app/Providers/AppServiceProvider.php"
    "config/app.php"
    "config/auth.php"
    "config/client.php"
    "config/domains.php"
    "config/modules.php"
    ".env.example"
    "resources/css/app.css"
    "resources/js/app.js"
    "resources/js/bootstrap.js"
)

for relative_path in "${BASELINE_PATHS[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$BASELINE_FILES"
done

# ---------------------------------------------------------------------------
# 2. First-class CRM presentation/controller roots
# ---------------------------------------------------------------------------

add_directory "$ROOT_DIR/resources/views/crm" "$CRM_VIEW_FILES"
add_directory "$ROOT_DIR/app/Http/Controllers/CRM" "$CRM_CONTROLLER_FILES"

# Module-owned controllers explicitly organized under a CRM namespace/directory.
if [[ -d "$ROOT_DIR/app/Modules" ]]; then
    while IFS= read -r directory; do
        add_directory "$directory" "$CRM_CONTROLLER_FILES"
    done < <(find "$ROOT_DIR/app/Modules" -type d -path '*/Controllers/CRM' -print)
fi

# Existing application login is retained as a candidate CRM/application auth
# boundary until route/controller ownership is confirmed.
add_file "$ROOT_DIR/resources/views/auth/login.blade.php" "$CRM_VIEW_FILES"

# Common anonymous CRM shell locations, if present.
CRM_COMPONENT_CANDIDATES=(
    "resources/views/components/layouts/crm.blade.php"
    "resources/views/components/crm/layout.blade.php"
    "resources/views/components/crm/success-notification.blade.php"
)

for relative_path in "${CRM_COMPONENT_CANDIDATES[@]}"; do
    add_file "$ROOT_DIR/$relative_path" "$CRM_VIEW_FILES"
done

# ---------------------------------------------------------------------------
# 3. Direct runtime owners imported by CRM routes
# ---------------------------------------------------------------------------

while IFS= read -r route_file; do
    [[ -f "$route_file" && "$route_file" == *.php ]] || continue

    resolve_config_dependencies "$route_file"

    while IFS= read -r class; do
        [[ -n "$class" ]] || continue

        resolved="$(class_to_file "$class" 2>/dev/null || true)"
        [[ -n "$resolved" && -f "$resolved" ]] || continue
        is_forbidden_file "$resolved" && continue

        add_file "$resolved" "$ROUTE_IMPORT_FILES"
    done < <(extract_project_classes "$route_file" | sort -u)
done < "$CRM_ROUTE_FILES"

# Resolve configs and directly-rendered views from CRM controllers and direct
# CRM-route runtime owners. We intentionally stop here instead of recursively
# traversing all imported models/services/actions.
cat "$CRM_CONTROLLER_FILES" "$ROUTE_IMPORT_FILES" \
    | sort -u \
    | while IFS= read -r php_file; do
        [[ -f "$php_file" && "$php_file" == *.php ]] || continue

        resolve_config_dependencies "$php_file"

        while IFS= read -r view_name; do
            [[ -n "$view_name" ]] || continue

            resolved_view="$(view_name_to_file "$view_name" 2>/dev/null || true)"
            [[ -n "$resolved_view" ]] || continue

            add_file "$resolved_view" "$CONTROLLER_VIEW_FILES"
        done < <(extract_php_view_references "$php_file" | sort -u)
    done

# ---------------------------------------------------------------------------
# 4. Recursively resolve Blade layout/include/component dependencies
# ---------------------------------------------------------------------------

cat "$CRM_VIEW_FILES" "$CONTROLLER_VIEW_FILES" \
    | sort -u \
    | grep -E '\.blade\.php$' > "$BLADE_QUEUE" || true

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
# 5. CRM/auth/route-focused tests
# ---------------------------------------------------------------------------

if [[ -d "$ROOT_DIR/tests" ]]; then
    while IFS= read -r test_file; do
        is_forbidden_file "$test_file" && continue

        relative="${test_file#$ROOT_DIR/}"
        include=false

        case "${relative,,}" in
            *crm*|*auth*|*route*)
                include=true
                ;;
        esac

        if [[ "$include" == false ]]; then
            TEST_PATTERNS=(
                "crm."
                "/crm"
                "crm/"
                "crm."
                "route('crm."
                'route("crm.'
                "route('login'"
                'route("login"'
                "auth.login"
                "assertRedirectToRoute('login'"
                'assertRedirectToRoute("login"'
            )

            for pattern in "${TEST_PATTERNS[@]}"; do
                if grep -IFlq -- "$pattern" "$test_file" 2>/dev/null; then
                    include=true
                    break
                fi
            done
        fi

        if [[ "$include" == true ]]; then
            add_file "$test_file" "$TEST_FILES"
        fi
    done < <(find "$ROOT_DIR/tests" -type f -print)
fi

# ---------------------------------------------------------------------------
# 6. Repository-wide explicit CRM integration perimeter
# ---------------------------------------------------------------------------

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
    "resources/views/crm"
    "views/crm"
    "Controllers\\CRM"
    "Controllers/CRM"
    "<x-layouts.crm"
    "route('crm."
    "route(\"crm."
    "to_route('crm."
    "to_route(\"crm."
    "redirect()->route('crm."
    "redirect()->route(\"crm."
    "crm."
    "CRM"
)

for relative_root in "${SEARCH_ROOTS[@]}"; do
    absolute_root="$ROOT_DIR/$relative_root"
    [[ -d "$absolute_root" ]] || continue

    while IFS= read -r file; do
        is_forbidden_file "$file" && continue

        relative_file="${file#$ROOT_DIR/}"
        matched=false

        # Strong path-based signals first.
        case "$relative_file" in
            resources/views/crm/*|app/Http/Controllers/CRM/*|app/Modules/*/Controllers/CRM/*|routes/crm.php|routes/crm/*)
                matched=true
                ;;
        esac

        if [[ "$matched" == false ]]; then
            for pattern in "${MATCH_PATTERNS[@]}"; do
                if [[ "$relative_file" == *"$pattern"* ]] \
                    || grep -IFlq -- "$pattern" "$file" 2>/dev/null
                then
                    matched=true
                    break
                fi
            done
        fi

        if [[ "$matched" == true ]]; then
            add_file "$file" "$INTEGRATION_FILES"
        fi
    done < <(find "$absolute_root" -type f -print)
done

# ---------------------------------------------------------------------------
# 7. Final assembly
# ---------------------------------------------------------------------------

cat \
    "$ROUTE_FILES" \
    "$CRM_VIEW_FILES" \
    "$CRM_CONTROLLER_FILES" \
    "$ROUTE_IMPORT_FILES" \
    "$CONTROLLER_VIEW_FILES" \
    "$BLADE_DEPENDENCY_FILES" \
    "$TEST_FILES" \
    "$INTEGRATION_FILES" \
    "$BASELINE_FILES" \
    "$CONFIG_FILES" \
    | sort -u \
    | while IFS= read -r file; do
        [[ -f "$file" ]] || continue
        is_forbidden_file "$file" && continue
        printf '%s\n' "$file"
    done > "$FINAL_FILES"

FILE_COUNT="$(wc -l < "$FINAL_FILES" | tr -d ' ')"
ROUTE_COUNT="$(sort -u "$ROUTE_FILES" | wc -l | tr -d ' ')"
CRM_ROUTE_COUNT="$(sort -u "$CRM_ROUTE_FILES" | wc -l | tr -d ' ')"
CRM_VIEW_COUNT="$(sort -u "$CRM_VIEW_FILES" | wc -l | tr -d ' ')"
CRM_CONTROLLER_COUNT="$(sort -u "$CRM_CONTROLLER_FILES" | wc -l | tr -d ' ')"
ROUTE_IMPORT_COUNT="$(sort -u "$ROUTE_IMPORT_FILES" | wc -l | tr -d ' ')"
CONTROLLER_VIEW_COUNT="$(sort -u "$CONTROLLER_VIEW_FILES" | wc -l | tr -d ' ')"
BLADE_DEPENDENCY_COUNT="$(sort -u "$BLADE_DEPENDENCY_FILES" | wc -l | tr -d ' ')"
TEST_COUNT="$(sort -u "$TEST_FILES" | wc -l | tr -d ' ')"
INTEGRATION_COUNT="$(sort -u "$INTEGRATION_FILES" | wc -l | tr -d ' ')"
BASELINE_COUNT="$(sort -u "$BASELINE_FILES" | wc -l | tr -d ' ')"
CONFIG_COUNT="$(sort -u "$CONFIG_FILES" | wc -l | tr -d ' ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

{
    echo "Engage Core CRM Dependency Cone Dump"
    echo "===================================="
    echo
    echo "Surface: CRM routing and presentation"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Included files: $FILE_COUNT"
    echo "All route files included: $ROUTE_COUNT"
    echo "CRM route seed files: $CRM_ROUTE_COUNT"
    echo "First-class CRM/auth view files: $CRM_VIEW_COUNT"
    echo "First-class CRM controller files: $CRM_CONTROLLER_COUNT"
    echo "Direct project-local imports from CRM routes: $ROUTE_IMPORT_COUNT"
    echo "Views directly referenced by admitted controllers: $CONTROLLER_VIEW_COUNT"
    echo "Recursively resolved Blade dependencies: $BLADE_DEPENDENCY_COUNT"
    echo "CRM/auth/route-focused tests: $TEST_COUNT"
    echo "Explicit CRM integration-perimeter files: $INTEGRATION_COUNT"
    echo "Baseline files included: $BASELINE_COUNT"
    echo "Config dependencies included: $CONFIG_COUNT"
    echo
    echo "Collection strategy:"
    echo "  - complete routes/** for route/bootstrap context"
    echo "  - routes/crm.php and routes/crm/** treated as first-class CRM route roots"
    echo "  - complete resources/views/crm/**"
    echo "  - resources/views/auth/login.blade.php retained as an auth-boundary candidate"
    echo "  - complete app/Http/Controllers/CRM/**"
    echo "  - complete app/Modules/*/Controllers/CRM/**"
    echo "  - direct project-local imports from CRM route roots"
    echo "  - views directly referenced by CRM controllers/direct CRM-route runtime owners"
    echo "  - recursive static Blade @include/@extends/@component/view references"
    echo "  - recursive anonymous Blade <x-...> component references"
    echo "  - CRM/auth/route-related test discovery by path and content"
    echo "  - repository-wide explicit CRM references retained as integration evidence"
    echo "  - bootstrap/provider/frontend build baseline retained"
    echo "  - config(...) roots resolved from CRM routes/controllers"
    echo "  - all environment files excluded except .env.example"
    echo
    echo "Intentional boundary:"
    echo "  - controller imports are not recursively expanded into every model/service/action"
    echo "  - model table and migration ownership are not resolved for this cross-module surface"
    echo "  - use module dependency cones for deep module-owned runtime/schema dependencies"
    echo
    echo "Static-resolution limitations:"
    echo "  - dynamic Blade component/view names cannot be resolved"
    echo "  - runtime-generated route/view names may require repository-wide integration matching"
    echo
    echo "CRM ROUTE ROOTS"
    echo "==============="

    if [[ "$CRM_ROUTE_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < <(sort -u "$CRM_ROUTE_FILES")
    fi

    echo
    echo "DIRECT CRM ROUTE IMPORTS"
    echo "========================"

    if [[ "$ROUTE_IMPORT_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < <(sort -u "$ROUTE_IMPORT_FILES")
    fi

    echo
    echo "BLADE DEPENDENCIES OUTSIDE resources/views/crm"
    echo "==============================================="

    outside_count=0

    while IFS= read -r file; do
        relative="${file#$ROOT_DIR/}"

        case "$relative" in
            resources/views/crm/*)
                continue
                ;;
        esac

        echo "$relative"
        outside_count=$((outside_count + 1))
    done < <(
        cat "$CRM_VIEW_FILES" "$CONTROLLER_VIEW_FILES" "$BLADE_DEPENDENCY_FILES" \
            | sort -u
    )

    if [[ "$outside_count" -eq 0 ]]; then
        echo "None detected."
    fi

    echo
    echo "CRM/AUTH/ROUTE-FOCUSED TESTS"
    echo "============================"

    if [[ "$TEST_COUNT" -eq 0 ]]; then
        echo "None detected."
    else
        while IFS= read -r file; do
            echo "${file#$ROOT_DIR/}"
        done < <(sort -u "$TEST_FILES")
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
echo "All route files: $ROUTE_COUNT"
echo "CRM route seeds: $CRM_ROUTE_COUNT"
echo "CRM/auth view seeds: $CRM_VIEW_COUNT"
echo "CRM controller seeds: $CRM_CONTROLLER_COUNT"
echo "Direct CRM route imports: $ROUTE_IMPORT_COUNT"
echo "Controller-referenced views: $CONTROLLER_VIEW_COUNT"
echo "Recursive Blade dependencies: $BLADE_DEPENDENCY_COUNT"
echo "CRM/auth/route-focused tests: $TEST_COUNT"
echo "CRM integration-perimeter files: $INTEGRATION_COUNT"
echo "Baseline files: $BASELINE_COUNT"
echo "Config dependencies: $CONFIG_COUNT"

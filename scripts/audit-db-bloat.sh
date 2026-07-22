#!/usr/bin/env bash
set -Eeuo pipefail

# Place in scripts/ under the Engage Core repository root.
#
# Usage:
#   ./scripts/audit-db-bloat
#   ./scripts/audit-db-bloat Webinars
#   ./scripts/audit-db-bloat webinars 50
#
# Optional environment variables:
#   DB_BLOAT_AUDIT_ROW_LIMIT=25
#   DB_BLOAT_AUDIT_DUPLICATE_LIMIT=100
#
# Produces:
#   file_dumps/<ModuleName>_db_bloat_audit_<UTC timestamp>/
#   file_dumps/<ModuleName>_db_bloat_audit_<UTC timestamp>_combined.txt
#   file_dumps/<ModuleName>_db_bloat_audit_<UTC timestamp>.tar.gz
#
# Boots Laravel directly through bootstrap/app.php; Laravel Tinker is not required.
# Section-level failures are recorded in 98_errors.tsv and surfaced prominently.
#
# The audit is read-only. It never writes raw payload, meta, body, answer_text,
# email, phone, token, URL, IP address, user-agent, or other sensitive values.
# Variable-width values are represented only by byte length, SHA-256 hash, and
# structural JSON paths.

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd -- "$SCRIPT_DIR/.." && pwd)"
MODULES_DIR="$ROOT_DIR/app/Modules"
MODULE_CONFIG="$ROOT_DIR/config/modules.php"
OUTPUT_ROOT="$ROOT_DIR/file_dumps"

DEFAULT_ROW_LIMIT="${DB_BLOAT_AUDIT_ROW_LIMIT:-25}"
DUPLICATE_LIMIT="${DB_BLOAT_AUDIT_DUPLICATE_LIMIT:-100}"

if [[ ! -d "$MODULES_DIR" ]]; then
    echo "Error: app/Modules was not found under: $ROOT_DIR" >&2
    echo "Place this script in the Engage Core repository scripts/ directory." >&2
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

if [[ ! -f "$ROOT_DIR/artisan" ]]; then
    echo "Error: artisan was not found under: $ROOT_DIR" >&2
    exit 1
fi

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

resolve_requested_module() {
    local requested="${1:-}"
    local normalized
    local module_dir
    local module_key

    [[ -n "$requested" ]] || return 1

    normalized="$(printf '%s' "$requested" | tr '[:upper:]' '[:lower:]')"

    for module_dir in "${MODULES[@]}"; do
        module_key="${MODULE_KEY_BY_DIR[$module_dir]}"

        if [[ "${module_dir,,}" == "$normalized" || "$module_key" == "$normalized" ]]; then
            printf '%s\n' "$module_dir"
            return 0
        fi
    done

    return 1
}

REQUESTED_MODULE="${1:-}"
ROW_LIMIT="${2:-$DEFAULT_ROW_LIMIT}"

if ! [[ "$ROW_LIMIT" =~ ^[0-9]+$ ]] || (( ROW_LIMIT < 1 || ROW_LIMIT > 500 )); then
    echo "Error: row limit must be an integer between 1 and 500." >&2
    exit 1
fi

if ! [[ "$DUPLICATE_LIMIT" =~ ^[0-9]+$ ]] || (( DUPLICATE_LIMIT < 1 || DUPLICATE_LIMIT > 1000 )); then
    echo "Error: DB_BLOAT_AUDIT_DUPLICATE_LIMIT must be between 1 and 1000." >&2
    exit 1
fi

if [[ -n "$REQUESTED_MODULE" ]]; then
    MODULE_NAME="$(resolve_requested_module "$REQUESTED_MODULE" || true)"

    if [[ -z "$MODULE_NAME" ]]; then
        echo "Error: module [$REQUESTED_MODULE] was not found." >&2
        echo "Available modules: ${MODULES[*]}" >&2
        exit 1
    fi
else
    echo "Select a module database-bloat audit:"
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
fi

MODULE_KEY="${MODULE_KEY_BY_DIR[$MODULE_NAME]}"
GENERATED_TOKEN="$(date -u '+%Y%m%dT%H%M%SZ')"
GENERATED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
AUDIT_BASENAME="${MODULE_NAME}_db_bloat_audit_${GENERATED_TOKEN}"
AUDIT_DIR="$OUTPUT_ROOT/$AUDIT_BASENAME"
COMBINED_FILE="$OUTPUT_ROOT/${AUDIT_BASENAME}_combined.txt"
ARCHIVE_FILE="$OUTPUT_ROOT/${AUDIT_BASENAME}.tar.gz"

mkdir -p "$OUTPUT_ROOT" "$AUDIT_DIR"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

MODULE_CONFIG_DATA="$TMP_DIR/module-config-data.tsv"
ALLOWED_MODULE_KEYS="$TMP_DIR/allowed-module-keys.txt"
ALLOWED_MODULE_DIRS="$TMP_DIR/allowed-module-dirs.txt"
RUNNER_FILE="$TMP_DIR/audit-db-bloat-runner.php"

# Resolve the selected module's recursive dependency closure using the same
# config/modules.php contract as the dependency-cone command.
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
' "$ROOT_DIR" "$MODULE_KEY" > "$MODULE_CONFIG_DATA"

awk -F '\t' '$1 == "ALLOW" { print $2 }' "$MODULE_CONFIG_DATA" \
    | sort -u > "$ALLOWED_MODULE_KEYS"

while IFS= read -r key; do
    dir="${MODULE_DIR_BY_KEY[$key]:-}"

    if [[ -z "$dir" ]]; then
        echo "Error: configured module [$key] has no matching app/Modules directory." >&2
        exit 1
    fi

    printf '%s\n' "$dir" >> "$ALLOWED_MODULE_DIRS"
done < "$ALLOWED_MODULE_KEYS"

sort -u "$ALLOWED_MODULE_DIRS" -o "$ALLOWED_MODULE_DIRS"

cat > "$RUNNER_FILE" <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$bootstrapRoot = rtrim((string) getenv('ENGAGE_AUDIT_ROOT'), DIRECTORY_SEPARATOR);

if ($bootstrapRoot === '') {
    throw new RuntimeException('Missing audit environment value [ENGAGE_AUDIT_ROOT].');
}

$autoloadPath = $bootstrapRoot.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
$bootstrapPath = $bootstrapRoot.DIRECTORY_SEPARATOR.'bootstrap'.DIRECTORY_SEPARATOR.'app.php';

if (! is_file($autoloadPath)) {
    throw new RuntimeException("Composer autoloader was not found at [{$autoloadPath}].");
}

if (! is_file($bootstrapPath)) {
    throw new RuntimeException("Laravel bootstrap file was not found at [{$bootstrapPath}].");
}

require $autoloadPath;

$app = require $bootstrapPath;
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$root = rtrim((string) getenv('ENGAGE_AUDIT_ROOT'), DIRECTORY_SEPARATOR);
$output = rtrim((string) getenv('ENGAGE_AUDIT_OUTPUT'), DIRECTORY_SEPARATOR);
$moduleName = trim((string) getenv('ENGAGE_AUDIT_MODULE_NAME'));
$moduleKey = trim((string) getenv('ENGAGE_AUDIT_MODULE_KEY'));
$allowedModuleDirsFile = (string) getenv('ENGAGE_AUDIT_ALLOWED_DIRS');
$rowLimit = max(1, (int) getenv('ENGAGE_AUDIT_ROW_LIMIT'));
$duplicateLimit = max(1, (int) getenv('ENGAGE_AUDIT_DUPLICATE_LIMIT'));
$generatedAt = trim((string) getenv('ENGAGE_AUDIT_GENERATED_AT'));

foreach ([
    'root' => $root,
    'output' => $output,
    'moduleName' => $moduleName,
    'moduleKey' => $moduleKey,
    'allowedModuleDirsFile' => $allowedModuleDirsFile,
] as $name => $value) {
    if ($value === '') {
        throw new RuntimeException("Missing audit environment value [{$name}].");
    }
}

if (! is_dir($output) && ! mkdir($output, 0775, true) && ! is_dir($output)) {
    throw new RuntimeException("Unable to create audit output directory [{$output}].");
}

$allowedModuleDirs = array_values(array_filter(
    file($allowedModuleDirsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
));

$driver = DB::connection()->getDriverName();

if ($driver !== 'mysql') {
    throw new RuntimeException(
        "audit-db-bloat currently supports MySQL-compatible connections; active driver is [{$driver}]."
    );
}

$database = DB::connection()->getDatabaseName();

if (! is_string($database) || trim($database) === '') {
    throw new RuntimeException('Unable to resolve the active database name.');
}

$database = trim($database);

$normalizeCell = static function (mixed $value): string {
    if ($value === null) {
        return '';
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format(DateTimeInterface::ATOM);
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if (is_array($value) || is_object($value)) {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        $value = is_string($encoded) ? $encoded : '[unencodable]';
    }

    $value = (string) $value;

    return str_replace(["\t", "\r", "\n"], [' ', ' ', ' '], $value);
};

$openTsv = static function (string $filename, array $headers) use ($output, $normalizeCell): array {
    $path = $output.DIRECTORY_SEPARATOR.$filename;
    $handle = fopen($path, 'wb');

    if ($handle === false) {
        throw new RuntimeException("Unable to open [{$path}] for writing.");
    }

    fputcsv(
        $handle,
        array_map($normalizeCell, $headers),
        "\t",
        '"',
        '\\',
    );

    $write = static function (array $row) use ($handle, $normalizeCell): void {
        fputcsv(
            $handle,
            array_map($normalizeCell, $row),
            "\t",
            '"',
            '\\',
        );
    };

    return [$handle, $write];
};

[$errorHandle, $writeError] = $openTsv('98_errors.tsv', [
    'section',
    'table',
    'column',
    'message',
]);

$recordError = static function (
    string $section,
    ?string $table,
    ?string $column,
    Throwable|string $error,
) use ($writeError): void {
    $writeError([
        $section,
        $table,
        $column,
        $error instanceof Throwable ? $error->getMessage() : $error,
    ]);
};

$quoteIdentifier = static function (string $identifier): string {
    if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException("Unsafe SQL identifier [{$identifier}].");
    }

    return '`'.$identifier.'`';
};

$snakeCase = static function (string $value): string {
    $value = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $value) ?? $value;
    $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;

    return strtolower($value);
};

$moduleDirFromFile = static function (string $file) use ($root): ?string {
    $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));

    if (preg_match('#^app/Modules/([^/]+)/#', $relative, $matches) !== 1) {
        return null;
    }

    return $matches[1];
};

$isAllowedRuntimeFile = static function (string $file) use (
    $root,
    $allowedModuleDirs,
    $moduleDirFromFile,
): bool {
    $real = realpath($file);
    $rootReal = realpath($root);

    if ($real === false || $rootReal === false || ! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
        return false;
    }

    $relative = str_replace('\\', '/', substr($real, strlen($rootReal) + 1));

    if (! str_starts_with($relative, 'app/')) {
        return false;
    }

    $moduleDir = $moduleDirFromFile($real);

    return $moduleDir === null || in_array($moduleDir, $allowedModuleDirs, true);
};

$classToFile = static function (string $class) use ($root): ?string {
    $class = ltrim(trim($class), '\\');

    if (! str_starts_with($class, 'App\\')) {
        return null;
    }

    $relative = 'app'.DIRECTORY_SEPARATOR
        .str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4))
        .'.php';

    $path = $root.DIRECTORY_SEPARATOR.$relative;

    return is_file($path) ? $path : null;
};

$extractProjectClasses = static function (string $file) use ($classToFile): array {
    $source = file_get_contents($file);

    if (! is_string($source) || $source === '') {
        return [];
    }

    $classes = [];

    if (preg_match_all(
        '/^\s*use\s+(App\\\\[^;{]+);/m',
        $source,
        $matches,
    )) {
        foreach ($matches[1] as $class) {
            $class = preg_replace('/\s+as\s+.+$/i', '', trim($class)) ?? trim($class);

            if ($classToFile($class) !== null) {
                $classes[$class] = true;
            }
        }
    }

    if (preg_match_all(
        '/\\\\?(App\\\\[A-Za-z_][A-Za-z0-9_\\\\]*)/',
        $source,
        $matches,
    )) {
        foreach ($matches[1] as $class) {
            $class = rtrim($class, '\\');

            if ($classToFile($class) !== null) {
                $classes[$class] = true;
            }
        }
    }

    if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespaceMatch) === 1) {
        $namespace = trim($namespaceMatch[1]);
        $tokens = token_get_all($source);

        foreach ($tokens as $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];

            if (preg_match('/^[A-Z][A-Za-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            $class = $namespace.'\\'.$name;

            if ($classToFile($class) !== null) {
                $classes[$class] = true;
            }
        }
    }

    return array_keys($classes);
};

$extractDirectTables = static function (string $file): array {
    $source = file_get_contents($file);

    if (! is_string($source) || $source === '') {
        return [];
    }

    $tables = [];
    $patterns = [
        '/(?:Schema::(?:create|table|hasTable|drop|dropIfExists)|DB::table|->from|->join|->leftJoin|->rightJoin|->updateOrInsert|->insertOrIgnore)\s*\(\s*([\'"])([A-Za-z0-9_]+)\1/s',
        '/protected\s+\$table\s*=\s*([\'"])([A-Za-z0-9_]+)\1/s',
    ];

    foreach ($patterns as $pattern) {
        if (! preg_match_all($pattern, $source, $matches, PREG_SET_ORDER)) {
            continue;
        }

        foreach ($matches as $match) {
            $tables[$match[2]] = true;
        }
    }

    return array_keys($tables);
};

$runtimeQueue = [];
$processedFiles = [];
$modelRows = [];
$directTableSources = [];

foreach ($allowedModuleDirs as $allowedModuleDir) {
    $directory = $root.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules'
        .DIRECTORY_SEPARATOR.$allowedModuleDir;

    if (! is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $runtimeQueue[$fileInfo->getPathname()] = true;
    }
}

while ($runtimeQueue !== []) {
    $file = array_key_first($runtimeQueue);
    unset($runtimeQueue[$file]);

    if (isset($processedFiles[$file]) || ! is_file($file) || ! $isAllowedRuntimeFile($file)) {
        continue;
    }

    $processedFiles[$file] = true;
    $moduleDir = $moduleDirFromFile($file) ?? 'shared';

    foreach ($extractDirectTables($file) as $table) {
        $directTableSources[$table][$file] = true;
    }

    foreach ($extractProjectClasses($file) as $class) {
        $resolved = $classToFile($class);

        if ($resolved !== null && $isAllowedRuntimeFile($resolved) && ! isset($processedFiles[$resolved])) {
            $runtimeQueue[$resolved] = true;
        }
    }

    $source = file_get_contents($file);

    if (! is_string($source) || $source === '') {
        continue;
    }

    if (preg_match('/^\s*namespace\s+([^;]+);/m', $source, $namespaceMatch) !== 1) {
        continue;
    }

    if (preg_match(
        '/^\s*(?:(?:final|abstract|readonly)\s+)*class\s+([A-Za-z_][A-Za-z0-9_]*)/m',
        $source,
        $classMatch,
    ) !== 1) {
        continue;
    }

    $class = trim($namespaceMatch[1]).'\\'.$classMatch[1];

    try {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            continue;
        }

        $model = $reflection->newInstanceWithoutConstructor();
        $table = $model->getTable();

        if (! is_string($table) || trim($table) === '') {
            continue;
        }

        $modelRows[] = [
            'module' => $moduleDir,
            'class' => $class,
            'table' => trim($table),
            'source_file' => str_replace('\\', '/', substr($file, strlen($root) + 1)),
        ];
    } catch (Throwable $error) {
        $recordError('model_resolution', null, null, $class.': '.$error->getMessage());
    }
}

[$modelHandle, $writeModel] = $openTsv('01_model_table_map.tsv', [
    'module',
    'class',
    'table',
    'source_file',
]);

usort(
    $modelRows,
    static fn (array $a, array $b): int => [$a['table'], $a['class']] <=> [$b['table'], $b['class']]
);

foreach ($modelRows as $row) {
    $writeModel($row);
}
fclose($modelHandle);

$allTableRows = DB::select(
    <<<'SQL'
SELECT
    TABLE_NAME AS table_name,
    ENGINE AS engine,
    TABLE_COLLATION AS table_collation,
    TABLE_ROWS AS approximate_rows,
    AVG_ROW_LENGTH AS average_row_length,
    DATA_LENGTH AS data_length,
    INDEX_LENGTH AS index_length,
    DATA_FREE AS data_free
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = ?
  AND TABLE_TYPE = 'BASE TABLE'
ORDER BY TABLE_NAME
SQL,
    [$database],
);

$allTables = [];

foreach ($allTableRows as $row) {
    $allTables[(string) $row->table_name] = $row;
}

$modelTables = [];

foreach ($modelRows as $row) {
    $modelTables[$row['table']] = true;
}

$directTables = array_fill_keys(array_keys($directTableSources), true);
$selectedSingular = Str::singular($moduleKey);
$prefixes = array_values(array_unique(array_filter([
    $moduleKey.'_',
    $selectedSingular.'_',
])));

$targetTables = [];

foreach (array_keys($allTables) as $table) {
    if (isset($modelTables[$table]) || isset($directTables[$table])) {
        $targetTables[$table] = true;
        continue;
    }

    foreach ($prefixes as $prefix) {
        if (str_starts_with($table, $prefix)) {
            $targetTables[$table] = true;
            break;
        }
    }
}

$targetTables = array_keys($targetTables);
sort($targetTables);

if ($targetTables === []) {
    throw new RuntimeException(
        "No database tables were resolved for module [{$moduleName}] and its dependency closure."
    );
}

$columnRows = DB::select(
    <<<'SQL'
SELECT
    TABLE_NAME AS table_name,
    COLUMN_NAME AS column_name,
    ORDINAL_POSITION AS ordinal_position,
    COLUMN_DEFAULT AS column_default,
    IS_NULLABLE AS is_nullable,
    DATA_TYPE AS data_type,
    CHARACTER_MAXIMUM_LENGTH AS character_maximum_length,
    NUMERIC_PRECISION AS numeric_precision,
    NUMERIC_SCALE AS numeric_scale,
    COLUMN_TYPE AS column_type,
    COLUMN_KEY AS column_key,
    EXTRA AS extra,
    COLLATION_NAME AS collation_name,
    COLUMN_COMMENT AS column_comment
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = ?
ORDER BY TABLE_NAME, ORDINAL_POSITION
SQL,
    [$database],
);

$columnsByTable = [];

foreach ($columnRows as $row) {
    $table = (string) $row->table_name;

    if (! in_array($table, $targetTables, true)) {
        continue;
    }

    $columnsByTable[$table][(string) $row->column_name] = $row;
}

[$columnHandle, $writeColumn] = $openTsv('03_columns.tsv', [
    'table',
    'column',
    'ordinal',
    'data_type',
    'column_type',
    'nullable',
    'default',
    'column_key',
    'extra',
    'character_maximum_length',
    'numeric_precision',
    'numeric_scale',
    'collation',
    'comment',
]);

foreach ($targetTables as $table) {
    foreach ($columnsByTable[$table] ?? [] as $column) {
        $writeColumn([
            $table,
            $column->column_name,
            $column->ordinal_position,
            $column->data_type,
            $column->column_type,
            $column->is_nullable,
            $column->column_default,
            $column->column_key,
            $column->extra,
            $column->character_maximum_length,
            $column->numeric_precision,
            $column->numeric_scale,
            $column->collation_name,
            $column->column_comment,
        ]);
    }
}
fclose($columnHandle);

$indexRows = DB::select(
    <<<'SQL'
SELECT
    TABLE_NAME AS table_name,
    INDEX_NAME AS index_name,
    NON_UNIQUE AS non_unique,
    SEQ_IN_INDEX AS sequence_in_index,
    COLUMN_NAME AS column_name,
    COLLATION AS collation,
    CARDINALITY AS cardinality,
    SUB_PART AS sub_part,
    INDEX_TYPE AS index_type,
    INDEX_COMMENT AS index_comment
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = ?
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
SQL,
    [$database],
);

[$indexHandle, $writeIndex] = $openTsv('04_indexes.tsv', [
    'table',
    'index',
    'non_unique',
    'sequence',
    'column',
    'collation',
    'cardinality',
    'sub_part',
    'index_type',
    'comment',
]);

$primaryKeysByTable = [];

foreach ($indexRows as $row) {
    $table = (string) $row->table_name;

    if (! in_array($table, $targetTables, true)) {
        continue;
    }

    $writeIndex([
        $table,
        $row->index_name,
        $row->non_unique,
        $row->sequence_in_index,
        $row->column_name,
        $row->collation,
        $row->cardinality,
        $row->sub_part,
        $row->index_type,
        $row->index_comment,
    ]);

    if ((string) $row->index_name === 'PRIMARY') {
        $primaryKeysByTable[$table][(int) $row->sequence_in_index] = (string) $row->column_name;
    }
}
fclose($indexHandle);

foreach ($primaryKeysByTable as &$primaryKeys) {
    ksort($primaryKeys);
    $primaryKeys = array_values($primaryKeys);
}
unset($primaryKeys);

$foreignKeyRows = DB::select(
    <<<'SQL'
SELECT
    kcu.TABLE_NAME AS table_name,
    kcu.CONSTRAINT_NAME AS constraint_name,
    kcu.COLUMN_NAME AS column_name,
    kcu.REFERENCED_TABLE_NAME AS referenced_table_name,
    kcu.REFERENCED_COLUMN_NAME AS referenced_column_name,
    rc.UPDATE_RULE AS update_rule,
    rc.DELETE_RULE AS delete_rule
FROM information_schema.KEY_COLUMN_USAGE kcu
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
    ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
   AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
   AND rc.TABLE_NAME = kcu.TABLE_NAME
WHERE kcu.CONSTRAINT_SCHEMA = ?
  AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
ORDER BY kcu.TABLE_NAME, kcu.CONSTRAINT_NAME, kcu.ORDINAL_POSITION
SQL,
    [$database],
);

[$foreignHandle, $writeForeign] = $openTsv('05_foreign_keys.tsv', [
    'table',
    'constraint',
    'column',
    'referenced_table',
    'referenced_column',
    'update_rule',
    'delete_rule',
]);

foreach ($foreignKeyRows as $row) {
    if (! in_array((string) $row->table_name, $targetTables, true)) {
        continue;
    }

    $writeForeign([
        $row->table_name,
        $row->constraint_name,
        $row->column_name,
        $row->referenced_table_name,
        $row->referenced_column_name,
        $row->update_rule,
        $row->delete_rule,
    ]);
}
fclose($foreignHandle);

$migrationRows = [];
$migrationDirectory = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

foreach (glob($migrationDirectory.DIRECTORY_SEPARATOR.'*.php') ?: [] as $migrationFile) {
    $source = file_get_contents($migrationFile);

    if (! is_string($source) || $source === '') {
        continue;
    }

    $tables = [];

    if (preg_match_all(
        '/Schema\s*::\s*(?:connection\s*\([^;]*?\)\s*->\s*)?(?:create|table|drop|dropIfExists)\s*\(\s*([\'"])([A-Za-z0-9_]+)\1/s',
        $source,
        $matches,
        PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $tables[$match[2]] = true;
        }
    }

    if (preg_match_all(
        '/Schema\s*::\s*(?:connection\s*\([^;]*?\)\s*->\s*)?rename\s*\(\s*([\'"])([A-Za-z0-9_]+)\1\s*,\s*([\'"])([A-Za-z0-9_]+)\3/s',
        $source,
        $matches,
        PREG_SET_ORDER,
    )) {
        foreach ($matches as $match) {
            $tables[$match[2]] = true;
            $tables[$match[4]] = true;
        }
    }

    foreach (array_keys($tables) as $table) {
        if (! in_array($table, $targetTables, true)) {
            continue;
        }

        $migrationRows[] = [
            $table,
            str_replace('\\', '/', substr($migrationFile, strlen($root) + 1)),
        ];
    }
}

[$migrationHandle, $writeMigration] = $openTsv('02_migration_table_map.tsv', [
    'table',
    'migration',
]);

usort($migrationRows, static fn (array $a, array $b): int => $a <=> $b);

foreach ($migrationRows as $row) {
    $writeMigration($row);
}
fclose($migrationHandle);

$originForTable = static function (string $table) use (
    $modelRows,
    $directTableSources,
    $root,
    $prefixes,
): string {
    $origins = [];

    foreach ($modelRows as $modelRow) {
        if ($modelRow['table'] === $table) {
            $origins['model:'.$modelRow['module']] = true;
        }
    }

    foreach (array_keys($directTableSources[$table] ?? []) as $sourceFile) {
        $relative = str_replace('\\', '/', substr($sourceFile, strlen($root) + 1));
        $origins['runtime:'.$relative] = true;
    }

    foreach ($prefixes as $prefix) {
        if (str_starts_with($table, $prefix)) {
            $origins['module_prefix'] = true;
        }
    }

    return implode(';', array_keys($origins));
};

[$inventoryHandle, $writeInventory] = $openTsv('06_table_inventory.tsv', [
    'table',
    'origin',
    'engine',
    'collation',
    'approximate_rows',
    'exact_rows',
    'average_row_bytes',
    'data_bytes',
    'index_bytes',
    'total_bytes',
    'data_free_bytes',
]);

foreach ($targetTables as $table) {
    try {
        $info = $allTables[$table];
        $exactRows = DB::table($table)->count();

        $writeInventory([
            $table,
            $originForTable($table),
            $info->engine,
            $info->table_collation,
            $info->approximate_rows,
            $exactRows,
            $info->average_row_length,
            $info->data_length,
            $info->index_length,
            (int) $info->data_length + (int) $info->index_length,
            $info->data_free,
        ]);
    } catch (Throwable $error) {
        $recordError('table_inventory', $table, null, $error);
    }
}
fclose($inventoryHandle);

$largeDataTypes = [
    'json',
    'tinytext',
    'text',
    'mediumtext',
    'longtext',
    'tinyblob',
    'blob',
    'mediumblob',
    'longblob',
];

$isLargeColumn = static function (object $column) use ($largeDataTypes): bool {
    $dataType = strtolower((string) $column->data_type);

    if (in_array($dataType, $largeDataTypes, true)) {
        return true;
    }

    return in_array($dataType, ['varchar', 'char'], true)
        && (int) ($column->character_maximum_length ?? 0) >= 256;
};

$largeColumnsByTable = [];

foreach ($columnsByTable as $table => $columns) {
    foreach ($columns as $name => $column) {
        if ($isLargeColumn($column)) {
            $largeColumnsByTable[$table][$name] = $column;
        }
    }
}

[$profileHandle, $writeProfile] = $openTsv('07_large_column_profile.tsv', [
    'table',
    'column',
    'data_type',
    'row_count',
    'null_rows',
    'empty_rows',
    'non_empty_rows',
    'total_bytes',
    'average_non_null_bytes',
    'maximum_bytes',
    'distinct_hashes',
]);

foreach ($largeColumnsByTable as $table => $columns) {
    foreach ($columns as $columnName => $column) {
        try {
            $quotedTable = $quoteIdentifier($table);
            $quotedColumn = $quoteIdentifier($columnName);

            $row = DB::selectOne(
                <<<SQL
SELECT
    COUNT(*) AS row_count,
    SUM(CASE WHEN {$quotedColumn} IS NULL THEN 1 ELSE 0 END) AS null_rows,
    SUM(CASE WHEN {$quotedColumn} IS NOT NULL AND OCTET_LENGTH({$quotedColumn}) = 0 THEN 1 ELSE 0 END) AS empty_rows,
    SUM(CASE WHEN {$quotedColumn} IS NOT NULL AND OCTET_LENGTH({$quotedColumn}) > 0 THEN 1 ELSE 0 END) AS non_empty_rows,
    SUM(COALESCE(OCTET_LENGTH({$quotedColumn}), 0)) AS total_bytes,
    AVG(CASE WHEN {$quotedColumn} IS NOT NULL THEN OCTET_LENGTH({$quotedColumn}) END) AS average_non_null_bytes,
    MAX(OCTET_LENGTH({$quotedColumn})) AS maximum_bytes,
    COUNT(DISTINCT CASE
        WHEN {$quotedColumn} IS NOT NULL
        THEN SHA2(CAST({$quotedColumn} AS CHAR), 256)
        ELSE NULL
    END) AS distinct_hashes
FROM {$quotedTable}
SQL
            );

            $writeProfile([
                $table,
                $columnName,
                $column->data_type,
                $row->row_count ?? 0,
                $row->null_rows ?? 0,
                $row->empty_rows ?? 0,
                $row->non_empty_rows ?? 0,
                $row->total_bytes ?? 0,
                $row->average_non_null_bytes ?? null,
                $row->maximum_bytes ?? null,
                $row->distinct_hashes ?? 0,
            ]);
        } catch (Throwable $error) {
            $recordError('large_column_profile', $table, $columnName, $error);
        }
    }
}
fclose($profileHandle);

$safeDimensionNames = [
    'id',
    'status',
    'type',
    'channel',
    'purpose',
    'scope',
    'queue',
    'message_type',
    'dispatch_key',
    'definition_config_path',
    'payload_class',
    'context_type',
    'recipient_type',
    'behavior_owner_type',
    'source_type',
    'subject_type',
    'source',
    'provider',
    'platform',
    'provider_event_type',
    'event_key',
    'surface',
    'created_at',
    'updated_at',
    'send_at',
    'sent_at',
    'skipped_at',
    'failed_at',
    'registered_at',
    'attended_at',
    'cancelled_at',
    'starts_at',
    'ends_at',
    'expires_at',
    'sort_order',
    'is_active',
];

$safeColumnsForTable = static function (string $table) use (
    $columnsByTable,
    $safeDimensionNames,
): array {
    $safe = [];

    foreach (array_keys($columnsByTable[$table] ?? []) as $columnName) {
        if (
            in_array($columnName, $safeDimensionNames, true)
            || str_ends_with($columnName, '_id')
        ) {
            $safe[] = $columnName;
        }
    }

    return $safe;
};

$rowIdentity = static function (object $row, array $primaryKeys): string {
    if ($primaryKeys === []) {
        return '';
    }

    $parts = [];

    foreach ($primaryKeys as $column) {
        $parts[] = $column.'='.(string) ($row->{$column} ?? '');
    }

    return implode(',', $parts);
};

$rowStorageSummary = static function (
    object $row,
    array $largeColumns,
): array {
    $columns = [];
    $total = 0;

    foreach (array_keys($largeColumns) as $column) {
        $bytesField = $column.'__bytes';
        $hashField = $column.'__sha256';
        $bytes = (int) ($row->{$bytesField} ?? 0);
        $hash = $row->{$hashField} ?? null;

        $columns[$column] = [
            'bytes' => $bytes,
            'sha256' => is_string($hash) && $hash !== '' ? $hash : null,
        ];

        $total += $bytes;
    }

    return [$total, $columns];
};

$selectStorageRows = static function (
    string $table,
    array $largeColumns,
    array $safeColumns,
    array $primaryKeys,
    ?string $orderColumn,
    int $limit,
) use ($quoteIdentifier): array {
    $quotedTable = $quoteIdentifier($table);
    $select = [];

    foreach (array_values(array_unique(array_merge($primaryKeys, $safeColumns))) as $column) {
        $select[] = $quoteIdentifier($column);
    }

    foreach (array_keys($largeColumns) as $column) {
        $quoted = $quoteIdentifier($column);
        $select[] = "OCTET_LENGTH({$quoted}) AS ".$quoteIdentifier($column.'__bytes');
        $select[] = "SHA2(CAST({$quoted} AS CHAR), 256) AS ".$quoteIdentifier($column.'__sha256');
    }

    if ($select === []) {
        return [];
    }

    $orderSql = $orderColumn !== null
        ? ' ORDER BY '.$quoteIdentifier($orderColumn).' DESC'
        : '';

    $sql = 'SELECT '.implode(', ', $select)
        .' FROM '.$quotedTable
        .$orderSql
        .' LIMIT '.max(1, $limit);

    return DB::select($sql);
};

[$largestHandle, $writeLargest] = $openTsv('08_largest_rows.tsv', [
    'table',
    'row_identity',
    'safe_dimensions',
    'variable_width_bytes',
    'column_storage',
]);

[$recentHandle, $writeRecent] = $openTsv('11_recent_rows.tsv', [
    'table',
    'row_identity',
    'safe_dimensions',
    'variable_width_bytes',
    'column_storage',
]);

foreach ($targetTables as $table) {
    $largeColumns = $largeColumnsByTable[$table] ?? [];

    if ($largeColumns === []) {
        continue;
    }

    $primaryKeys = $primaryKeysByTable[$table] ?? [];
    $safeColumns = $safeColumnsForTable($table);
    $available = array_keys($columnsByTable[$table] ?? []);

    $rowByteParts = [];

    foreach (array_keys($largeColumns) as $column) {
        $rowByteParts[] = 'COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0)';
    }

    try {
        $quotedTable = $quoteIdentifier($table);
        $select = [];

        foreach (array_values(array_unique(array_merge($primaryKeys, $safeColumns))) as $column) {
            $select[] = $quoteIdentifier($column);
        }

        foreach (array_keys($largeColumns) as $column) {
            $quoted = $quoteIdentifier($column);
            $select[] = "OCTET_LENGTH({$quoted}) AS ".$quoteIdentifier($column.'__bytes');
            $select[] = "SHA2(CAST({$quoted} AS CHAR), 256) AS ".$quoteIdentifier($column.'__sha256');
        }

        $select[] = '('.implode(' + ', $rowByteParts).') AS variable_width_bytes';

        $rows = DB::select(
            'SELECT '.implode(', ', $select)
            .' FROM '.$quotedTable
            .' ORDER BY variable_width_bytes DESC'
            .' LIMIT '.$rowLimit
        );

        foreach ($rows as $row) {
            $dimensions = [];

            foreach ($safeColumns as $column) {
                $dimensions[$column] = $row->{$column} ?? null;
            }

            [, $columnStorage] = $rowStorageSummary($row, $largeColumns);

            $writeLargest([
                $table,
                $rowIdentity($row, $primaryKeys),
                $dimensions,
                $row->variable_width_bytes ?? 0,
                $columnStorage,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('largest_rows', $table, null, $error);
    }

    $orderColumn = null;

    foreach (['created_at', 'updated_at'] as $candidate) {
        if (in_array($candidate, $available, true)) {
            $orderColumn = $candidate;
            break;
        }
    }

    if ($orderColumn === null && $primaryKeys !== []) {
        $orderColumn = $primaryKeys[0];
    }

    try {
        $rows = $selectStorageRows(
            $table,
            $largeColumns,
            $safeColumns,
            $primaryKeys,
            $orderColumn,
            $rowLimit,
        );

        foreach ($rows as $row) {
            $dimensions = [];

            foreach ($safeColumns as $column) {
                $dimensions[$column] = $row->{$column} ?? null;
            }

            [$totalBytes, $columnStorage] = $rowStorageSummary($row, $largeColumns);

            $writeRecent([
                $table,
                $rowIdentity($row, $primaryKeys),
                $dimensions,
                $totalBytes,
                $columnStorage,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('recent_rows', $table, null, $error);
    }
}

fclose($largestHandle);
fclose($recentHandle);

[$duplicateHandle, $writeDuplicate] = $openTsv('09_duplicate_values.tsv', [
    'table',
    'column',
    'sha256',
    'bytes_per_value',
    'occurrences',
    'duplicate_bytes',
]);

foreach ($largeColumnsByTable as $table => $columns) {
    foreach (array_keys($columns) as $columnName) {
        try {
            $quotedTable = $quoteIdentifier($table);
            $quotedColumn = $quoteIdentifier($columnName);

            $rows = DB::select(
                <<<SQL
SELECT
    SHA2(CAST({$quotedColumn} AS CHAR), 256) AS value_hash,
    MAX(OCTET_LENGTH({$quotedColumn})) AS value_bytes,
    COUNT(*) AS occurrences,
    (COUNT(*) - 1) * MAX(OCTET_LENGTH({$quotedColumn})) AS duplicate_bytes
FROM {$quotedTable}
WHERE {$quotedColumn} IS NOT NULL
  AND OCTET_LENGTH({$quotedColumn}) > 0
GROUP BY
    SHA2(CAST({$quotedColumn} AS CHAR), 256)
HAVING COUNT(*) > 1
ORDER BY duplicate_bytes DESC, occurrences DESC
LIMIT {$duplicateLimit}
SQL
            );

            foreach ($rows as $row) {
                $writeDuplicate([
                    $table,
                    $columnName,
                    $row->value_hash,
                    $row->value_bytes,
                    $row->occurrences,
                    $row->duplicate_bytes,
                ]);
            }
        } catch (Throwable $error) {
            $recordError('duplicate_values', $table, $columnName, $error);
        }
    }
}
fclose($duplicateHandle);

$collectJsonPaths = static function (
    mixed $value,
    string $path,
    int $depth,
    array &$counts,
    array &$types,
) use (&$collectJsonPaths): void {
    if ($depth > 4) {
        return;
    }

    if (is_array($value)) {
        $isList = array_is_list($value);

        foreach ($value as $key => $child) {
            $segment = $isList ? '[]' : (string) $key;
            $childPath = $path === '$'
                ? '$.'.$segment
                : $path.'.'.$segment;

            $counts[$childPath] = ($counts[$childPath] ?? 0) + 1;
            $type = get_debug_type($child);
            $types[$childPath][$type] = ($types[$childPath][$type] ?? 0) + 1;

            $collectJsonPaths($child, $childPath, $depth + 1, $counts, $types);
        }
    }
};

[$jsonHandle, $writeJson] = $openTsv('10_json_paths.tsv', [
    'table',
    'column',
    'json_path',
    'occurrences',
    'value_types',
]);

foreach ($columnsByTable as $table => $columns) {
    foreach ($columns as $columnName => $column) {
        if (strtolower((string) $column->data_type) !== 'json') {
            continue;
        }

        try {
            $counts = [];
            $types = [];

            $primaryKey = $primaryKeysByTable[$table][0] ?? null;
            $selectedColumns = array_values(array_unique(array_filter([
                $primaryKey,
                $columnName,
            ])));

            $query = DB::table($table)
                ->whereNotNull($columnName)
                ->select($selectedColumns);

            $rows = $primaryKey !== null
                ? $query->orderBy($primaryKey)->lazyById(250, $primaryKey)
                : $query->orderBy($columnName)->cursor();

            $rows->each(function (object $row) use (
                $columnName,
                &$counts,
                &$types,
                $collectJsonPaths,
            ): void {
                $raw = $row->{$columnName} ?? null;

                if (is_array($raw)) {
                    $decoded = $raw;
                } elseif (is_string($raw)) {
                    $decoded = json_decode($raw, true);
                } else {
                    $decoded = null;
                }

                if (! is_array($decoded)) {
                    return;
                }

                $collectJsonPaths($decoded, '$', 0, $counts, $types);
            });

            arsort($counts);
            $written = 0;

            foreach ($counts as $path => $count) {
                $writeJson([
                    $table,
                    $columnName,
                    $path,
                    $count,
                    $types[$path] ?? [],
                ]);

                $written++;

                if ($written >= 500) {
                    break;
                }
            }
        } catch (Throwable $error) {
            $recordError('json_paths', $table, $columnName, $error);
        }
    }
}
fclose($jsonHandle);

[$monthlyHandle, $writeMonthly] = $openTsv('12_monthly_growth.tsv', [
    'table',
    'month',
    'rows',
    'variable_width_bytes',
]);

foreach ($targetTables as $table) {
    if (! isset($columnsByTable[$table]['created_at'])) {
        continue;
    }

    try {
        $quotedTable = $quoteIdentifier($table);
        $byteParts = [];

        foreach (array_keys($largeColumnsByTable[$table] ?? []) as $column) {
            $byteParts[] = 'COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0)';
        }

        $byteExpression = $byteParts === [] ? '0' : implode(' + ', $byteParts);

        $rows = DB::select(
            <<<SQL
SELECT
    DATE_FORMAT(`created_at`, '%Y-%m') AS row_month,
    COUNT(*) AS row_count,
    SUM({$byteExpression}) AS variable_width_bytes
FROM {$quotedTable}
GROUP BY DATE_FORMAT(`created_at`, '%Y-%m')
ORDER BY row_month
SQL
        );

        foreach ($rows as $row) {
            $writeMonthly([
                $table,
                $row->row_month,
                $row->row_count,
                $row->variable_width_bytes,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('monthly_growth', $table, 'created_at', $error);
    }
}
fclose($monthlyHandle);

$searchTerms = array_values(array_unique(array_filter([
    strtolower($moduleName),
    strtolower($moduleKey),
    strtolower(Str::singular($moduleKey)),
    strtolower(str_replace('_', '-', $moduleKey)),
    strtolower(str_replace('_', ' ', $moduleKey)),
    strtolower('App\\Modules\\'.$moduleName),
    strtolower(Str::singular($moduleKey).'_registration'),
])));

$isSearchableClassifier = static function (string $columnName, object $column): bool {
    $type = strtolower((string) $column->data_type);

    if (! in_array($type, [
        'char',
        'varchar',
        'tinytext',
        'text',
        'mediumtext',
        'longtext',
        'json',
    ], true)) {
        return false;
    }

    return in_array($columnName, [
        'status',
        'type',
        'channel',
        'purpose',
        'scope',
        'queue',
        'message_type',
        'dispatch_key',
        'dispatch_keys',
        'definition_config_path',
        'payload_class',
        'context_type',
        'recipient_type',
        'behavior_owner_type',
        'source_type',
        'subject_type',
        'source',
        'provider',
        'platform',
        'provider_event_type',
        'event_key',
        'surface',
        'payload',
        'meta',
    ], true);
};

[$relatedHandle, $writeRelated] = $openTsv('13_module_related_rows.tsv', [
    'table',
    'row_identity',
    'safe_dimensions',
    'variable_width_bytes',
    'column_storage',
]);

foreach ($targetTables as $table) {
    $searchColumns = [];

    foreach ($columnsByTable[$table] ?? [] as $columnName => $column) {
        if ($isSearchableClassifier($columnName, $column)) {
            $searchColumns[] = $columnName;
        }
    }

    if ($searchColumns === []) {
        continue;
    }

    $predicates = [];
    $bindings = [];

    foreach ($searchColumns as $columnName) {
        $quotedColumn = $quoteIdentifier($columnName);

        foreach ($searchTerms as $term) {
            $predicates[] = "LOWER(CAST({$quotedColumn} AS CHAR)) LIKE ?";
            $bindings[] = '%'.$term.'%';
        }
    }

    $primaryKeys = $primaryKeysByTable[$table] ?? [];
    $safeColumns = $safeColumnsForTable($table);
    $largeColumns = $largeColumnsByTable[$table] ?? [];
    $select = [];

    foreach (array_values(array_unique(array_merge($primaryKeys, $safeColumns))) as $column) {
        $select[] = $quoteIdentifier($column);
    }

    $byteParts = [];

    foreach (array_keys($largeColumns) as $column) {
        $quoted = $quoteIdentifier($column);
        $select[] = "OCTET_LENGTH({$quoted}) AS ".$quoteIdentifier($column.'__bytes');
        $select[] = "SHA2(CAST({$quoted} AS CHAR), 256) AS ".$quoteIdentifier($column.'__sha256');
        $byteParts[] = "COALESCE(OCTET_LENGTH({$quoted}), 0)";
    }

    $select[] = ($byteParts === [] ? '0' : '('.implode(' + ', $byteParts).')')
        .' AS variable_width_bytes';

    try {
        $rows = DB::select(
            'SELECT '.implode(', ', $select)
            .' FROM '.$quoteIdentifier($table)
            .' WHERE '.implode(' OR ', $predicates)
            .' ORDER BY variable_width_bytes DESC'
            .' LIMIT '.$rowLimit,
            $bindings,
        );

        foreach ($rows as $row) {
            $dimensions = [];

            foreach ($safeColumns as $column) {
                $dimensions[$column] = $row->{$column} ?? null;
            }

            [, $columnStorage] = $rowStorageSummary($row, $largeColumns);

            $writeRelated([
                $table,
                $rowIdentity($row, $primaryKeys),
                $dimensions,
                $row->variable_width_bytes ?? 0,
                $columnStorage,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('module_related_rows', $table, null, $error);
    }
}
fclose($relatedHandle);

[$contextHandle, $writeContext] = $openTsv('14_polymorphic_context_summary.tsv', [
    'table',
    'context_type',
    'rows',
    'variable_width_bytes',
]);

foreach ($targetTables as $table) {
    if (
        ! isset($columnsByTable[$table]['context_type'])
        || ! isset($columnsByTable[$table]['context_id'])
    ) {
        continue;
    }

    try {
        $byteParts = [];

        foreach (array_keys($largeColumnsByTable[$table] ?? []) as $column) {
            $byteParts[] = 'COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0)';
        }

        $byteExpression = $byteParts === [] ? '0' : implode(' + ', $byteParts);

        $rows = DB::select(
            'SELECT `context_type`, COUNT(*) AS row_count,'
            .' SUM('.$byteExpression.') AS variable_width_bytes'
            .' FROM '.$quoteIdentifier($table)
            .' GROUP BY `context_type`'
            .' ORDER BY variable_width_bytes DESC, row_count DESC'
        );

        foreach ($rows as $row) {
            $writeContext([
                $table,
                $row->context_type,
                $row->row_count,
                $row->variable_width_bytes,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('polymorphic_context_summary', $table, null, $error);
    }
}
fclose($contextHandle);

[$messageHandle, $writeMessage] = $openTsv('15_message_storage_summary.tsv', [
    'channel',
    'purpose',
    'scope',
    'message_type',
    'status',
    'queue',
    'context_type',
    'rows',
    'payload_bytes',
    'meta_bytes',
    'dispatch_key_bytes',
    'failure_reason_bytes',
    'skip_reason_bytes',
    'total_variable_bytes',
]);

if (
    isset($allTables['scheduled_messages'])
    && isset($columnsByTable['scheduled_messages'])
) {
    try {
        $messageColumns = $columnsByTable['scheduled_messages'];
        $has = static fn (string $column): bool => isset($messageColumns[$column]);

        $sum = static fn (string $column): string => $has($column)
            ? 'SUM(COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0))'
            : '0';

        $modulePredicates = [];
        $moduleBindings = [];

        foreach ([
            'scope',
            'message_type',
            'definition_config_path',
            'context_type',
            'payload_class',
            'meta',
        ] as $column) {
            if (! $has($column)) {
                continue;
            }

            foreach ($searchTerms as $term) {
                $modulePredicates[] = 'LOWER(CAST('.$quoteIdentifier($column).' AS CHAR)) LIKE ?';
                $moduleBindings[] = '%'.$term.'%';
            }
        }

        $groupColumns = [
            'channel',
            'purpose',
            'scope',
            'message_type',
            'status',
            'queue',
            'context_type',
        ];

        $groupSelect = [];

        foreach ($groupColumns as $column) {
            $groupSelect[] = $has($column)
                ? $quoteIdentifier($column)
                : 'NULL AS '.$quoteIdentifier($column);
        }

        $payloadBytes = $sum('payload');
        $metaBytes = $sum('meta');
        $dispatchBytes = $sum('dispatch_keys');
        $failureBytes = $sum('failure_reason');
        $skipBytes = $sum('skip_reason');

        $where = $modulePredicates === []
            ? '1 = 0'
            : '('.implode(' OR ', $modulePredicates).')';

        $groupBy = implode(', ', array_map(
            static fn (string $column): string => $quoteIdentifier($column),
            array_values(array_filter($groupColumns, $has)),
        ));

        $rows = DB::select(
            'SELECT '.implode(', ', $groupSelect).','
            .' COUNT(*) AS row_count,'
            ." {$payloadBytes} AS payload_bytes,"
            ." {$metaBytes} AS meta_bytes,"
            ." {$dispatchBytes} AS dispatch_key_bytes,"
            ." {$failureBytes} AS failure_reason_bytes,"
            ." {$skipBytes} AS skip_reason_bytes,"
            ." ({$payloadBytes} + {$metaBytes} + {$dispatchBytes} + {$failureBytes} + {$skipBytes})"
            .' AS total_variable_bytes'
            .' FROM `scheduled_messages`'
            .' WHERE '.$where
            .($groupBy !== '' ? ' GROUP BY '.$groupBy : '')
            .' ORDER BY total_variable_bytes DESC, row_count DESC',
            $moduleBindings,
        );

        foreach ($rows as $row) {
            $writeMessage([
                $row->channel ?? null,
                $row->purpose ?? null,
                $row->scope ?? null,
                $row->message_type ?? null,
                $row->status ?? null,
                $row->queue ?? null,
                $row->context_type ?? null,
                $row->row_count ?? 0,
                $row->payload_bytes ?? 0,
                $row->meta_bytes ?? 0,
                $row->dispatch_key_bytes ?? 0,
                $row->failure_reason_bytes ?? 0,
                $row->skip_reason_bytes ?? 0,
                $row->total_variable_bytes ?? 0,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('message_storage_summary', 'scheduled_messages', null, $error);
    }
}
fclose($messageHandle);

[$webinarHandle, $writeWebinar] = $openTsv('16_webinar_registration_storage.tsv', [
    'registration_id',
    'contact_id',
    'webinar_id',
    'replacement_of_registration_id',
    'status',
    'source',
    'registered_at',
    'created_at',
    'registration_meta_bytes',
    'registration_meta_sha256',
    'response_count',
    'response_bytes',
    'scheduled_message_count',
    'scheduled_message_payload_bytes',
    'scheduled_message_meta_bytes',
    'scheduled_message_other_bytes',
    'delivery_attempt_count',
    'delivery_attempt_bytes',
    'outbox_event_count',
    'outbox_event_bytes',
    'total_related_variable_bytes',
]);

if (
    $moduleKey === 'webinars'
    && isset($allTables['webinar_registrations'])
    && isset($columnsByTable['webinar_registrations'])
) {
    try {
        $registrationColumns = $columnsByTable['webinar_registrations'];
        $registrations = DB::table('webinar_registrations')
            ->select(array_values(array_filter([
                isset($registrationColumns['id']) ? 'id' : null,
                isset($registrationColumns['contact_id']) ? 'contact_id' : null,
                isset($registrationColumns['webinar_id']) ? 'webinar_id' : null,
                isset($registrationColumns['replacement_of_registration_id'])
                    ? 'replacement_of_registration_id'
                    : null,
                isset($registrationColumns['status']) ? 'status' : null,
                isset($registrationColumns['source']) ? 'source' : null,
                isset($registrationColumns['registered_at']) ? 'registered_at' : null,
                isset($registrationColumns['created_at']) ? 'created_at' : null,
                isset($registrationColumns['meta']) ? 'meta' : null,
            ])))
            ->orderByDesc('id')
            ->limit(max($rowLimit, 100))
            ->get();

        $attemptTable = isset($allTables['scheduled_message_delivery_attempts'])
            ? 'scheduled_message_delivery_attempts'
            : null;
        $outboxTable = isset($allTables['scheduled_message_outbox_events'])
            ? 'scheduled_message_outbox_events'
            : null;

        $sumVariableBytes = static function (
            string $table,
            array $ids,
            string $foreignKey,
        ) use (
            $columnsByTable,
            $largeColumnsByTable,
            $quoteIdentifier,
        ): array {
            if ($ids === [] || ! isset($columnsByTable[$table][$foreignKey])) {
                return [0, 0];
            }

            $byteParts = [];

            foreach (array_keys($largeColumnsByTable[$table] ?? []) as $column) {
                $byteParts[] = 'COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0)';
            }

            $byteExpression = $byteParts === [] ? '0' : implode(' + ', $byteParts);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            $row = DB::selectOne(
                'SELECT COUNT(*) AS row_count,'
                .' SUM('.$byteExpression.') AS total_bytes'
                .' FROM '.$quoteIdentifier($table)
                .' WHERE '.$quoteIdentifier($foreignKey).' IN ('.$placeholders.')',
                $ids,
            );

            return [
                (int) ($row->row_count ?? 0),
                (int) ($row->total_bytes ?? 0),
            ];
        };

        foreach ($registrations as $registration) {
            $registrationId = (int) ($registration->id ?? 0);
            $metaRaw = $registration->meta ?? null;
            $metaString = is_string($metaRaw)
                ? $metaRaw
                : json_encode($metaRaw, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $metaString = is_string($metaString) ? $metaString : '';

            $responseCount = 0;
            $responseBytes = 0;

            if (
                isset($allTables['webinar_registration_responses'])
                && isset($columnsByTable['webinar_registration_responses']['webinar_registration_id'])
            ) {
                $byteParts = [];

                foreach (array_keys($largeColumnsByTable['webinar_registration_responses'] ?? []) as $column) {
                    $byteParts[] = 'COALESCE(OCTET_LENGTH('.$quoteIdentifier($column).'), 0)';
                }

                $byteExpression = $byteParts === [] ? '0' : implode(' + ', $byteParts);
                $row = DB::selectOne(
                    'SELECT COUNT(*) AS row_count,'
                    .' SUM('.$byteExpression.') AS total_bytes'
                    .' FROM `webinar_registration_responses`'
                    .' WHERE `webinar_registration_id` = ?',
                    [$registrationId],
                );

                $responseCount = (int) ($row->row_count ?? 0);
                $responseBytes = (int) ($row->total_bytes ?? 0);
            }

            $messageIds = [];
            $messageCount = 0;
            $messagePayloadBytes = 0;
            $messageMetaBytes = 0;
            $messageOtherBytes = 0;

            if (isset($allTables['scheduled_messages'])) {
                $messages = DB::table('scheduled_messages')
                    ->where(function ($query) use ($registrationId): void {
                        $query
                            ->where(function ($context) use ($registrationId): void {
                                $context
                                    ->where('context_id', $registrationId)
                                    ->whereRaw('LOWER(CAST(`context_type` AS CHAR)) LIKE ?', ['%webinar%']);
                            })
                            ->orWhereRaw(
                                'JSON_UNQUOTE(JSON_EXTRACT(`meta`, "$.webinar_registration_id")) = ?',
                                [(string) $registrationId],
                            );
                    })
                    ->select(array_values(array_filter([
                        'id',
                        isset($columnsByTable['scheduled_messages']['payload']) ? 'payload' : null,
                        isset($columnsByTable['scheduled_messages']['meta']) ? 'meta' : null,
                        isset($columnsByTable['scheduled_messages']['dispatch_keys']) ? 'dispatch_keys' : null,
                        isset($columnsByTable['scheduled_messages']['failure_reason']) ? 'failure_reason' : null,
                        isset($columnsByTable['scheduled_messages']['skip_reason']) ? 'skip_reason' : null,
                    ])))
                    ->get();

                foreach ($messages as $message) {
                    $messageIds[] = (int) $message->id;
                    $messageCount++;
                    $messagePayloadBytes += strlen((string) ($message->payload ?? ''));
                    $messageMetaBytes += strlen((string) ($message->meta ?? ''));
                    $messageOtherBytes += strlen((string) ($message->dispatch_keys ?? ''));
                    $messageOtherBytes += strlen((string) ($message->failure_reason ?? ''));
                    $messageOtherBytes += strlen((string) ($message->skip_reason ?? ''));
                }
            }

            [$attemptCount, $attemptBytes] = $attemptTable !== null
                ? $sumVariableBytes($attemptTable, $messageIds, 'scheduled_message_id')
                : [0, 0];

            [$outboxCount, $outboxBytes] = $outboxTable !== null
                ? $sumVariableBytes($outboxTable, $messageIds, 'scheduled_message_id')
                : [0, 0];

            $registrationMetaBytes = strlen($metaString);
            $total = $registrationMetaBytes
                + $responseBytes
                + $messagePayloadBytes
                + $messageMetaBytes
                + $messageOtherBytes
                + $attemptBytes
                + $outboxBytes;

            $writeWebinar([
                $registrationId,
                $registration->contact_id ?? null,
                $registration->webinar_id ?? null,
                $registration->replacement_of_registration_id ?? null,
                $registration->status ?? null,
                $registration->source ?? null,
                $registration->registered_at ?? null,
                $registration->created_at ?? null,
                $registrationMetaBytes,
                $metaString !== '' ? hash('sha256', $metaString) : null,
                $responseCount,
                $responseBytes,
                $messageCount,
                $messagePayloadBytes,
                $messageMetaBytes,
                $messageOtherBytes,
                $attemptCount,
                $attemptBytes,
                $outboxCount,
                $outboxBytes,
                $total,
            ]);
        }
    } catch (Throwable $error) {
        $recordError('webinar_registration_storage', 'webinar_registrations', null, $error);
    }
}
fclose($webinarHandle);

$summary = [
    'Engage Core Database-Bloat Audit',
    '=================================',
    '',
    'Module: '.$moduleName,
    'Module key: '.$moduleKey,
    'Generated: '.$generatedAt,
    'Database: '.$database,
    'Driver: '.$driver,
    'Allowed module closure: '.implode(', ', $allowedModuleDirs),
    'Resolved runtime files: '.count($processedFiles),
    'Resolved model classes: '.count($modelRows),
    'Audited tables: '.count($targetTables),
    'Row sample limit: '.$rowLimit,
    'Duplicate-group limit per column: '.$duplicateLimit,
    '',
    'Audited tables:',
];

foreach ($targetTables as $table) {
    $summary[] = '  - '.$table;
}

$summary[] = '';
$summary[] = 'Privacy and safety:';
$summary[] = '  - read-only queries only';
$summary[] = '  - no raw payload, meta, body, text, token, URL, email, phone, IP, user-agent, or answer text is written';
$summary[] = '  - variable-width values are represented by byte length, SHA-256 hash, and structural JSON paths';
$summary[] = '  - identifiers and operational classifier columns may be included for correlation';
$summary[] = '';
$summary[] = 'Interpretation note:';
$summary[] = '  - information_schema row estimates may be approximate';
$summary[] = '  - exact row counts are included in 06_table_inventory.tsv';
$summary[] = '  - duplicate_bytes estimates removable repeated copies, not guaranteed reclaimable storage';

file_put_contents(
    $output.DIRECTORY_SEPARATOR.'00_summary.txt',
    implode(PHP_EOL, $summary).PHP_EOL,
);

fclose($errorHandle);

echo 'Database-bloat audit completed.'.PHP_EOL;
echo 'Module: '.$moduleName.PHP_EOL;
echo 'Database: '.$database.PHP_EOL;
echo 'Tables: '.count($targetTables).PHP_EOL;
echo 'Output: '.$output.PHP_EOL;
PHP

export ENGAGE_AUDIT_ROOT="$ROOT_DIR"
export ENGAGE_AUDIT_OUTPUT="$AUDIT_DIR"
export ENGAGE_AUDIT_MODULE_NAME="$MODULE_NAME"
export ENGAGE_AUDIT_MODULE_KEY="$MODULE_KEY"
export ENGAGE_AUDIT_ALLOWED_DIRS="$ALLOWED_MODULE_DIRS"
export ENGAGE_AUDIT_ROW_LIMIT="$ROW_LIMIT"
export ENGAGE_AUDIT_DUPLICATE_LIMIT="$DUPLICATE_LIMIT"
export ENGAGE_AUDIT_GENERATED_AT="$GENERATED_AT"

set +e
(
    cd "$ROOT_DIR"
    php "$RUNNER_FILE"
) > "$AUDIT_DIR/99_runner.log" 2>&1
RUNNER_STATUS=$?
set -e

if [[ "$RUNNER_STATUS" -ne 0 ]]; then
    echo "Error: database-bloat audit failed." >&2
    echo "PHP runner output:" >&2
    cat "$AUDIT_DIR/99_runner.log" >&2
    exit "$RUNNER_STATUS"
fi

SECTION_ERROR_COUNT="$(
    awk 'NR > 1 { count++ } END { print count + 0 }' "$AUDIT_DIR/98_errors.tsv"
)"

{
    echo "Engage Core Database-Bloat Audit Manifest"
    echo "========================================="
    echo
    echo "Module: $MODULE_NAME"
    echo "Module key: $MODULE_KEY"
    echo "Generated: $GENERATED_AT"
    echo "Repository root: $ROOT_DIR"
    echo "Output directory: $AUDIT_DIR"
    echo "Row sample limit: $ROW_LIMIT"
    echo "Duplicate-group limit per column: $DUPLICATE_LIMIT"
    echo "Recorded section errors: $SECTION_ERROR_COUNT"

    if [[ "$SECTION_ERROR_COUNT" -gt 0 ]]; then
        echo "Audit completeness: INCOMPLETE — inspect 98_errors.tsv"
    else
        echo "Audit completeness: COMPLETE"
    fi

    echo
    echo "Allowed module dependency closure:"
    while IFS= read -r allowed_dir; do
        printf '  - %s (%s)\n' "$allowed_dir" "${MODULE_KEY_BY_DIR[$allowed_dir]}"
    done < "$ALLOWED_MODULE_DIRS"
    echo
    echo "Read-only guarantee:"
    echo "  - the command executes SELECT and information_schema queries only"
    echo "  - it does not insert, update, delete, truncate, alter, or lock application rows"
    echo
    echo "Redaction guarantee:"
    echo "  - raw payload, meta, body, text, answer_text, email, phone, token, URL,"
    echo "    IP address, user-agent, and credential values are not written"
    echo "  - variable-width values are represented by lengths, hashes, and JSON paths"
    echo
    echo "Files:"
    find "$AUDIT_DIR" -maxdepth 1 -type f -printf '  - %f\n' | sort
} > "$AUDIT_DIR/00_manifest.txt"

{
    echo "Engage Core Combined Database-Bloat Audit"
    echo "========================================="
    echo
    echo "Source directory: $AUDIT_DIR"

    while IFS= read -r file; do
        echo
        echo "===== $(basename "$file") ====="
        echo

        if [[ -s "$file" ]]; then
            cat "$file"
            [[ "$(tail -c 1 "$file" 2>/dev/null || true)" == "" ]] || echo
        else
            echo "[EMPTY FILE]"
        fi
    done < <(
        find "$AUDIT_DIR" -maxdepth 1 -type f -print | sort
    )
} > "$COMBINED_FILE"

tar -czf "$ARCHIVE_FILE" -C "$OUTPUT_ROOT" "$AUDIT_BASENAME"

echo
echo "Created audit directory: $AUDIT_DIR"
echo "Created combined dump: $COMBINED_FILE"
echo "Created archive: $ARCHIVE_FILE"
echo "Allowed module closure: $(paste -sd ',' "$ALLOWED_MODULE_DIRS" | sed 's/,/, /g')"
echo "Row sample limit: $ROW_LIMIT"
echo "Recorded section errors: $SECTION_ERROR_COUNT"

if [[ "$SECTION_ERROR_COUNT" -gt 0 ]]; then
    echo "Warning: audit completed with section errors." >&2
    echo "Inspect: $AUDIT_DIR/98_errors.tsv" >&2
else
    echo "Audit completeness: complete"
fi

echo "Upload the combined dump for review:"
echo "  $COMBINED_FILE"
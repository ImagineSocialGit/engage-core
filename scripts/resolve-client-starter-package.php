<?php

declare(strict_types=1);

/**
 * Resolve a creation-time starter package without changing runtime package ownership.
 *
 * Usage:
 *   php scripts/resolve-client-starter-package.php PRESET_TEMPLATE_DIR STARTER_PACKAGES_FILE [STARTER_PACKAGE] [OUTPUT_MODULES_FILE]
 *
 * The preset template's config/modules.php remains the base module selection. An
 * optional creation-time starter-package manifest may select a different runtime
 * preset package and replace/compose the explicit module list.
 */

function fail(string $message): never
{
    fwrite(STDERR, $message."\n");
    exit(1);
}

/** @return array<int, string> */
function validateModuleList(mixed $modules, string $source): array
{
    if (! is_array($modules) || $modules === []) {
        fail("{$source} must contain a non-empty module list.");
    }

    $validated = [];

    foreach ($modules as $module) {
        if (! is_string($module)
            || preg_match('/^[a-z0-9][a-z0-9_]*$/', $module) !== 1
        ) {
            fail("{$source} contains an invalid module key.");
        }

        if (! in_array($module, $validated, true)) {
            $validated[] = $module;
        }
    }

    return $validated;
}

function phpString(string $value): string
{
    return var_export($value, true);
}

/** @param array<int, string> $modules */
function writeModulesFile(string $path, array $modules): void
{
    $directory = dirname($path);

    if (! is_dir($directory)) {
        fail("Output modules directory does not exist: {$directory}");
    }

    $lines = [
        '<?php',
        '',
        'return [',
        "    'enabled' => [",
    ];

    foreach ($modules as $module) {
        $lines[] = '        '.phpString($module).',';
    }

    $lines[] = '    ],';
    $lines[] = '];';
    $lines[] = '';

    if (file_put_contents($path, implode("\n", $lines), LOCK_EX) === false) {
        fail("Unable to write resolved modules file: {$path}");
    }
}

$presetTemplateDir = rtrim((string) ($argv[1] ?? ''), '/');
$starterPackagesFile = trim((string) ($argv[2] ?? ''));
$requestedPackage = trim((string) ($argv[3] ?? ''));
$outputModulesFile = trim((string) ($argv[4] ?? ''));

if ($presetTemplateDir === '' || ! is_dir($presetTemplateDir)) {
    fail('Preset template directory does not exist.');
}

$presetKey = basename($presetTemplateDir);

if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $presetKey) !== 1) {
    fail("Invalid preset key derived from template directory: {$presetKey}");
}

if ($requestedPackage !== ''
    && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $requestedPackage) !== 1
) {
    fail("Invalid starter package: {$requestedPackage}");
}

$modulesFile = $presetTemplateDir.'/config/modules.php';

if (! is_file($modulesFile)) {
    fail("Preset template is missing config/modules.php: {$presetTemplateDir}");
}

$baseConfig = require $modulesFile;
$baseModules = validateModuleList(
    is_array($baseConfig) ? ($baseConfig['enabled'] ?? null) : null,
    "Preset [{$presetKey}] config/modules.php",
);

$manifestFile = $starterPackagesFile;

if ($manifestFile === '' || ! is_file($manifestFile)) {
    if ($requestedPackage !== '') {
        fail("Preset [{$presetKey}] does not define starter packages; remove --starter-package.");
    }

    if ($outputModulesFile !== '') {
        writeModulesFile($outputModulesFile, $baseModules);
    }

    echo json_encode([
        'preset_family' => $presetKey,
        'starter_package' => null,
        'starter_package_name' => null,
        'runtime_preset' => $presetKey,
        'modules' => $baseModules,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

    exit(0);
}

$manifest = require $manifestFile;

if (! is_array($manifest)) {
    fail("Starter-package manifest must return an array: {$manifestFile}");
}

$packages = $manifest['packages'] ?? null;
$defaultPackage = $manifest['default'] ?? null;

if (! is_array($packages) || $packages === []) {
    fail("Starter-package manifest must define a non-empty packages map: {$manifestFile}");
}

if (! is_string($defaultPackage) || trim($defaultPackage) === '') {
    fail("Starter-package manifest must define a default package: {$manifestFile}");
}

$selectedPackage = $requestedPackage !== '' ? $requestedPackage : trim($defaultPackage);

if (! array_key_exists($selectedPackage, $packages)) {
    $available = implode(', ', array_keys($packages));
    fail("Unknown starter package [{$selectedPackage}] for preset [{$presetKey}]. Available: {$available}");
}

/**
 * @param array<int, string> $stack
 * @return array<int, string>
 */
$resolveModules = function (string $packageKey, array $stack = []) use (
    &$resolveModules,
    $packages,
    $baseModules,
    $presetKey,
): array {
    if (in_array($packageKey, $stack, true)) {
        $cycle = implode(' -> ', [...$stack, $packageKey]);
        fail("Starter-package include cycle for preset [{$presetKey}]: {$cycle}");
    }

    $definition = $packages[$packageKey] ?? null;

    if (! is_array($definition)) {
        fail("Starter package [{$packageKey}] for preset [{$presetKey}] must be an array.");
    }

    $modules = array_key_exists('modules', $definition)
        ? validateModuleList(
            $definition['modules'],
            "Starter package [{$packageKey}] modules",
        )
        : $baseModules;

    $includes = $definition['includes'] ?? [];

    if (! is_array($includes)) {
        fail("Starter package [{$packageKey}] includes must be a list.");
    }

    foreach ($includes as $includedPackage) {
        if (! is_string($includedPackage) || trim($includedPackage) === '') {
            fail("Starter package [{$packageKey}] contains an invalid include.");
        }

        $includedPackage = trim($includedPackage);

        if (! array_key_exists($includedPackage, $packages)) {
            fail("Starter package [{$packageKey}] includes unknown package [{$includedPackage}].");
        }

        foreach ($resolveModules($includedPackage, [...$stack, $packageKey]) as $module) {
            if (! in_array($module, $modules, true)) {
                $modules[] = $module;
            }
        }
    }

    return $modules;
};

$selectedDefinition = $packages[$selectedPackage];
$packageName = $selectedDefinition['name'] ?? null;
$runtimePreset = $selectedDefinition['preset'] ?? null;

if (! is_string($packageName)
    || trim($packageName) === ''
    || preg_match('/[\r\n]/', $packageName) === 1
) {
    fail("Starter package [{$selectedPackage}] must define a single-line name.");
}

if (! is_string($runtimePreset)
    || preg_match('/^[a-z0-9][a-z0-9_-]*$/', $runtimePreset) !== 1
) {
    fail("Starter package [{$selectedPackage}] must define a valid runtime preset key.");
}

$resolvedModules = $resolveModules($selectedPackage);

if ($outputModulesFile !== '') {
    writeModulesFile($outputModulesFile, $resolvedModules);
}

echo json_encode([
    'preset_family' => $presetKey,
    'starter_package' => $selectedPackage,
    'starter_package_name' => trim($packageName),
    'runtime_preset' => $runtimePreset,
    'modules' => $resolvedModules,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
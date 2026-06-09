<?php

declare(strict_types=1);

$coreEnvPath = base_path('.env');

if (! file_exists($coreEnvPath)) {
    fwrite(STDERR, "Core .env file not found.\n");
    exit(1);
}

$coreEnv = parse_env_file($coreEnvPath);
$clientKey = trim((string) ($coreEnv['CLIENT_KEY'] ?? ''));

if ($clientKey === '') {
    fwrite(STDERR, "CLIENT_KEY is not set in core .env.\n");
    exit(1);
}

if (! preg_match('/^[a-zA-Z0-9_-]+$/', $clientKey)) {
    fwrite(STDERR, "CLIENT_KEY contains invalid characters.\n");
    exit(1);
}

$manifestPath = base_path('resources/images/manifest.json');
$outputPath = base_path("client/{$clientKey}/config/generated/images.php");

if (! file_exists($manifestPath)) {
    fwrite(STDERR, "manifest.json not found.\n");
    exit(1);
}

$contents = file_get_contents($manifestPath);
$data = json_decode($contents, true);

if (! is_array($data)) {
    fwrite(STDERR, "manifest.json is invalid.\n");
    exit(1);
}

ksort($data);

$output = "<?php\n\nreturn ".var_export($data, true).";\n";

$dir = dirname($outputPath);

if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($outputPath, $output);

echo "Generated client/{$clientKey}/config/generated/images.php\n";

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path
        ? $base.DIRECTORY_SEPARATOR.ltrim($path, '/')
        : $base;
}

function parse_env_file(string $path): array
{
    $values = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return $values;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        $key = trim($key);
        $value = trim($value);

        if ($key === '') {
            continue;
        }

        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $values[$key] = $value;
    }

    return $values;
}
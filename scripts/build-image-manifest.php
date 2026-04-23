<?php

declare(strict_types=1);

$manifestPath = base_path('resources/images/manifest.json');
$outputPath = base_path('config/generated/images.php');

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

echo "Generated config/generated/images.php\n";

function base_path(string $path = ''): string
{
    $base = dirname(__DIR__);

    return $path
        ? $base.DIRECTORY_SEPARATOR.ltrim($path, '/')
        : $base;
}
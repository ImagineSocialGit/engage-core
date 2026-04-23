<?php

declare(strict_types=1);

if (! function_exists('cdn_image')) {
    function cdn_image(string $path, ?string $file = null): string
    {
        $base = rtrim((string) config('filesystems.disks.spaces.url', env('CDN_URL')), '/');
        $path = trim($path, '/');

        if ($file !== null) {
            $file = ltrim($file, '/');

            return "{$base}/images/{$path}/{$file}";
        }

        return "{$base}/images/{$path}";
    }
}
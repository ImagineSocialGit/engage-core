<?php

namespace App\Providers;

use FilesystemIterator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeClientConfig();
    }

    public function boot(): void
    {
        $views = config('client.views_path');

        if (is_string($views) && is_dir($views)) {
            View::prependLocation($views);
            View::prependNamespace('client', $views);
        }
    }

    private function mergeClientConfig(): void
    {
        $root = config('client.config_path');

        if (! is_string($root) || ! is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS,
            )
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo) {
                continue;
            }

            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            $key = str($path)
                ->after($root.DIRECTORY_SEPARATOR)
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '.')
                ->toString();

            $clientConfig = require $path;
            $current = config($key);

            Config::set(
                $key,
                is_array($current) && is_array($clientConfig)
                    ? $this->mergeConfigValues($current, $clientConfig)
                    : $clientConfig,
            );
        }
    }

    private function mergeConfigValues(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                $defaults[$key] = $value;

                continue;
            }

            $defaults[$key] = $this->shouldMergeRecursively($defaults[$key], $value)
                ? $this->mergeConfigValues($defaults[$key], $value)
                : $value;
        }

        return $defaults;
    }

    private function shouldMergeRecursively(mixed $default, mixed $override): bool
    {
        return is_array($default)
            && is_array($override)
            && $this->isAssociativeArray($default)
            && $this->isAssociativeArray($override);
    }

    private function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        foreach (array_keys($array) as $key) {
            if (is_string($key)) {
                return true;
            }
        }

        return false;
    }
}

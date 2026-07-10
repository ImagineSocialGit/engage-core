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
        $this->loadClientEnvironment();

        $preset = $this->selectedPreset();

        if ($preset !== null) {
            $this->mergePresetConfig($preset);
        }

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

    private function selectedPreset(): ?string
    {
        $explicit = config('client.preset');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $clientConfig = $this->loadClientConfigFile('client');

        if (! is_array($clientConfig)) {
            return null;
        }

        $preset = $clientConfig['preset'] ?? null;

        return is_string($preset) && $preset !== ''
            ? $preset
            : null;
    }

    private function mergePresetConfig(string $preset): void
    {
        $defaults = config("presets.presets.{$preset}");

        if (! is_array($defaults)) {
            return;
        }

        foreach ($defaults as $key => $presetConfig) {
            $current = config($key);

            Config::set(
                $key,
                is_array($current) && is_array($presetConfig)
                    ? $this->mergeConfigValues($current, $presetConfig)
                    : $presetConfig,
            );
        }

        Config::set('client.preset', $preset);
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

    private function loadClientConfigFile(string $key): mixed
    {
        $root = config('client.config_path');

        if (! is_string($root) || ! is_dir($root)) {
            return null;
        }

        $path = $root.DIRECTORY_SEPARATOR.str_replace('.', DIRECTORY_SEPARATOR, $key).'.php';

        if (! is_file($path)) {
            return null;
        }

        return require $path;
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

    private function loadClientEnvironment(): void
    {
        $path = config('client.env_path');

        if (! is_string($path) || ! file_exists($path)) {
            config()->set('client.env', []);

            return;
        }

        $values = [];

        foreach (
            file(
                filename: $path,
                flags: FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
            ) ?: [] as $line
        ) {
            $line = trim($line);

            if (
                $line === ''
                || str_starts_with($line, '#')
                || ! str_contains($line, '=')
            ) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);

            $values[trim($key)] = trim(
                $value,
                " \t\n\r\0\x0B\"'"
            );
        }

        config()->set('client.env', $values);
    }
}
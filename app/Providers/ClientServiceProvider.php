<?php

namespace App\Providers;

use App\Support\Clients\ClientPackageRuntime;
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
        $this->enableExplicitTestPackageModules();

        foreach (ClientPackageRuntime::manifest()->providers() as $provider) {
            $this->app->register($provider);
        }
    }

    public function boot(): void
    {
        $views = config('client.views_path');

        if (is_string($views) && is_dir($views)) {
            View::prependLocation($views);
            View::prependNamespace('client', $views);
        }
    }

    /**
     * The standalone package runner activates only modules declared by the
     * package under test. A normal test bootstrap has no package activation.
     * Never allow this test-only request outside the testing environment.
     */
    private function enableExplicitTestPackageModules(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        $requested = getenv('ENGAGE_PACKAGE_TEST_MODULES');

        if (! is_string($requested) || $requested === '') {
            return;
        }

        $keys = explode(',', $requested);
        $available = array_keys(ClientPackageRuntime::manifest()->moduleDefinitions());

        if (in_array('', $keys, true)
            || count($keys) !== count(array_unique($keys))
            || array_diff($keys, $available) !== []
        ) {
            throw new \RuntimeException('Standalone package test module selection is invalid.');
        }

        $enabled = config('modules.enabled', []);
        $enabled = is_array($enabled) ? $enabled : [];

        config()->set('modules.enabled', array_values(array_unique([
            ...$enabled,
            ...$keys,
        ])));
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

            // Staging authentication is root/runtime-owned, not a client
            // package override. Reject it even if this provider boots later.
            if ($key === 'staging' || str_starts_with($key, 'staging.')) {
                throw new \RuntimeException(
                    'Client configuration cannot override root-owned staging access.',
                );
            }

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
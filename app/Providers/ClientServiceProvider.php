<?php

namespace App\Providers;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->loadClientEnvironment();
        $this->mergeClientConfig();
    }

    public function boot(): void
    {
        $views = config('client.views_path');

        if (is_dir($views)) {
            View::prependNamespace('client', $views);
        }
    }

    private function mergeClientConfig(): void
    {
        $root = config('client.config_path');

        if (! is_dir($root)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            )
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();

            $key = str($path)
                ->after($root.DIRECTORY_SEPARATOR)
                ->beforeLast('.php')
                ->replace(DIRECTORY_SEPARATOR, '.')
                ->toString();

            Config::set(
                $key,
                array_replace_recursive(
                    config($key, []),
                    require $path,
                ),
            );
        }
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
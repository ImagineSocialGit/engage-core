<?php

namespace App\Support\Clients;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class ClientPackageManifestLoader
{
    public function load(string $basePath): ClientPackageManifest
    {
        $clientKey = $this->clientKey();

        if ($clientKey === null) {
            return ClientPackageManifest::empty();
        }

        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $clientKey) !== 1) {
            throw new RuntimeException(
                "CLIENT_KEY [{$clientKey}] contains invalid characters.",
            );
        }

        $clientDirectory = rtrim($basePath, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'client'
            .DIRECTORY_SEPARATOR.$clientKey;

        if (! is_dir($clientDirectory)) {
            return ClientPackageManifest::empty();
        }

        $autoloadPath = $clientDirectory
            .DIRECTORY_SEPARATOR.'vendor'
            .DIRECTORY_SEPARATOR.'autoload.php';

        if (is_file($autoloadPath)) {
            $this->assertNoFrameworkShadow($clientDirectory);

            if (! is_readable($autoloadPath)) {
                throw new RuntimeException(sprintf(
                    'Selected client Composer autoloader [%s] is not readable by the current process.',
                    $autoloadPath,
                ));
            }

            require_once $autoloadPath;
        }

        $manifestPath = $clientDirectory
            .DIRECTORY_SEPARATOR.'config'
            .DIRECTORY_SEPARATOR.'client_packages.php';

        if (! is_file($manifestPath)) {
            return ClientPackageManifest::empty();
        }

        if (! is_readable($manifestPath)) {
            throw new RuntimeException(sprintf(
                'Selected client package manifest [%s] is not readable by the current process.',
                $manifestPath,
            ));
        }

        $manifest = require $manifestPath;

        if (! is_array($manifest)) {
            throw new RuntimeException(sprintf(
                'Selected client package manifest [%s] must return an array.',
                $manifestPath,
            ));
        }

        $this->assertAllowedKeys(
            value: $manifest,
            allowed: ['providers', 'environment'],
            context: "Selected client package manifest [{$manifestPath}]",
        );

        $providers = $this->providers(
            value: $manifest['providers'] ?? [],
            manifestPath: $manifestPath,
            autoloadPath: $autoloadPath,
        );

        $environmentDefinitions = $this->environmentDefinitions(
            value: $manifest['environment'] ?? [],
            manifestPath: $manifestPath,
        );

        return new ClientPackageManifest(
            providers: $providers,
            environmentDefinitions: $environmentDefinitions,
        );
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    private function providers(
        mixed $value,
        string $manifestPath,
        string $autoloadPath,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(
                "Selected client package manifest [{$manifestPath}] providers must be a list.",
            );
        }

        if ($value !== [] && ! is_file($autoloadPath)) {
            throw new RuntimeException(sprintf(
                'Selected client package manifest [%s] declares providers but Composer dependencies are not installed at [%s].',
                $manifestPath,
                $autoloadPath,
            ));
        }

        $providers = [];

        foreach ($value as $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                throw new RuntimeException(
                    "Selected client package manifest [{$manifestPath}] contains an invalid provider class.",
                );
            }

            $provider = trim($provider);

            if (! class_exists($provider)) {
                throw new RuntimeException(
                    "Selected client package provider [{$provider}] does not exist.",
                );
            }

            if (! is_subclass_of($provider, ServiceProvider::class)) {
                throw new RuntimeException(sprintf(
                    'Selected client package provider [%s] must extend [%s].',
                    $provider,
                    ServiceProvider::class,
                ));
            }

            $providers[] = $provider;
        }

        return array_values(array_unique($providers));
    }

    /**
     * @return array<string, EnvironmentVariableDefinition>
     */
    private function environmentDefinitions(
        mixed $value,
        string $manifestPath,
    ): array {
        if (! is_array($value)) {
            throw new RuntimeException(
                "Selected client package manifest [{$manifestPath}] environment definitions must be an array.",
            );
        }

        $builtInDefinitions = EnvironmentVariableCatalog::builtInDefinitions();
        $definitions = [];

        foreach ($value as $key => $definition) {
            if (! is_string($key)
                || preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1
            ) {
                throw new RuntimeException(
                    "Selected client package manifest [{$manifestPath}] contains an invalid environment variable key.",
                );
            }

            if (array_key_exists($key, $builtInDefinitions)) {
                throw new RuntimeException(
                    "Selected client package environment variable [{$key}] conflicts with an Engage-owned environment variable.",
                );
            }

            if (! is_array($definition)) {
                throw new RuntimeException(
                    "Selected client package environment variable [{$key}] must declare an array definition.",
                );
            }

            $this->assertAllowedKeys(
                value: $definition,
                allowed: ['owner', 'secret'],
                context: "Selected client package environment variable [{$key}]",
            );

            $owner = $definition['owner'] ?? null;

            if (! is_string($owner)
                || preg_match('/^[a-z0-9][a-z0-9._-]*$/', trim($owner)) !== 1
            ) {
                throw new RuntimeException(
                    "Selected client package environment variable [{$key}] must declare a lowercase owner key.",
                );
            }

            $secret = $definition['secret'] ?? false;

            if (! is_bool($secret)) {
                throw new RuntimeException(
                    "Selected client package environment variable [{$key}] secret flag must be boolean.",
                );
            }

            $definitions[$key] = new EnvironmentVariableDefinition(
                key: $key,
                scope: EnvironmentVariableDefinition::SCOPE_CLIENT,
                owner: trim($owner),
                secret: $secret,
            );
        }

        return $definitions;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $allowed
     */
    private function assertAllowedKeys(
        array $value,
        array $allowed,
        string $context,
    ): void {
        $unexpected = array_values(array_diff(
            array_map(static fn (mixed $key): string => (string) $key, array_keys($value)),
            $allowed,
        ));

        sort($unexpected);

        if ($unexpected !== []) {
            throw new RuntimeException(sprintf(
                '%s contains unsupported key(s): %s.',
                $context,
                implode(', ', $unexpected),
            ));
        }
    }

    private function assertNoFrameworkShadow(string $clientDirectory): void
    {
        $forbidden = [
            $clientDirectory.'/vendor/laravel/framework',
            $clientDirectory.'/vendor/illuminate',
        ];

        foreach ($forbidden as $path) {
            if (! is_dir($path)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Selected client Composer dependencies [%s] include a second Laravel/Illuminate runtime. Client packages must use the Engage Core host framework and public contracts.',
                $path,
            ));
        }
    }

    private function clientKey(): ?string
    {
        $clientKey = Env::get('CLIENT_KEY');

        if (! is_string($clientKey)) {
            return null;
        }

        $clientKey = trim($clientKey);

        return $clientKey !== ''
            ? $clientKey
            : null;
    }
}
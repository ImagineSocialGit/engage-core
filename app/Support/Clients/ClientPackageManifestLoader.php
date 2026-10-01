<?php

namespace App\Support\Clients;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class ClientPackageManifestLoader
{
    private const MODULE_KEY_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    private const PACKAGE_MIGRATION_PATH_PATTERN = '/^vendor\/[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*\/database\/migrations(?:\/[a-z0-9_-]+)*$/D';

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
            allowed: ['providers', 'environment', 'modules', 'migrations'],
            context: "Selected client package manifest [{$manifestPath}]",
        );

        $providers = $this->serviceProviderClasses(
            value: $manifest['providers'] ?? [],
            context: "Selected client package manifest [{$manifestPath}] providers",
            autoloadPath: $autoloadPath,
        );

        $environmentDefinitions = $this->environmentDefinitions(
            value: $manifest['environment'] ?? [],
            manifestPath: $manifestPath,
        );

        $moduleDefinitions = $this->moduleDefinitions(
            value: $manifest['modules'] ?? [],
            manifestPath: $manifestPath,
            autoloadPath: $autoloadPath,
        );

        $migrationScopes = $this->migrationScopes(
            value: $manifest['migrations'] ?? [],
            moduleDefinitions: $moduleDefinitions,
            clientKey: $clientKey,
            clientDirectory: $clientDirectory,
            manifestPath: $manifestPath,
            autoloadPath: $autoloadPath,
        );

        return new ClientPackageManifest(
            providers: $providers,
            environmentDefinitions: $environmentDefinitions,
            moduleDefinitions: $moduleDefinitions,
            migrationScopes: $migrationScopes,
        );
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
     * @return array<string, array<string, mixed>>
     */
    private function moduleDefinitions(
        mixed $value,
        string $manifestPath,
        string $autoloadPath,
    ): array {
        if (! is_array($value)) {
            throw new RuntimeException(
                "Selected client package manifest [{$manifestPath}] modules must be an array.",
            );
        }

        if ($value !== [] && ! is_file($autoloadPath)) {
            throw new RuntimeException(sprintf(
                'Selected client package manifest [%s] declares modules but Composer dependencies are not installed at [%s].',
                $manifestPath,
                $autoloadPath,
            ));
        }

        $definitions = [];

        foreach ($value as $moduleKey => $definition) {
            if (! is_string($moduleKey)
                || preg_match(self::MODULE_KEY_PATTERN, trim($moduleKey)) !== 1
            ) {
                throw new RuntimeException(
                    "Selected client package manifest [{$manifestPath}] contains an invalid module key.",
                );
            }

            $moduleKey = trim($moduleKey);

            if (! is_array($definition)) {
                throw new RuntimeException(
                    "Selected client package module [{$moduleKey}] must declare an array definition.",
                );
            }

            $this->assertAllowedKeys(
                value: $definition,
                allowed: [
                    'name',
                    'ui',
                    'nav',
                    'settings',
                    'always_on',
                    'depends_on',
                    'requires_provider',
                    'preset_contributors',
                    'message_template_definition_contributors',
                    'providers',
                ],
                context: "Selected client package module [{$moduleKey}]",
            );

            $name = $definition['name'] ?? null;

            if (! is_string($name) || trim($name) === '') {
                throw new RuntimeException(
                    "Selected client package module [{$moduleKey}] requires a non-empty name.",
                );
            }

            foreach (['ui', 'nav', 'settings'] as $arrayField) {
                if (array_key_exists($arrayField, $definition)
                    && ! is_array($definition[$arrayField])
                ) {
                    throw new RuntimeException(
                        "Selected client package module [{$moduleKey}] {$arrayField} must be an array when declared.",
                    );
                }
            }

            foreach (['always_on', 'requires_provider'] as $booleanField) {
                if (array_key_exists($booleanField, $definition)
                    && ! is_bool($definition[$booleanField])
                ) {
                    throw new RuntimeException(
                        "Selected client package module [{$moduleKey}] {$booleanField} must be boolean when declared.",
                    );
                }
            }

            if (($definition['always_on'] ?? false) === true) {
                throw new RuntimeException(
                    "Selected client package module [{$moduleKey}] cannot be always_on; package installation must not enable runtime capability.",
                );
            }

            $dependencies = $this->moduleKeyList(
                value: $definition['depends_on'] ?? [],
                context: "Selected client package module [{$moduleKey}] depends_on",
            );

            $providers = $this->serviceProviderClasses(
                value: $definition['providers'] ?? [],
                context: "Selected client package module [{$moduleKey}] providers",
                autoloadPath: $autoloadPath,
            );

            $presetContributors = $this->classStringList(
                value: $definition['preset_contributors'] ?? [],
                context: "Selected client package module [{$moduleKey}] preset_contributors",
            );

            $messageTemplateContributors = $this->classStringList(
                value: $definition['message_template_definition_contributors'] ?? [],
                context: "Selected client package module [{$moduleKey}] message_template_definition_contributors",
            );

            $definitions[$moduleKey] = [
                ...$definition,
                'name' => trim($name),
                'depends_on' => $dependencies,
                'providers' => $providers,
                'preset_contributors' => $presetContributors,
                'message_template_definition_contributors' => $messageTemplateContributors,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, array<string, mixed>> $moduleDefinitions
     * @return array<string, array{path:string}>
     */
    private function migrationScopes(
        mixed $value,
        array $moduleDefinitions,
        string $clientKey,
        string $clientDirectory,
        string $manifestPath,
        string $autoloadPath,
    ): array {
        if (! is_array($value)) {
            throw new RuntimeException(
                "Selected client package manifest [{$manifestPath}] migrations must be an array.",
            );
        }

        if ($value !== [] && ! is_file($autoloadPath)) {
            throw new RuntimeException(sprintf(
                'Selected client package manifest [%s] declares migrations but Composer dependencies are not installed at [%s].',
                $manifestPath,
                $autoloadPath,
            ));
        }

        $scopes = [];

        foreach ($value as $moduleKey => $definition) {
            if (! is_string($moduleKey)
                || preg_match(self::MODULE_KEY_PATTERN, trim($moduleKey)) !== 1
            ) {
                throw new RuntimeException(
                    "Selected client package manifest [{$manifestPath}] contains an invalid migration module key.",
                );
            }

            $moduleKey = trim($moduleKey);

            if (! array_key_exists($moduleKey, $moduleDefinitions)) {
                throw new RuntimeException(
                    "Selected client package migration scope [{$moduleKey}] must belong to a module declared by the same package manifest.",
                );
            }

            if (! is_array($definition)) {
                throw new RuntimeException(
                    "Selected client package migration scope [{$moduleKey}] must declare an array definition.",
                );
            }

            $this->assertAllowedKeys(
                value: $definition,
                allowed: ['path'],
                context: "Selected client package migration scope [{$moduleKey}]",
            );

            $path = $definition['path'] ?? null;

            if (! is_string($path)
                || preg_match(self::PACKAGE_MIGRATION_PATH_PATTERN, trim($path)) !== 1
            ) {
                throw new RuntimeException(
                    "Selected client package migration scope [{$moduleKey}] path must be a normalized package-relative directory under vendor/<vendor>/<package>/database/migrations.",
                );
            }

            $path = trim($path);
            $absolutePath = $clientDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);

            if (! is_dir($absolutePath)) {
                throw new RuntimeException(
                    "Selected client package migration scope [{$moduleKey}] directory [{$path}] does not exist.",
                );
            }

            $scopes[$moduleKey] = [
                'path' => 'client/'.$clientKey.'/'.$path,
            ];
        }

        return $scopes;
    }

    /**
     * @return list<class-string<ServiceProvider>>
     */
    private function serviceProviderClasses(
        mixed $value,
        string $context,
        string $autoloadPath,
    ): array {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$context} must be a list.");
        }

        if ($value !== [] && ! is_file($autoloadPath)) {
            throw new RuntimeException(sprintf(
                '%s require Composer dependencies to be installed at [%s].',
                $context,
                $autoloadPath,
            ));
        }

        $providers = [];

        foreach ($value as $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                throw new RuntimeException("{$context} contains an invalid provider class.");
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

    /** @return list<string> */
    private function moduleKeyList(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$context} must be a list.");
        }

        $keys = [];

        foreach ($value as $key) {
            if (! is_string($key)
                || preg_match(self::MODULE_KEY_PATTERN, trim($key)) !== 1
            ) {
                throw new RuntimeException("{$context} contains an invalid module key.");
            }

            $keys[] = trim($key);
        }

        return array_values(array_unique($keys));
    }

    /** @return list<class-string> */
    private function classStringList(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("{$context} must be a list.");
        }

        $classes = [];

        foreach ($value as $class) {
            if (! is_string($class) || trim($class) === '') {
                throw new RuntimeException("{$context} contains an invalid class name.");
            }

            $class = trim($class);

            if (! class_exists($class)) {
                throw new RuntimeException("Selected client package class [{$class}] does not exist.");
            }

            $classes[] = $class;
        }

        return array_values(array_unique($classes));
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
<?php

namespace App\Support\Modules\Migrations;

use App\Support\Modules\ModuleManager;
use InvalidArgumentException;

final class ModuleMigrationRegistry
{
    /**
     * @var array<string, MigrationScopeDefinition>|null
     */
    private ?array $resolved = null;

    public function __construct(
        private readonly ModuleManager $modules,
    ) {}

    public function platform(): MigrationScopeDefinition
    {
        return $this->definitions()['platform'];
    }

    /**
     * @return array<string, MigrationScopeDefinition>
     */
    public function modules(): array
    {
        return array_filter(
            $this->definitions(),
            static fn (MigrationScopeDefinition $definition): bool => $definition->isModule(),
        );
    }

    public function module(string $moduleKey): ?MigrationScopeDefinition
    {
        $definition = $this->definitions()[$moduleKey] ?? null;

        return $definition instanceof MigrationScopeDefinition
            && $definition->isModule()
                ? $definition
                : null;
    }

    public function requireModule(string $moduleKey): MigrationScopeDefinition
    {
        $definition = $this->module($moduleKey);

        if (! $definition instanceof MigrationScopeDefinition) {
            throw new InvalidArgumentException(
                "Module [{$moduleKey}] does not own a registered migration scope.",
            );
        }

        return $definition;
    }

    public function hasModule(string $moduleKey): bool
    {
        return $this->module($moduleKey) instanceof MigrationScopeDefinition;
    }

    /**
     * @return array<string, MigrationScopeDefinition>
     */
    public function definitions(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $configuration = config('module_migrations');

        if (! is_array($configuration)) {
            throw new InvalidArgumentException(
                'module_migrations configuration must be an array.',
            );
        }

        $unknownRootFields = array_values(array_diff(
            array_map(
                static fn (int|string $field): string => (string) $field,
                array_keys($configuration),
            ),
            ['platform', 'modules'],
        ));

        if ($unknownRootFields !== []) {
            sort($unknownRootFields);

            throw new InvalidArgumentException(sprintf(
                'module_migrations contains unsupported root field(s): [%s].',
                implode(', ', $unknownRootFields),
            ));
        }

        $platform = $configuration['platform'] ?? null;
        $moduleDefinitions = $configuration['modules'] ?? null;

        if (! is_array($platform)) {
            throw new InvalidArgumentException(
                'module_migrations.platform must be an array.',
            );
        }

        if (! is_array($moduleDefinitions)) {
            throw new InvalidArgumentException(
                'module_migrations.modules must be an array.',
            );
        }

        $definitions = [
            'platform' => MigrationScopeDefinition::platform($platform),
        ];

        foreach ($moduleDefinitions as $moduleKey => $definition) {
            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                throw new InvalidArgumentException(
                    'Every module migration scope must use a non-empty string key.',
                );
            }

            $moduleKey = trim($moduleKey);

            if (! $this->modules->known($moduleKey)) {
                throw new InvalidArgumentException(
                    "Migration scope references unknown module [{$moduleKey}].",
                );
            }

            if (! is_array($definition)) {
                throw new InvalidArgumentException(
                    "Migration scope for module [{$moduleKey}] must be an array.",
                );
            }

            $definitions[$moduleKey] = MigrationScopeDefinition::module(
                moduleKey: $moduleKey,
                definition: $definition,
            );
        }

        $this->assertUniquePaths($definitions);
        $this->assertUniqueMigrationOwners($definitions);

        return $this->resolved = $definitions;
    }

    public function manifestHash(MigrationScopeDefinition $definition): string
    {
        return hash('sha256', json_encode([
            'key' => $definition->key,
            'module_key' => $definition->moduleKey,
            'path' => $definition->path,
            'schema_version' => $definition->schemaVersion,
            'migrations' => $definition->migrationFiles,
        ], JSON_THROW_ON_ERROR));
    }

    public function ownerFor(string $migrationFile): ?MigrationScopeDefinition
    {
        $migrationFile = basename(trim($migrationFile));

        foreach ($this->definitions() as $definition) {
            if ($definition->owns($migrationFile)) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public function migrationFiles(): array
    {
        $files = [];

        foreach ($this->definitions() as $definition) {
            foreach ($definition->migrationFiles as $migrationFile) {
                $files[] = $migrationFile;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param array<string, MigrationScopeDefinition> $definitions
     */
    private function assertUniquePaths(array $definitions): void
    {
        $ownersByPath = [];

        foreach ($definitions as $definition) {
            $existingOwner = $ownersByPath[$definition->path] ?? null;

            if (is_string($existingOwner)) {
                throw new InvalidArgumentException(
                    "Migration scopes [{$existingOwner}] and [{$definition->key}] share target path [{$definition->path}].",
                );
            }

            $ownersByPath[$definition->path] = $definition->key;
        }
    }

    /**
     * @param array<string, MigrationScopeDefinition> $definitions
     */
    private function assertUniqueMigrationOwners(array $definitions): void
    {
        $ownersByFile = [];

        foreach ($definitions as $definition) {
            foreach ($definition->migrationFiles as $migrationFile) {
                $existingOwner = $ownersByFile[$migrationFile] ?? null;

                if (is_string($existingOwner)) {
                    throw new InvalidArgumentException(
                        "Migration [{$migrationFile}] is owned by both [{$existingOwner}] and [{$definition->key}].",
                    );
                }

                $ownersByFile[$migrationFile] = $definition->key;
            }
        }
    }
}
<?php

namespace App\Support\Modules\Migrations;

final readonly class ModuleMigrationPlan
{
    /**
     * @param array<int, string> $requestedModuleKeys
     * @param array<int, string> $dependencyOrderedModuleKeys
     * @param array<int, MigrationScopeDefinition> $migrationScopes
     */
    public function __construct(
        public array $requestedModuleKeys,
        public array $dependencyOrderedModuleKeys,
        public array $migrationScopes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function migrationModuleKeys(): array
    {
        return array_values(array_map(
            static fn (MigrationScopeDefinition $scope): string => (string) $scope->moduleKey,
            $this->migrationScopes,
        ));
    }
}
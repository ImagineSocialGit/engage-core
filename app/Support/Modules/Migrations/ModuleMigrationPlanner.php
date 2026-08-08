<?php

namespace App\Support\Modules\Migrations;

use App\Support\Modules\ModuleManager;
use InvalidArgumentException;

final class ModuleMigrationPlanner
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleMigrationRegistry $registry,
    ) {}

    public function forModule(string $moduleKey): ModuleMigrationPlan
    {
        return $this->forModules([$moduleKey]);
    }

    /**
     * @param array<int, string> $moduleKeys
     */
    public function forModules(array $moduleKeys): ModuleMigrationPlan
    {
        $requestedModuleKeys = $this->normalizeRequestedModuleKeys($moduleKeys);
        $dependencyOrderedModuleKeys = [];
        $states = [];

        foreach ($requestedModuleKeys as $moduleKey) {
            $this->visit(
                moduleKey: $moduleKey,
                dependencyOrderedModuleKeys: $dependencyOrderedModuleKeys,
                states: $states,
                stack: [],
            );
        }

        $migrationScopes = [];

        foreach ($dependencyOrderedModuleKeys as $moduleKey) {
            $scope = $this->registry->module($moduleKey);

            if ($scope instanceof MigrationScopeDefinition) {
                $migrationScopes[] = $scope;
            }
        }

        return new ModuleMigrationPlan(
            requestedModuleKeys: $requestedModuleKeys,
            dependencyOrderedModuleKeys: $dependencyOrderedModuleKeys,
            migrationScopes: $migrationScopes,
        );
    }

    /**
     * @param array<int, string> $moduleKeys
     * @return array<int, string>
     */
    private function normalizeRequestedModuleKeys(array $moduleKeys): array
    {
        $normalized = [];

        foreach ($moduleKeys as $index => $moduleKey) {
            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                throw new InvalidArgumentException(
                    "Requested module key at index [{$index}] must be a non-empty string.",
                );
            }

            $moduleKey = trim($moduleKey);

            if (! $this->modules->known($moduleKey)) {
                throw new InvalidArgumentException(
                    "Unknown module [{$moduleKey}].",
                );
            }

            if (! in_array($moduleKey, $normalized, true)) {
                $normalized[] = $moduleKey;
            }
        }

        if ($normalized === []) {
            throw new InvalidArgumentException(
                'At least one module key is required to build a migration plan.',
            );
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $dependencyOrderedModuleKeys
     * @param array<string, string> $states
     * @param array<int, string> $stack
     */
    private function visit(
        string $moduleKey,
        array &$dependencyOrderedModuleKeys,
        array &$states,
        array $stack,
    ): void {
        $state = $states[$moduleKey] ?? null;

        if ($state === 'resolved') {
            return;
        }

        if ($state === 'resolving') {
            $cycleStart = array_search($moduleKey, $stack, true);
            $cycle = $cycleStart === false
                ? [...$stack, $moduleKey]
                : [...array_slice($stack, $cycleStart), $moduleKey];

            throw new InvalidArgumentException(sprintf(
                'Module dependency cycle detected: [%s].',
                implode(' -> ', $cycle),
            ));
        }

        if (! $this->modules->known($moduleKey)) {
            throw new InvalidArgumentException(
                "Unknown module dependency [{$moduleKey}].",
            );
        }

        $states[$moduleKey] = 'resolving';
        $stack[] = $moduleKey;

        foreach ($this->modules->dependencies($moduleKey) as $dependency) {
            $this->visit(
                moduleKey: $dependency,
                dependencyOrderedModuleKeys: $dependencyOrderedModuleKeys,
                states: $states,
                stack: $stack,
            );
        }

        $states[$moduleKey] = 'resolved';
        $dependencyOrderedModuleKeys[] = $moduleKey;
    }
}
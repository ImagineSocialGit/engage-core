<?php

namespace App\Support\Modules\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class ModuleMigrationStatusInspector
{
    public function __construct(
        private readonly ModuleMigrationRegistry $registry,
        private readonly Migrator $migrator,
    ) {}

    public function inspectModule(string $moduleKey): ModuleMigrationStatus
    {
        $statuses = $this->inspectScopes([
            $this->registry->requireModule($moduleKey),
        ]);

        return $statuses[0];
    }

    /**
     * @param array<int, MigrationScopeDefinition> $scopes
     * @return array<int, ModuleMigrationStatus>
     */
    public function inspectScopes(array $scopes): array
    {
        foreach ($scopes as $index => $scope) {
            if (! $scope instanceof MigrationScopeDefinition || ! $scope->isModule()) {
                throw new InvalidArgumentException(
                    "Migration status scope at index [{$index}] must be a module scope definition.",
                );
            }
        }

        $repositoryExists = $this->migrator->repositoryExists();
        $ranMigrations = $repositoryExists
            ? array_fill_keys($this->migrator->getRepository()->getRan(), true)
            : [];
        $ledgerExists = Schema::hasTable('module_installations');
        $installations = $this->installationsByModuleKey($scopes, $ledgerExists);
        $statuses = [];

        foreach ($scopes as $scope) {
            $pendingMigrationFiles = [];
            $ranMigrationCount = 0;

            foreach ($scope->migrationFiles as $migrationFile) {
                $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);

                if (isset($ranMigrations[$migrationName])) {
                    $ranMigrationCount++;
                } else {
                    $pendingMigrationFiles[] = $migrationFile;
                }
            }

            $installation = $installations->get($scope->moduleKey);
            $ledgerStatus = $installation instanceof ModuleInstallation
                ? $installation->status
                : ($ledgerExists
                    ? ModuleMigrationStatus::LEDGER_UNTRACKED
                    : ModuleMigrationStatus::LEDGER_MISSING);

            $statuses[] = new ModuleMigrationStatus(
                scope: $scope,
                migrationState: $this->migrationState(
                    repositoryExists: $repositoryExists,
                    ranMigrationCount: $ranMigrationCount,
                    expectedMigrationCount: count($scope->migrationFiles),
                ),
                expectedMigrationCount: count($scope->migrationFiles),
                ranMigrationCount: $ranMigrationCount,
                pendingMigrationFiles: $pendingMigrationFiles,
                ledgerStatus: $ledgerStatus,
                contractState: $this->contractState(
                    scope: $scope,
                    installation: $installation,
                    ledgerExists: $ledgerExists,
                ),
                recordedSchemaVersion: $installation instanceof ModuleInstallation
                    ? $installation->schema_version
                    : null,
                recordedManifestHash: $installation instanceof ModuleInstallation
                    ? $installation->manifest_hash
                    : null,
            );
        }

        return $statuses;
    }

    private function migrationState(
        bool $repositoryExists,
        int $ranMigrationCount,
        int $expectedMigrationCount,
    ): string {
        if (! $repositoryExists) {
            return ModuleMigrationStatus::MIGRATIONS_REPOSITORY_MISSING;
        }

        if ($ranMigrationCount === 0) {
            return ModuleMigrationStatus::MIGRATIONS_NOT_MIGRATED;
        }

        if ($ranMigrationCount < $expectedMigrationCount) {
            return ModuleMigrationStatus::MIGRATIONS_PARTIAL;
        }

        return ModuleMigrationStatus::MIGRATIONS_CURRENT;
    }

    private function contractState(
        MigrationScopeDefinition $scope,
        mixed $installation,
        bool $ledgerExists,
    ): string {
        if (! $ledgerExists) {
            return ModuleMigrationStatus::CONTRACT_UNAVAILABLE;
        }

        if (! $installation instanceof ModuleInstallation) {
            return ModuleMigrationStatus::CONTRACT_UNTRACKED;
        }

        return $installation->schema_version === $scope->schemaVersion
            && hash_equals(
                $this->registry->manifestHash($scope),
                (string) $installation->manifest_hash,
            )
                ? ModuleMigrationStatus::CONTRACT_CURRENT
                : ModuleMigrationStatus::CONTRACT_DRIFT;
    }

    /**
     * @param array<int, MigrationScopeDefinition> $scopes
     * @return Collection<string, ModuleInstallation>
     */
    private function installationsByModuleKey(
        array $scopes,
        bool $ledgerExists,
    ): Collection {
        if (! $ledgerExists || $scopes === []) {
            return collect();
        }

        $moduleKeys = array_values(array_map(
            static fn (MigrationScopeDefinition $scope): string => (string) $scope->moduleKey,
            $scopes,
        ));

        return ModuleInstallation::query()
            ->whereIn('module_key', $moduleKeys)
            ->get()
            ->keyBy('module_key');
    }
}
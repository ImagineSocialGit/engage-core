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
        $checksumLedgerExists = $ledgerExists
            && Schema::hasColumn('module_installations', 'migration_checksums');
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
            $integrity = $this->integrity(
                scope: $scope,
                installation: $installation,
                ranMigrations: $ranMigrations,
                checksumLedgerExists: $checksumLedgerExists,
            );

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
                integrityState: $integrity['state'],
                changedAppliedMigrationFiles: $integrity['changed'],
                missingRecordedMigrationFiles: $integrity['missing'],
                untrackedAppliedMigrationFiles: $integrity['untracked_applied'],
                recordedMigrationChecksums: $integrity['recorded'],
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
     * @param array<string, bool> $ranMigrations
     * @return array{
     *     state: string,
     *     changed: array<int, string>,
     *     missing: array<int, string>,
     *     untracked_applied: array<int, string>,
     *     recorded: array<string, string>|null
     * }
     */
    private function integrity(
        MigrationScopeDefinition $scope,
        mixed $installation,
        array $ranMigrations,
        bool $checksumLedgerExists,
    ): array {
        $empty = [
            'changed' => [],
            'missing' => [],
            'untracked_applied' => [],
            'recorded' => null,
        ];

        if (! $checksumLedgerExists) {
            return [
                'state' => ModuleMigrationStatus::INTEGRITY_UNAVAILABLE,
                ...$empty,
            ];
        }

        if (! $installation instanceof ModuleInstallation) {
            return [
                'state' => ModuleMigrationStatus::INTEGRITY_UNTRACKED,
                ...$empty,
            ];
        }

        $rawChecksums = $installation->getRawOriginal('migration_checksums');

        if ($rawChecksums === null) {
            return [
                'state' => ModuleMigrationStatus::INTEGRITY_BASELINE_MISSING,
                ...$empty,
            ];
        }

        $recorded = $this->normalizeChecksums($installation->migration_checksums);

        if ($recorded === null) {
            return [
                'state' => ModuleMigrationStatus::INTEGRITY_DRIFT,
                ...$empty,
            ];
        }

        $missing = array_values(array_diff(
            array_keys($recorded),
            $scope->migrationFiles,
        ));
        $changed = [];
        $untrackedApplied = [];

        foreach ($scope->migrationFiles as $migrationFile) {
            $migrationName = pathinfo($migrationFile, PATHINFO_FILENAME);

            if (! isset($ranMigrations[$migrationName])) {
                continue;
            }

            $recordedChecksum = $recorded[$migrationFile] ?? null;

            if (! is_string($recordedChecksum)) {
                $untrackedApplied[] = $migrationFile;

                continue;
            }

            if (! hash_equals($recordedChecksum, $scope->checksum($migrationFile))) {
                $changed[] = $migrationFile;
            }
        }

        sort($changed, SORT_STRING);
        sort($missing, SORT_STRING);
        sort($untrackedApplied, SORT_STRING);

        return [
            'state' => $changed !== [] || $missing !== [] || $untrackedApplied !== []
                ? ModuleMigrationStatus::INTEGRITY_DRIFT
                : ModuleMigrationStatus::INTEGRITY_CURRENT,
            'changed' => $changed,
            'missing' => $missing,
            'untracked_applied' => $untrackedApplied,
            'recorded' => $recorded,
        ];
    }

    /** @return array<string, string>|null */
    private function normalizeChecksums(mixed $checksums): ?array
    {
        if (! is_array($checksums) || $checksums === []) {
            return null;
        }

        $normalized = [];

        foreach ($checksums as $file => $checksum) {
            if (! is_string($file)
                || ! is_string($checksum)
                || preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1
            ) {
                return null;
            }

            $normalized[$file] = $checksum;
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
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
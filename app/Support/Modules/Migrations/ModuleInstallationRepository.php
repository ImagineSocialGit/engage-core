<?php

namespace App\Support\Modules\Migrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ModuleInstallationRepository
{
    public function __construct(
        private readonly ModuleMigrationRegistry $registry,
    ) {}

    public function find(string $moduleKey): ?ModuleInstallation
    {
        $scope = $this->registry->requireModule($moduleKey);

        return ModuleInstallation::query()->find($scope->moduleKey);
    }

    public function installed(string $moduleKey): bool
    {
        return $this->find($moduleKey)?->status
            === ModuleInstallation::STATUS_INSTALLED;
    }

    /** @return array<int, string> */
    public function installedModuleKeys(): array
    {
        return ModuleInstallation::query()
            ->where('status', ModuleInstallation::STATUS_INSTALLED)
            ->orderBy('module_key')
            ->pluck('module_key')
            ->map(static fn (mixed $moduleKey): string => (string) $moduleKey)
            ->values()
            ->all();
    }

    /**
     * @param array<string, string> $migrationChecksums
     */
    public function establishMigrationChecksumBaseline(
        string $moduleKey,
        array $migrationChecksums,
    ): ModuleInstallation {
        $scope = $this->registry->requireModule($moduleKey);
        ksort($migrationChecksums, SORT_STRING);

        return DB::transaction(function () use ($scope, $migrationChecksums): ModuleInstallation {
            $installation = ModuleInstallation::query()
                ->lockForUpdate()
                ->find($scope->moduleKey);

            if (! $installation instanceof ModuleInstallation
                || $installation->status !== ModuleInstallation::STATUS_INSTALLED
            ) {
                throw new RuntimeException(
                    "Module migration checksum baseline can only be established for installed module [{$scope->moduleKey}].",
                );
            }

            $rawChecksums = $installation->getRawOriginal('migration_checksums');

            if ($rawChecksums !== null) {
                $existingChecksums = $installation->migration_checksums;

                if (is_array($existingChecksums)) {
                    ksort($existingChecksums, SORT_STRING);
                }

                if ($existingChecksums === $migrationChecksums) {
                    return $installation->refresh();
                }

                throw new RuntimeException(
                    "Module [{$scope->moduleKey}] already has a different accepted migration checksum baseline.",
                );
            }

            if ($migrationChecksums === []) {
                throw new RuntimeException(
                    "Module [{$scope->moduleKey}] checksum baseline cannot be empty.",
                );
            }

            $expectedFiles = array_slice(
                $scope->migrationFiles,
                0,
                count($migrationChecksums),
            );

            if (array_keys($migrationChecksums) !== $expectedFiles) {
                throw new RuntimeException(
                    "Module [{$scope->moduleKey}] checksum baseline does not match an initial prefix of the current migration inventory.",
                );
            }

            foreach ($migrationChecksums as $migrationFile => $checksum) {
                if (! hash_equals($scope->checksum($migrationFile), $checksum)) {
                    throw new RuntimeException(
                        "Module [{$scope->moduleKey}] checksum baseline contains an unexpected checksum for migration [{$migrationFile}].",
                    );
                }
            }

            $installation->forceFill([
                'migration_checksums' => $migrationChecksums,
            ])->save();

            return $installation->refresh();
        });
    }

    public function begin(string $moduleKey): ModuleInstallation
    {
        $scope = $this->registry->requireModule($moduleKey);

        return DB::transaction(function () use ($scope): ModuleInstallation {
            $installation = ModuleInstallation::query()->firstOrNew([
                'module_key' => $scope->moduleKey,
            ]);
            $acceptedChecksums = $installation->exists
                ? $installation->migration_checksums
                : null;

            $installation->fill([
                'status' => ModuleInstallation::STATUS_INSTALLING,
                'schema_version' => $scope->schemaVersion,
                'manifest_hash' => $this->registry->manifestHash($scope),
                'migration_checksums' => $acceptedChecksums,
                'installed_at' => $installation->installed_at,
                'last_migrated_at' => $installation->last_migrated_at,
            ]);
            $installation->save();

            return $installation->refresh();
        });
    }

    public function markInstalled(
        string $moduleKey,
        ?CarbonInterface $occurredAt = null,
    ): ModuleInstallation {
        $scope = $this->registry->requireModule($moduleKey);
        $occurredAt ??= now();

        return DB::transaction(function () use ($scope, $occurredAt): ModuleInstallation {
            $installation = ModuleInstallation::query()->firstOrNew([
                'module_key' => $scope->moduleKey,
            ]);

            $installation->fill([
                'status' => ModuleInstallation::STATUS_INSTALLED,
                'schema_version' => $scope->schemaVersion,
                'manifest_hash' => $this->registry->manifestHash($scope),
                'migration_checksums' => $scope->migrationChecksums,
                'installed_at' => $installation->installed_at ?? $occurredAt,
                'last_migrated_at' => $occurredAt,
            ]);
            $installation->save();

            return $installation->refresh();
        });
    }

    public function markFailed(string $moduleKey): ModuleInstallation
    {
        $scope = $this->registry->requireModule($moduleKey);

        return DB::transaction(function () use ($scope): ModuleInstallation {
            $installation = ModuleInstallation::query()->firstOrNew([
                'module_key' => $scope->moduleKey,
            ]);
            $acceptedChecksums = $installation->exists
                ? $installation->migration_checksums
                : null;

            $installation->fill([
                'status' => ModuleInstallation::STATUS_FAILED,
                'schema_version' => $scope->schemaVersion,
                'manifest_hash' => $this->registry->manifestHash($scope),
                'migration_checksums' => $acceptedChecksums,
                'installed_at' => $installation->installed_at,
                'last_migrated_at' => $installation->last_migrated_at,
            ]);
            $installation->save();

            return $installation->refresh();
        });
    }
}
<?php

namespace App\Support\Modules\Migrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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

    /**
     * @return array<int, string>
     */
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

    public function begin(string $moduleKey): ModuleInstallation
    {
        $scope = $this->registry->requireModule($moduleKey);

        return DB::transaction(function () use ($scope): ModuleInstallation {
            $installation = ModuleInstallation::query()->firstOrNew([
                'module_key' => $scope->moduleKey,
            ]);

            $installation->fill([
                'status' => ModuleInstallation::STATUS_INSTALLING,
                'schema_version' => $scope->schemaVersion,
                'manifest_hash' => $this->registry->manifestHash($scope),
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

            $installation->fill([
                'status' => ModuleInstallation::STATUS_FAILED,
                'schema_version' => $scope->schemaVersion,
                'manifest_hash' => $this->registry->manifestHash($scope),
                'installed_at' => $installation->installed_at,
                'last_migrated_at' => $installation->last_migrated_at,
            ]);
            $installation->save();

            return $installation->refresh();
        });
    }
}
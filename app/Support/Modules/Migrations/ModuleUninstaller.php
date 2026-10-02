<?php

namespace App\Support\Modules\Migrations;

use App\Support\Modules\ModuleManager;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ModuleUninstaller
{
    private const LOCK_SECONDS = 3600;

    public function __construct(
        private readonly Migrator $migrator,
        private readonly ModuleManager $modules,
        private readonly ModuleMigrationRegistry $registry,
        private readonly ModuleMigrationPlanner $planner,
        private readonly ModuleMigrationStatusInspector $statuses,
        private readonly ModuleInstallationRepository $installations,
    ) {}

    public function uninstall(
        string $moduleKey,
        bool $allowAppliedDrift = false,
    ): ModuleUninstallResult {
        $moduleKey = trim($moduleKey);

        if ($moduleKey === '' || ! $this->modules->known($moduleKey)) {
            throw new RuntimeException(
                $moduleKey === ''
                    ? 'A module key is required for uninstall.'
                    : "Unknown module [{$moduleKey}].",
            );
        }

        $definition = $this->modules->definitions()[$moduleKey] ?? null;

        if (! is_array($definition)) {
            throw new RuntimeException(
                "Module [{$moduleKey}] does not have a valid module definition.",
            );
        }

        if ($moduleKey === 'core' || (bool) ($definition['always_on'] ?? false)) {
            throw new RuntimeException(
                "Module [{$moduleKey}] is always-on and cannot be uninstalled.",
            );
        }

        $scope = $this->registry->requireModule($moduleKey);

        $this->assertRuntimeDisabled($moduleKey);
        $this->assertNoInstalledDependents($moduleKey);
        $this->assertPlatformFoundationExists();

        $lock = Cache::lock(
            ModuleMigrationExecutor::LOCK_KEY,
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            throw new RuntimeException(
                'Another module migration operation is already running.',
            );
        }

        try {
            return $this->uninstallLocked(
                scope: $scope,
                allowAppliedDrift: $allowAppliedDrift,
            );
        } finally {
            $lock->release();
        }
    }

    private function uninstallLocked(
        MigrationScopeDefinition $scope,
        bool $allowAppliedDrift,
    ): ModuleUninstallResult {
        $moduleKey = (string) $scope->moduleKey;
        $status = $this->statuses->inspectModule($moduleKey);

        if ($status->ledgerStatus === ModuleMigrationStatus::LEDGER_UNTRACKED
            && $status->ranMigrationCount === 0
        ) {
            return new ModuleUninstallResult(
                moduleKey: $moduleKey,
                rolledBackMigrationFiles: [],
                alreadyUninstalled: true,
                usedAppliedDriftOverride: false,
            );
        }

        if ($status->ledgerStatus !== ModuleInstallation::STATUS_INSTALLED) {
            throw new RuntimeException(sprintf(
                'Module [%s] cannot be uninstalled because its installation ledger state is [%s]. Resolve that state before retrying.',
                $moduleKey,
                $status->ledgerStatus,
            ));
        }

        if ($status->ranMigrationCount === 0) {
            $this->forgetInstallation($moduleKey);

            return new ModuleUninstallResult(
                moduleKey: $moduleKey,
                rolledBackMigrationFiles: [],
                alreadyUninstalled: false,
                usedAppliedDriftOverride: false,
            );
        }

        $this->assertRollbackInventorySafe(
            status: $status,
            allowAppliedDrift: $allowAppliedDrift,
        );
        $this->assertScopeFilesExist($scope);

        try {
            $rolledBack = $this->migrator->reset(
                [base_path($scope->path)],
                false,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                "Module [{$moduleKey}] uninstall stopped while reversing migrations. The installation ledger was preserved. Inspect [php artisan modules:status {$moduleKey}], correct the rollback problem, and rerun the uninstall. {$exception->getMessage()}",
                previous: $exception,
            );
        }

        $afterRollback = $this->statuses->inspectModule($moduleKey);

        if ($afterRollback->ranMigrationCount !== 0) {
            throw new RuntimeException(sprintf(
                'Module [%s] rollback finished without removing every recorded migration. %d migration(s) remain recorded; the installation ledger was preserved.',
                $moduleKey,
                $afterRollback->ranMigrationCount,
            ));
        }

        $this->forgetInstallation($moduleKey);

        $after = $this->statuses->inspectModule($moduleKey);

        if ($after->ranMigrationCount !== 0
            || $after->ledgerStatus !== ModuleMigrationStatus::LEDGER_UNTRACKED
        ) {
            throw new RuntimeException(
                "Module [{$moduleKey}] did not reach a clean uninstalled state.",
            );
        }

        return new ModuleUninstallResult(
            moduleKey: $moduleKey,
            rolledBackMigrationFiles: array_values(array_map(
                static fn (string $path): string => basename($path),
                $rolledBack,
            )),
            alreadyUninstalled: false,
            usedAppliedDriftOverride: $allowAppliedDrift
                && $status->changedAppliedMigrationFiles !== [],
        );
    }

    private function assertRuntimeDisabled(string $moduleKey): void
    {
        if (in_array(
            $moduleKey,
            $this->modules->enabledKeysWithDependencies(),
            true,
        )) {
            throw new RuntimeException(
                "Module [{$moduleKey}] is still enabled directly or required by an enabled module. Remove it from client module configuration, deploy that change, clear cached configuration, and retry.",
            );
        }
    }

    private function assertNoInstalledDependents(string $moduleKey): void
    {
        $dependents = [];

        foreach ($this->installations->installedModuleKeys() as $installedModuleKey) {
            if ($installedModuleKey === $moduleKey
                || ! $this->modules->known($installedModuleKey)
            ) {
                continue;
            }

            $plan = $this->planner->forModule($installedModuleKey);

            if (in_array($moduleKey, $plan->dependencyOrderedModuleKeys, true)) {
                $dependents[] = $installedModuleKey;
            }
        }

        if ($dependents === []) {
            return;
        }

        sort($dependents, SORT_STRING);

        throw new RuntimeException(sprintf(
            'Module [%s] cannot be uninstalled while installed module(s) depend on it: [%s]. Uninstall the dependent modules first.',
            $moduleKey,
            implode(', ', $dependents),
        ));
    }

    private function assertPlatformFoundationExists(): void
    {
        if (! $this->migrator->repositoryExists()
            || ! Schema::hasTable('module_installations')
            || ! Schema::hasColumn('module_installations', 'migration_checksums')
        ) {
            throw new RuntimeException(
                'Platform migration foundation is incomplete. Laravel migration history and the checksum-capable module installation ledger are required before uninstalling a module.',
            );
        }
    }

    private function assertRollbackInventorySafe(
        ModuleMigrationStatus $status,
        bool $allowAppliedDrift,
    ): void {
        $moduleKey = (string) $status->scope->moduleKey;

        if (! is_array($status->recordedMigrationChecksums)
            || $status->recordedMigrationChecksums === []
        ) {
            throw new RuntimeException(
                "Module [{$moduleKey}] has no accepted migration checksum baseline. Establish or repair the module migration baseline before uninstalling it.",
            );
        }

        if ($status->missingRecordedMigrationFiles !== []) {
            throw new RuntimeException(sprintf(
                'Module [%s] cannot be uninstalled because previously accepted migration file(s) are missing: [%s]. Restore the historical migration files before retrying.',
                $moduleKey,
                implode(', ', $status->missingRecordedMigrationFiles),
            ));
        }

        if ($status->untrackedAppliedMigrationFiles !== []) {
            throw new RuntimeException(sprintf(
                'Module [%s] cannot be uninstalled because applied migration file(s) are absent from its accepted checksum baseline: [%s]. Reconcile the migration history before retrying.',
                $moduleKey,
                implode(', ', $status->untrackedAppliedMigrationFiles),
            ));
        }

        if ($status->changedAppliedMigrationFiles !== [] && ! $allowAppliedDrift) {
            throw new RuntimeException(sprintf(
                'Module [%s] contains changed already-applied migration file(s): [%s]. Uninstall is blocked because current down() behavior may differ from the accepted installed code. For an intentionally disposable/pre-rollout module only, take a database backup and rerun with --allow-applied-drift.',
                $moduleKey,
                implode(', ', $status->changedAppliedMigrationFiles),
            ));
        }
    }

    private function assertScopeFilesExist(MigrationScopeDefinition $scope): void
    {
        $scopePath = base_path($scope->path);

        if (! is_dir($scopePath)) {
            throw new RuntimeException(
                "Module migration directory [{$scope->path}] does not exist.",
            );
        }

        foreach ($scope->migrationFiles as $migrationFile) {
            $targetPath = $scope->targetPath($migrationFile);

            if (! is_file(base_path($targetPath))) {
                throw new RuntimeException(
                    "Discovered module migration [{$targetPath}] does not exist.",
                );
            }
        }
    }

    private function forgetInstallation(string $moduleKey): void
    {
        DB::transaction(function () use ($moduleKey): void {
            ModuleInstallation::query()
                ->whereKey($moduleKey)
                ->delete();
        });
    }
}
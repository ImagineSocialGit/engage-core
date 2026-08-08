<?php

namespace App\Support\Modules\Migrations;

use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class ModuleMigrationExecutor
{
    public const LOCK_KEY = 'engage-core:module-migrations';

    private const LOCK_SECONDS = 3600;

    public function __construct(
        private readonly Migrator $migrator,
        private readonly ModuleMigrationStatusInspector $statusInspector,
        private readonly ModuleInstallationRepository $installations,
    ) {}

    public function execute(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        return $this->executeWithLock(
            plan: $plan,
            operation: fn (): ModuleMigrationExecutionResult => $this->installUnlocked($plan),
        );
    }

    public function migrate(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        return $this->executeWithLock(
            plan: $plan,
            operation: fn (): ModuleMigrationExecutionResult => $this->migrateUnlocked($plan),
        );
    }

    public function reconcile(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        return $this->executeWithLock(
            plan: $plan,
            operation: fn (): ModuleMigrationExecutionResult => $this->reconcileUnlocked($plan),
        );
    }

    private function executeWithLock(
        ModuleMigrationPlan $plan,
        Closure $operation,
    ): ModuleMigrationExecutionResult {
        if ($plan->migrationScopes === []) {
            return new ModuleMigrationExecutionResult(
                plan: $plan,
                scopeResults: [],
            );
        }

        $this->assertPlatformFoundationExists();

        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new RuntimeException(
                'Another module migration operation is already running.',
            );
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function installUnlocked(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        $scopeResults = [];

        foreach ($plan->migrationScopes as $scope) {
            $moduleKey = (string) $scope->moduleKey;
            $before = $this->statusInspector->inspectModule($moduleKey);

            if ($before->current() && $before->ledgerCurrent()) {
                $scopeResults[$moduleKey] = [
                    'outcome' => ModuleMigrationExecutionResult::OUTCOME_CURRENT,
                    'ran_migrations' => 0,
                ];

                continue;
            }

            $scopeResults[$moduleKey] = $this->migrateInstallableScope(
                scope: $scope,
                before: $before,
                zeroMigrationOutcome: ModuleMigrationExecutionResult::OUTCOME_TRACKED,
            );
        }

        return new ModuleMigrationExecutionResult(
            plan: $plan,
            scopeResults: $scopeResults,
        );
    }

    private function migrateUnlocked(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        $statuses = $this->statusesByModuleKey($plan);

        foreach ($plan->migrationScopes as $scope) {
            $moduleKey = (string) $scope->moduleKey;
            $status = $statuses[$moduleKey];

            if ($status->ledgerStatus !== ModuleInstallation::STATUS_INSTALLED) {
                throw new RuntimeException(
                    "Module migration scope [{$moduleKey}] is not installed. Run modules:install {$moduleKey} or modules:reconcile {$moduleKey} first.",
                );
            }
        }

        $scopeResults = [];

        foreach ($plan->migrationScopes as $scope) {
            $moduleKey = (string) $scope->moduleKey;
            $before = $statuses[$moduleKey];

            if ($before->current() && $before->ledgerCurrent()) {
                $scopeResults[$moduleKey] = [
                    'outcome' => ModuleMigrationExecutionResult::OUTCOME_CURRENT,
                    'ran_migrations' => 0,
                ];

                continue;
            }

            $scopeResults[$moduleKey] = $this->migrateInstallableScope(
                scope: $scope,
                before: $before,
                zeroMigrationOutcome: ModuleMigrationExecutionResult::OUTCOME_UPDATED,
            );
        }

        return new ModuleMigrationExecutionResult(
            plan: $plan,
            scopeResults: $scopeResults,
        );
    }

    private function reconcileUnlocked(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationExecutionResult {
        $statuses = $this->statusesByModuleKey($plan);

        foreach ($plan->migrationScopes as $scope) {
            $moduleKey = (string) $scope->moduleKey;
            $status = $statuses[$moduleKey];

            if (! $status->current()) {
                throw new RuntimeException(sprintf(
                    'Module migration scope [%s] cannot be reconciled because migrations are [%s]. Pending: %s',
                    $moduleKey,
                    $status->migrationState,
                    $status->pendingSummary(),
                ));
            }

            if ($status->ledgerStatus === ModuleMigrationStatus::LEDGER_UNTRACKED) {
                continue;
            }

            if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED
                && $status->contractState === ModuleMigrationStatus::CONTRACT_CURRENT
            ) {
                continue;
            }

            if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED) {
                throw new RuntimeException(
                    "Module migration scope [{$moduleKey}] has installation contract drift. Run modules:migrate {$moduleKey}.",
                );
            }

            throw new RuntimeException(
                "Module migration scope [{$moduleKey}] has ledger state [{$status->ledgerStatus}] and cannot be reconciled.",
            );
        }

        $scopeResults = DB::transaction(function () use ($plan, $statuses): array {
            $results = [];

            foreach ($plan->migrationScopes as $scope) {
                $moduleKey = (string) $scope->moduleKey;
                $status = $statuses[$moduleKey];

                if ($status->ledgerStatus === ModuleMigrationStatus::LEDGER_UNTRACKED) {
                    $this->installations->markInstalled($moduleKey);

                    $results[$moduleKey] = [
                        'outcome' => ModuleMigrationExecutionResult::OUTCOME_RECONCILED,
                        'ran_migrations' => 0,
                    ];

                    continue;
                }

                $results[$moduleKey] = [
                    'outcome' => ModuleMigrationExecutionResult::OUTCOME_CURRENT,
                    'ran_migrations' => 0,
                ];
            }

            return $results;
        });

        return new ModuleMigrationExecutionResult(
            plan: $plan,
            scopeResults: $scopeResults,
        );
    }

    /**
     * @return array{outcome: string, ran_migrations: int}
     */
    private function migrateInstallableScope(
        MigrationScopeDefinition $scope,
        ModuleMigrationStatus $before,
        string $zeroMigrationOutcome,
    ): array {
        $moduleKey = (string) $scope->moduleKey;

        try {
            $this->installations->begin($moduleKey);
            $this->assertScopeFilesExist($scope);

            $this->migrator->run(
                [base_path($scope->path)],
                [
                    'pretend' => false,
                    'step' => false,
                ],
            );

            $after = $this->statusInspector->inspectModule($moduleKey);

            if (! $after->current()) {
                throw new RuntimeException(sprintf(
                    'Module migration scope [%s] did not reach current state. Pending: %s',
                    $moduleKey,
                    $after->pendingSummary(),
                ));
            }

            $this->installations->markInstalled($moduleKey);

            $ranMigrationCount = max(
                0,
                $after->ranMigrationCount - $before->ranMigrationCount,
            );

            return [
                'outcome' => $ranMigrationCount > 0
                    ? ModuleMigrationExecutionResult::OUTCOME_MIGRATED
                    : $zeroMigrationOutcome,
                'ran_migrations' => $ranMigrationCount,
            ];
        } catch (Throwable $exception) {
            $this->recordFailure($moduleKey, $exception);

            throw new RuntimeException(
                "Module migration scope [{$moduleKey}] failed: {$exception->getMessage()}",
                previous: $exception,
            );
        }
    }

    /**
     * @return array<string, ModuleMigrationStatus>
     */
    private function statusesByModuleKey(
        ModuleMigrationPlan $plan,
    ): array {
        $statuses = [];

        foreach ($this->statusInspector->inspectScopes($plan->migrationScopes) as $status) {
            $statuses[(string) $status->scope->moduleKey] = $status;
        }

        return $statuses;
    }

    private function assertPlatformFoundationExists(): void
    {
        if (! $this->migrator->repositoryExists()
            || ! Schema::hasTable('module_installations')
        ) {
            throw new RuntimeException(
                'Platform migration foundation is missing. Run the platform migrations before operating on modules.',
            );
        }
    }

    private function assertScopeFilesExist(
        MigrationScopeDefinition $scope,
    ): void {
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
                    "Registered module migration [{$targetPath}] does not exist.",
                );
            }
        }
    }

    private function recordFailure(
        string $moduleKey,
        Throwable $originalException,
    ): void {
        try {
            $this->installations->markFailed($moduleKey);
        } catch (Throwable $ledgerException) {
            throw new RuntimeException(
                "Module migration scope [{$moduleKey}] failed and its failed ledger state could not be recorded: {$ledgerException->getMessage()}",
                previous: $originalException,
            );
        }
    }
}
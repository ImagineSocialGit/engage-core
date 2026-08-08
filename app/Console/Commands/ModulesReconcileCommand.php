<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleMigrationExecutionResult;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\Migrations\ModuleMigrationStatus;
use App\Support\Modules\Migrations\ModuleMigrationStatusInspector;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;
use Throwable;

final class ModulesReconcileCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'modules:reconcile
        {module? : Reconcile this module and its schema-owning dependencies}
        {--force : Force the operation to run in production}';

    protected $description = 'Record current existing module schemas in the installation ledger without running migrations.';

    public function handle(
        ModuleMigrationPlanner $planner,
        ModuleMigrationRegistry $registry,
        ModuleMigrationStatusInspector $inspector,
        ModuleMigrationExecutor $executor,
    ): int {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $module = $this->argument('module');

            if (is_string($module) && trim($module) !== '') {
                $plan = $planner->forModule(trim($module));
            } else {
                $statuses = $inspector->inspectScopes(
                    array_values($registry->modules()),
                );

                foreach ($statuses as $status) {
                    if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_REPOSITORY_MISSING) {
                        throw new RuntimeException(
                            'Platform migration foundation is missing. Run the platform migrations before operating on modules.',
                        );
                    }

                    if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_PARTIAL) {
                        throw new RuntimeException(sprintf(
                            'Module migration scope [%s] is partial and blocks bulk reconciliation. Pending: %s',
                            $status->scope->moduleKey,
                            $status->pendingSummary(),
                        ));
                    }
                }

                $currentModuleKeys = array_values(array_map(
                    static fn (ModuleMigrationStatus $status): string => (string) $status->scope->moduleKey,
                    array_filter(
                        $statuses,
                        static fn (ModuleMigrationStatus $status): bool => $status->current(),
                    ),
                ));

                if ($currentModuleKeys === []) {
                    $this->info('No current module migration scopes are available to reconcile.');

                    return self::SUCCESS;
                }

                $plan = $planner->forModules($currentModuleKeys);
            }

            $result = $executor->reconcile($plan);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('Resolved modules: '.implode(
            ', ',
            $result->plan->dependencyOrderedModuleKeys,
        ));

        if ($result->scopeResults === []) {
            $this->info('The resolved module closure owns no registered schema.');

            return self::SUCCESS;
        }

        $this->table(
            ['Module', 'Result'],
            array_map(
                fn (string $moduleKey): array => [
                    $moduleKey,
                    $result->outcome($moduleKey),
                ],
                $result->moduleKeys(),
            ),
        );

        $this->info(sprintf(
            'Module reconciliation completed. %d scope(s) reconciled.',
            $result->countOutcome(
                ModuleMigrationExecutionResult::OUTCOME_RECONCILED,
            ),
        ));

        return self::SUCCESS;
    }
}
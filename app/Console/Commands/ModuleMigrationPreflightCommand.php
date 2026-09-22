<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationPreflightInspector;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Console\Command;
use Throwable;

final class ModuleMigrationPreflightCommand extends Command
{
    protected $signature = 'modules:preflight
        {module? : Optional module key whose dependency closure should be inspected}';

    protected $description = 'Inspect module migration history and checksum integrity without changing the database.';

    public function handle(
        ModuleManager $modules,
        ModuleMigrationRegistry $registry,
        ModuleMigrationPlanner $planner,
        ModuleMigrationPreflightInspector $preflight,
    ): int {
        try {
            $module = $this->argument('module');

            if (is_string($module) && trim($module) !== '') {
                $plan = $planner->forModule(trim($module));
            } else {
                $enabledSchemaModules = array_values(array_filter(
                    $modules->enabledKeysWithDependencies(),
                    static fn (string $moduleKey): bool => $registry->hasModule($moduleKey),
                ));

                if ($enabledSchemaModules === []) {
                    $this->info('No enabled schema-owning modules require migration preflight.');

                    return self::SUCCESS;
                }

                $plan = $planner->forModules($enabledSchemaModules);
            }

            $result = $preflight->inspect($plan);

            if ($result->statuses !== []) {
                $this->table(
                    ['Module', 'Schema', 'Pending', 'Ledger', 'Contract', 'Integrity'],
                    array_map(
                        static fn ($status): array => [
                            (string) $status->scope->moduleKey,
                            $status->migrationState,
                            $status->pendingSummary(),
                            $status->ledgerStatus,
                            $status->contractState,
                            $status->integrityState,
                        ],
                        $result->statuses,
                    ),
                );
            }

            foreach ($result->warnings as $warning) {
                $this->warn($warning);
            }

            if (! $result->safe()) {
                foreach ($result->blockers as $blocker) {
                    $this->error($blocker);
                }

                return self::FAILURE;
            }

            $this->info($result->summary());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
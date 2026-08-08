<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

final class ModulesMigrateCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'modules:migrate
        {module? : Upgrade this installed module and its schema-owning dependencies}
        {--force : Force the operation to run in production}';

    protected $description = 'Run pending migrations for already-installed module scopes without installing untracked modules.';

    public function handle(
        ModuleMigrationPlanner $planner,
        ModuleMigrationExecutor $executor,
        ModuleInstallationRepository $installations,
    ): int {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $module = $this->argument('module');

            if (is_string($module) && trim($module) !== '') {
                $plan = $planner->forModule(trim($module));
            } else {
                $installedModuleKeys = $installations->installedModuleKeys();

                if ($installedModuleKeys === []) {
                    $this->info('No installed module migration scopes are recorded.');

                    return self::SUCCESS;
                }

                $plan = $planner->forModules($installedModuleKeys);
            }

            $result = $executor->migrate($plan);
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
            ['Module', 'Result', 'Migrations run'],
            array_map(
                fn (string $moduleKey): array => [
                    $moduleKey,
                    $result->outcome($moduleKey),
                    $result->ranMigrationCount($moduleKey),
                ],
                $result->moduleKeys(),
            ),
        );

        $this->info(sprintf(
            'Module migration upgrade completed. %d migration(s) ran.',
            $result->totalRanMigrationCount(),
        ));

        return self::SUCCESS;
    }
}
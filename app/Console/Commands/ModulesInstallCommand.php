<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleMigrationExecutionResult;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

final class ModulesInstallCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'modules:install
        {module : Install this module migration scope and its schema-owning dependencies}
        {--force : Force the operation to run in production}';

    protected $description = 'Install a module migration scope and its dependency closure using one shared migration repository.';

    public function handle(
        ModuleMigrationPlanner $planner,
        ModuleMigrationExecutor $executor,
    ): int {
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $module = trim((string) $this->argument('module'));
            $plan = $planner->forModule($module);
            $result = $executor->execute($plan);
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
            'Module installation completed. %d migration(s) ran.',
            $result->totalRanMigrationCount(),
        ));

        return self::SUCCESS;
    }
}
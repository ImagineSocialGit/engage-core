<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\Migrations\ModuleMigrationStatus;
use App\Support\Modules\Migrations\ModuleMigrationStatusInspector;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ModulesStatusCommand extends Command
{
    protected $signature = 'modules:status
        {module? : Limit inspection to this module and its dependency closure}';

    protected $description = 'Inspect module migration history and installation-ledger state without mutating either.';

    public function handle(
        ModuleMigrationPlanner $planner,
        ModuleMigrationRegistry $registry,
        ModuleMigrationStatusInspector $inspector,
    ): int {
        try {
            $module = $this->argument('module');
            $scopes = is_string($module) && trim($module) !== ''
                ? $planner->forModule(trim($module))->migrationScopes
                : array_values($registry->modules());
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scopes === []) {
            $this->info('The requested module dependency closure owns no registered schema.');

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (ModuleMigrationStatus $status): array => [
                $status->scope->moduleKey,
                $status->migrationState,
                $status->progress(),
                $status->ledgerStatus,
                $status->contractState,
                $status->pendingSummary(),
            ],
            $inspector->inspectScopes($scopes),
        );

        $this->table([
            'Module',
            'Migrations',
            'Ran',
            'Ledger',
            'Contract',
            'Pending',
        ], $rows);

        return self::SUCCESS;
    }
}
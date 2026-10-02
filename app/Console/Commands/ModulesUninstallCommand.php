<?php

namespace App\Console\Commands;

use App\Support\Modules\Migrations\ModuleUninstaller;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

final class ModulesUninstallCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'modules:uninstall
        {module : Uninstall this module migration scope and remove its installation ledger state}
        {--force : Confirm this destructive operation and force it to run in production}
        {--allow-applied-drift : Allow current down() methods for already-applied migration files whose checksums changed since installation}';

    protected $description = 'Destructively return one disabled module migration scope to a clean uninstalled state.';

    public function handle(ModuleUninstaller $uninstaller): int
    {
        if (! (bool) $this->option('force')) {
            $this->error(
                'Module uninstall is destructive. Take a database backup, disable the module in client configuration, then rerun with --force.',
            );

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $module = trim((string) $this->argument('module'));
            $result = $uninstaller->uninstall(
                moduleKey: $module,
                allowAppliedDrift: (bool) $this->option('allow-applied-drift'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($result->alreadyUninstalled) {
            $this->info(
                "Module [{$result->moduleKey}] is already in a clean uninstalled state.",
            );

            return self::SUCCESS;
        }

        if ($result->usedAppliedDriftOverride) {
            $this->warn(
                'The uninstall used current down() methods for changed already-applied migration files because --allow-applied-drift was supplied.',
            );
        }

        $this->info(sprintf(
            'Module [%s] uninstalled. %d migration(s) rolled back and installation-ledger state removed.',
            $result->moduleKey,
            $result->rolledBackMigrationCount(),
        ));

        if ($result->rolledBackMigrationFiles !== []) {
            $this->line('Rolled back migrations:');

            foreach ($result->rolledBackMigrationFiles as $migrationFile) {
                $this->line("  - {$migrationFile}");
            }
        }

        $this->line(
            "Verify with: php artisan modules:status {$result->moduleKey}",
        );

        return self::SUCCESS;
    }
}
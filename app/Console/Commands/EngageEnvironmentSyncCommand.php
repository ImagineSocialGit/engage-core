<?php

namespace App\Console\Commands;

use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileSynchronizer;
use Illuminate\Console\Command;

final class EngageEnvironmentSyncCommand extends Command
{
    protected $signature = 'engage:environment:sync
        {--write-missing : Add missing required variable names to the correct root/client environment file}';

    protected $description = 'Reconcile environment-file shape with the current deployment plan without inventing secrets or overwriting existing values.';

    public function handle(
        DeploymentPlanResolver $resolver,
        EnvironmentFileSynchronizer $synchronizer,
    ): int {
        if (! (bool) $this->option('write-missing')) {
            $this->error('No mutation mode selected. Use [engage:deployment-plan] for read-only inspection or add [--write-missing].');

            return self::FAILURE;
        }

        $plan = $resolver->resolve();
        $written = $synchronizer->writeMissingRequiredKeys($plan);

        if ($written === []) {
            $this->info('No missing required environment variable names needed to be added.');
        } else {
            $this->info('Added missing required environment variable names:');

            foreach ($written as $item) {
                $this->line(sprintf(
                    '  - %s -> %s',
                    $item['key'],
                    $item['path'],
                ));
            }
        }

        $this->line('Existing values were not changed. Unused keys were not removed.');
        $this->line('Secret values were not generated or displayed.');
        $this->newLine();
        $this->line('Populate any blank required values, clear cached configuration if applicable, then run:');
        $this->line('  php artisan engage:deployment-plan');

        return self::SUCCESS;
    }
}
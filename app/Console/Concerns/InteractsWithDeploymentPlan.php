<?php

namespace App\Console\Concerns;

use App\Support\Deployment\DeploymentPlanResolver;
use Throwable;

trait InteractsWithDeploymentPlan
{
    protected function deploymentEnvironmentIsReady(
        DeploymentPlanResolver $resolver,
        string $operation,
    ): bool {
        try {
            $plan = $resolver->resolve();
        } catch (Throwable $exception) {
            $this->error($operation.' refused because the deployment plan could not be resolved.');
            $this->line('  '.$exception->getMessage());
            $this->deploymentPreflightRecoveryGuidance();

            return false;
        }

        $this->info('Deployment preflight');
        $this->line('Environment: '.$plan->environment);
        $this->line('Client:      '.($plan->clientKey !== '' ? $plan->clientKey : '[none]'));

        $blocking = $plan->blockingEnvironmentRequirements();

        if ($blocking === []) {
            $this->line('Environment requirements: ready');
            $this->newLine();

            return true;
        }

        $this->error($operation.' refused because deployment environment requirements are incomplete.');

        foreach ($blocking as $resolved) {
            $secret = $resolved->definition->secret ? ' [secret]' : '';

            $this->line(sprintf(
                '  - %s (%s)%s: %s',
                $resolved->definition->key,
                $resolved->definition->scope,
                $secret,
                $resolved->requirement->reason,
            ));
        }

        $this->deploymentPreflightRecoveryGuidance();

        return false;
    }

    private function deploymentPreflightRecoveryGuidance(): void
    {
        $this->newLine();
        $this->line('No database changes were made.');
        $this->line('Inspect the full deployment plan:');
        $this->line('  php artisan engage:deployment-plan');
        $this->line('Add only missing required variable names when needed:');
        $this->line('  php artisan engage:environment:sync --write-missing');
    }
}
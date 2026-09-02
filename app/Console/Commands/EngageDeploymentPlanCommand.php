<?php

namespace App\Console\Commands;

use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use Illuminate\Console\Command;

final class EngageDeploymentPlanCommand extends Command
{
    protected $signature = 'engage:deployment-plan
        {--json : Emit the resolved deployment plan as JSON}';

    protected $description = 'Resolve the committed client/module deployment requirements without mutating runtime state.';

    public function handle(DeploymentPlanResolver $resolver): int
    {
        $plan = $resolver->resolve();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $plan->toArray(),
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_THROW_ON_ERROR,
            ));

            return $plan->ready() ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Engage Core deployment plan');
        $this->line('Environment: '.$plan->environment);
        $this->line('Client:      '.($plan->clientKey !== '' ? $plan->clientKey : '[none]'));
        $this->line('Modules:     '.implode(', ', $plan->enabledModules));
        $this->line('Coverage:    '.implode(', ', $plan->coveredOwners));
        $this->newLine();

        $verbose = (bool) $this->option('verbose');
        $visibleRequirements = $verbose
            ? $plan->environmentRequirements
            : array_values(array_filter(
                $plan->environmentRequirements,
                static fn (ResolvedEnvironmentRequirement $requirement): bool =>
                    $requirement->requirement->isRequired()
                    || $requirement->status === ResolvedEnvironmentRequirement::STATUS_READY,
            ));

        if ($visibleRequirements === []) {
            $this->line('No required environment values or active overrides were contributed.');
        } else {
            $this->table(
                ['Scope', 'Key', 'Need', 'Status', 'Secret', 'Target'],
                array_map(
                    static fn (ResolvedEnvironmentRequirement $requirement): array => [
                        $requirement->definition->scope,
                        $requirement->definition->key,
                        $requirement->requirement->requirement,
                        $requirement->status,
                        $requirement->definition->secret ? 'yes' : 'no',
                        $requirement->targetPath,
                    ],
                    $visibleRequirements,
                ),
            );
        }

        if (! $verbose) {
            $hiddenCount = count($plan->environmentRequirements) - count($visibleRequirements);

            if ($hiddenCount > 0) {
                $this->line(sprintf(
                    '%d inactive optional/defaulted requirement(s) hidden. Use [--verbose] to show the full environment matrix.',
                    $hiddenCount,
                ));
            }
        }

        if ($plan->unusedEnvironmentKeys !== []) {
            $this->newLine();
            $this->warn('Present but unused keys for currently covered deployment owners:');

            foreach ($plan->unusedEnvironmentKeys as $key) {
                $this->line('  - '.$key);
            }

            $this->line('No environment values were removed.');
        }

        $blocking = $plan->blockingEnvironmentRequirements();

        if ($blocking !== []) {
            $this->newLine();
            $this->error('Deployment requirements are incomplete.');

            foreach ($blocking as $requirement) {
                $secret = $requirement->definition->secret ? ' [secret]' : '';
                $allowed = $requirement->requirement->allowedValues !== []
                    ? ' Allowed: '.implode(', ', $requirement->requirement->allowedValues).'.'
                    : '';

                $this->line(sprintf(
                    '  - %s (%s)%s: %s%s',
                    $requirement->definition->key,
                    $requirement->definition->scope,
                    $secret,
                    $requirement->requirement->reason,
                    $allowed,
                ));
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Deployment environment requirements are satisfied.');

        return self::SUCCESS;
    }
}
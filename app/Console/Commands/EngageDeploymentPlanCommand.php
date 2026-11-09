<?php

namespace App\Console\Commands;

use App\Support\Deployment\Data\EnvironmentIntegrityFinding;
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
        $this->line('Selected:    '.implode(', ', $plan->selectedModules));
        $this->line('Known:       '.count($plan->knownModules).' module definitions');
        $this->line('Coverage:    '.implode(', ', $plan->coveredOwners));
        $this->newLine();

        $verbose = (bool) $this->option('verbose');
        $visibleRequirements = $verbose
            ? $plan->environmentRequirements
            : array_values(array_filter(
                $plan->environmentRequirements,
                static fn (ResolvedEnvironmentRequirement $requirement): bool =>
                    $requirement->requirement->isRequired()
                    || $requirement->status === ResolvedEnvironmentRequirement::STATUS_READY
                    || $requirement->blocksDeployment(),
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

        if ($plan->setupSteps !== []) {
            $this->newLine();
            $this->info('Operator/external setup steps for this deployment:');

            foreach ($plan->setupSteps as $resolvedStep) {
                $this->line(sprintf(
                    '  - [%s] %s',
                    $resolvedStep->owner,
                    $resolvedStep->step->title,
                ));
            }

            $this->line('Use [engage:deployment-plan --json] for the structured instructions, environment inputs, and verification checklist.');
        }

        $blocking = $plan->blockingEnvironmentRequirements();

        if (! $plan->ready()) {
            $this->newLine();
            $this->error('Deployment requirements are incomplete.');

            foreach ($plan->missingModuleDefinitions as $moduleKey) {
                $this->line(sprintf(
                    '  - Module [%s] is selected or required by a selected module but has no registered definition.',
                    $moduleKey,
                ));
            }

            foreach ($plan->environmentIntegrityFindings as $finding) {
                $description = match ($finding->code) {
                    EnvironmentIntegrityFinding::UNREGISTERED_REQUIREMENT,
                    EnvironmentIntegrityFinding::UNREGISTERED_SETUP_KEY
                        => 'Not registered in the selected-client environment catalog. Review and synchronize the owning package metadata before deployment.',
                    EnvironmentIntegrityFinding::WRONG_SCOPE
                        => sprintf('Found in [%s] but owned by [%s]; review its placement before deployment.', $finding->scope, $finding->expectedScope),
                    EnvironmentIntegrityFinding::DUPLICATE_KEY
                        => sprintf('Defined more than once in the [%s] environment%s; remove the duplicate before deployment.',
                            $finding->scope,
                            $finding->line !== null ? ' (second assignment at line '.$finding->line.')' : ''),
                    EnvironmentIntegrityFinding::MALFORMED_FILE
                        => sprintf('The [%s] environment file contains invalid dotenv syntax; correct it before deployment.', $finding->scope),
                    EnvironmentIntegrityFinding::UNREADABLE_FILE
                        => sprintf('The [%s] environment file could not be read; check file access before deployment.', $finding->scope),
                    default => 'Unresolved environment integrity finding.',
                };

                $this->line(sprintf(
                    '  - %s [%s] (owner: %s): %s',
                    $finding->key,
                    $finding->code,
                    $finding->owner,
                    $description,
                ));
            }

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
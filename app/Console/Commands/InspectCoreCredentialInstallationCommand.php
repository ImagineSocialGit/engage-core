<?php

namespace App\Console\Commands;

use App\Support\Deployment\Credentials\CoreCredentialInstallationContract;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Console\Command;
use Throwable;

/** Read-only recipient installation-target evidence; no secret issuance or delivery. */
final class InspectCoreCredentialInstallationCommand extends Command
{
    protected $signature = 'engage:credential-installation:inspect
        {purpose : Internal pairing purpose}
        {--source-platform= : Proposed peer platform}
        {--caller-id= : Proposed stable caller identity}
        {--source-origin= : Independently approved peer origin}
        {--expected-client= : Expected selected Core client}
        {--expected-environment= : Expected deployment environment}';

    protected $description = 'Inspect a package-owned internal credential installation target without writing secrets.';

    public function handle(
        DeploymentPlanResolver $plans,
        EnvironmentFileRepository $environmentFiles,
        CoreCredentialInstallationContract $installation,
    ): int {
        try {
            $clientFile = $environmentFiles->inspectScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
            $rootFile = $environmentFiles->inspectScope(EnvironmentVariableDefinition::SCOPE_ROOT);
            $purpose = (string) $this->argument('purpose');
            $result = $installation->inspect(
                purpose: $purpose,
                sourcePlatform: (string) $this->option('source-platform'),
                callerId: (string) $this->option('caller-id'),
                sourceOrigin: (string) $this->option('source-origin'),
                expectedClient: (string) $this->option('expected-client'),
                expectedEnvironment: (string) $this->option('expected-environment'),
                deploymentPlan: $plans->resolve()->toArray(),
                capabilityConfig: match ($purpose) {
                    'forms.external_intake' => config('forms.external_intake'),
                    'donations.external_checkout' => config('donations.external_checkout'),
                    default => null,
                },
                persistedClientEnvironment: $clientFile['values'],
                persistedRootEnvironment: $rootFile['values'],
                environmentIssues: array_merge($clientFile['issues'], $rootFile['issues']),
            );
        } catch (Throwable) {
            // Never print exception messages: parsers and configuration may
            // include sensitive file fragments or runtime credential material.
            $this->line((string) json_encode([
                'schema' => 1,
                'platform' => 'core',
                'status' => 'blocked',
                'findings' => ['installation_inspection_unavailable'],
                'installation_target_recognized' => false,
                'credential_equality_verified' => false,
                'delivery_allowed' => false,
                'writes_allowed' => false,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        return $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
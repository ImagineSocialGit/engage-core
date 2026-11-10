<?php

namespace App\Console\Commands;

use App\Support\Deployment\Credentials\CoreEndpointOriginInspector;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Console\Command;
use Throwable;

/** Read-only Core-owned endpoint configuration inspection; never an issuer. */
final class InspectCoreEndpointOriginCommand extends Command
{
    protected $signature = 'engage:credential-endpoint:inspect
        {--expected-client= : Expected selected Core client key}
        {--expected-environment= : Expected deployment environment}';

    protected $description = 'Inspect configured Core webhook origin without trusting a proposed pairing URL.';

    public function handle(
        DeploymentPlanResolver $plans,
        EnvironmentFileRepository $environmentFiles,
        CoreEndpointOriginInspector $inspector,
    ): int {
        try {
            $result = $inspector->inspect(
                expectedClient: (string) $this->option('expected-client'),
                expectedEnvironment: (string) $this->option('expected-environment'),
                deploymentPlan: $plans->resolve()->toArray(),
                clientEnvironment: $environmentFiles->valuesForScope(EnvironmentVariableDefinition::SCOPE_CLIENT),
                effectiveRootDomain: config('app.root_domain'),
                effectiveAppUrl: config('app.url'),
            );
        } catch (Throwable) {
            $this->line((string) json_encode([
                'schema' => 1,
                'platform' => 'core',
                'status' => 'blocked',
                'findings' => ['inspection_unavailable'],
                'configured_webhook_origin' => null,
                'webhook_routes_verified' => false,
                'network_routing_verified' => false,
                'runtime_attested' => false,
                'issuance_allowed' => false,
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
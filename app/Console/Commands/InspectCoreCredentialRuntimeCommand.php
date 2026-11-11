<?php

namespace App\Console\Commands;

use App\Support\Deployment\Credentials\CoreCredentialRuntimeProbe;
use App\Support\Deployment\Credentials\CoreEndpointOriginInspector;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Local source collectors use this console command; no HTTP route is added. */
final class InspectCoreCredentialRuntimeCommand extends Command
{
    protected $signature = 'engage:credential-runtime:inspect
        {purpose? : Package-owned internal connection purpose}
        {--inventory : List all package-owned runtime bindings without invoking credential consumers}
        {--source-platform= : Expected source platform}
        {--caller-id= : Expected caller identity}
        {--challenge= : Fresh controller-generated 32-byte hexadecimal nonce}
        {--expected-client= : Expected selected Core client}
        {--expected-environment= : Expected deployment environment}';

    protected $description = 'Inspect internal runtime bindings or prove a consumer uses its persisted credential.';

    public function handle(
        CoreCredentialRuntimeProbe $probe,
        DeploymentPlanResolver $plans,
        EnvironmentFileRepository $files,
        CoreEndpointOriginInspector $endpoints,
    ): int {
        try {
            $plan = $plans->resolve()->toArray();
            $client = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
            $root = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_ROOT);
            if ($client['issues'] !== [] || $root['issues'] !== []
                || app()->environment() !== $this->option('expected-environment')
            ) {
                throw new RuntimeException();
            }
            $endpoint = $endpoints->inspect(
                expectedClient: (string) $this->option('expected-client'),
                expectedEnvironment: (string) $this->option('expected-environment'),
                deploymentPlan: $plan,
                clientEnvironment: $client['values'],
                effectiveRootDomain: config('app.root_domain'),
                effectiveAppUrl: config('app.url'),
            );
            if (($endpoint['core_configuration_consistent'] ?? false) !== true) {
                throw new RuntimeException();
            }
            $origin = $endpoint['configured_webhook_origin'];
            if ($this->option('inventory')) {
                if ($this->argument('purpose') !== null || $this->option('challenge') !== null
                    || $this->option('caller-id') !== null || $this->option('source-platform') !== null
                ) {
                    throw new RuntimeException();
                }
                $result = $probe->inventory($plan, $origin);
            } else {
                $result = $probe->prove(
                    $plan, $origin, (string) $this->argument('purpose'),
                    (string) $this->option('source-platform'), (string) $this->option('caller-id'),
                    (string) $this->option('challenge'), $client['values'], $root['values'],
                );
            }
        } catch (Throwable) {
            $result = [
                'schema' => 1, 'platform' => 'core', 'status' => 'blocked',
                'findings' => ['runtime_inspection_unavailable'],
                'credential_equality_verified' => false,
                'network_verified' => false, 'writes_allowed' => false,
            ];
        }

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
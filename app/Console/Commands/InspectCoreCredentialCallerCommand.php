<?php

namespace App\Console\Commands;

use App\Support\Deployment\Credentials\CoreCredentialCallerContributor;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeContributor;
use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/** Package-owned metadata targets. Infrastructure must independently authorize writes. */
final class InspectCoreCredentialCallerCommand extends Command
{
    protected $signature = 'engage:credential-caller:inspect
        {purpose : Package-owned internal connection purpose}
        {--source-platform= : Selected peer platform}
        {--source-client= : Selected peer client key}
        {--source-origin= : Independently observed peer origin}
        {--expected-client= : Selected Core client}
        {--expected-environment= : Selected environment}';

    protected $description = 'Inspect package-declared caller metadata targets without changing client configuration.';

    public function handle(DeploymentPlanResolver $plans, EnvironmentFileRepository $files): int
    {
        try {
            $plan = $plans->resolve()->toArray();
            $client = (string) $this->option('expected-client');
            $environment = (string) $this->option('expected-environment');
            $purpose = (string) $this->argument('purpose');
            $platform = (string) $this->option('source-platform');
            $sourceClient = (string) $this->option('source-client');
            $origin = (string) $this->option('source-origin');
            if (($plan['client_key'] ?? null) !== $client || ($plan['environment'] ?? null) !== $environment
                || config('client.key') !== $client || app()->environment() !== $environment
                || ($plan['environment_integrity_findings'] ?? null) !== []
            ) {
                throw new RuntimeException();
            }
            $matches = [];
            foreach (app()->tagged(CoreCredentialRuntimeContributor::TAG) as $contributor) {
                if (! $contributor instanceof CoreCredentialCallerContributor) {
                    continue;
                }
                foreach ($contributor->callerCapabilities() as $capability) {
                    if (! is_array($capability) || count($capability) !== 2
                        || ! is_string($capability['purpose'] ?? null) || ! is_string($capability['source_platform'] ?? null)
                    ) {
                        throw new RuntimeException();
                    }
                    if ($capability['purpose'] === $purpose && $capability['source_platform'] === $platform) {
                        $matches[] = $contributor;
                    }
                }
            }
            if (count($matches) !== 1 || ! in_array($matches[0]->owner(), $plan['covered_owners'] ?? [], true)) {
                throw new RuntimeException();
            }
            $clientFile = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_CLIENT);
            $rootFile = $files->inspectScope(EnvironmentVariableDefinition::SCOPE_ROOT);
            $key = CoreInternalCallerRegistry::ENVIRONMENT_KEY;
            $definitions = array_values(array_filter($plan['environment_requirements'] ?? [],
                static fn (mixed $item): bool => is_array($item) && ($item['key'] ?? null) === $key));
            if (count($definitions) !== 1 || ($definitions[0]['owner'] ?? null) !== 'core'
                || ($definitions[0]['scope'] ?? null) !== 'client' || ($definitions[0]['secret'] ?? null) !== false
                || ($definitions[0]['requirement'] ?? null) !== 'optional'
                || ($definitions[0]['target_path'] ?? null) !== 'client/'.$client.'/.env'
            ) {
                throw new RuntimeException();
            }
            if ($clientFile['issues'] !== [] || $rootFile['issues'] !== [] || array_key_exists($key, $rootFile['values'])) {
                throw new RuntimeException();
            }
            $previous = $clientFile['values'][$key] ?? null;
            $registry = CoreInternalCallerRegistry::decode($previous, $client, $environment);
            $next = $registry->withCaller($purpose, $platform, $sourceClient, $origin);
            $matches[0]->validateCaller($purpose, $platform, $sourceClient, $origin);
            $desired = $next->encode();
            $result = [
                'schema' => 1, 'platform' => 'core', 'client_key' => $client, 'environment' => $environment,
                'purpose' => $purpose, 'source_platform' => $platform, 'source_client' => $sourceClient,
                'caller_id' => $sourceClient, 'source_origin' => $origin, 'owner' => $matches[0]->owner(),
                'status' => $previous === $desired ? 'caller_metadata_present' : 'caller_metadata_pending',
                // This value is canonical public metadata, NEVER credential material.
                'environment_key' => $key, 'environment_value' => $desired,
                'environment_owner' => 'core', 'environment_scope' => 'client', 'environment_secret' => false,
                'previous_environment_value' => $previous,
                'findings' => [], 'metadata_target_recognized' => true,
                'credential_equality_verified' => false, 'writes_allowed' => false,
            ];
        } catch (Throwable) {
            $result = [
                'schema' => 1, 'platform' => 'core', 'status' => 'blocked',
                'findings' => ['caller_metadata_inspection_unavailable'],
                'metadata_target_recognized' => false,
                'credential_equality_verified' => false, 'writes_allowed' => false,
            ];
        }

        $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
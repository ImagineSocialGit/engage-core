<?php

namespace App\Console\Commands;

use App\Support\Deployment\Credentials\CoreCredentialRecipientInspector;
use App\Support\Deployment\DeploymentPlanResolver;
use Illuminate\Console\Command;
use Throwable;

/** Internal read-only recipient evidence consumed by authorized Infrastructure review. */
final class InspectCoreCredentialRecipientCommand extends Command
{
    protected $signature = 'engage:credential-recipient:inspect
        {purpose : First-party pairing purpose}
        {--source-platform= : Proposed peer platform}
        {--caller-id= : Proposed stable caller identity}
        {--source-origin= : Approved candidate public origin (not a credential)}
        {--expected-client= : Expected selected Core client key}
        {--expected-environment= : Expected deployment environment}';

    protected $description = 'Inspect a selected Core signing recipient without reading out or issuing credentials.';

    public function handle(
        DeploymentPlanResolver $plans,
        CoreCredentialRecipientInspector $inspector,
    ): int {
        try {
            $purpose = (string) $this->argument('purpose');
            $result = $inspector->inspect(
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
            );
        } catch (Throwable) {
            // A malformed runtime/plan must not leak environment material or a
            // raw exception through an inspection endpoint.
            $this->line((string) json_encode([
                'schema' => 1,
                'platform' => 'core',
                'status' => 'blocked',
                'findings' => ['inspection_unavailable'],
                'issuance_allowed' => false,
                'writes_allowed' => false,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $this->line((string) json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));

        // A missing secret is expected before provisioning; it does not turn
        // a redacted, read-only review into an issuance authorization.
        return $result['status'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
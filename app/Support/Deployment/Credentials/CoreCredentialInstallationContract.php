<?php

namespace App\Support\Deployment\Credentials;

/**
 * Core-owned, read-only installation TARGET contract for first-party callers.
 *
 * A target is not an installation permit. Each capability owner must explicitly
 * consume the derived per-caller environment key before Infrastructure may
 * deliver a credential through its protected writer. Neither a selected module
 * nor a missing secret creates an authorized caller slot.
 */
final class CoreCredentialInstallationContract
{
    public function __construct(
        private readonly CoreCredentialRecipientInspector $recipients = new CoreCredentialRecipientInspector(),
    ) {}

    /**
     * @param array<string, mixed> $deploymentPlan Core's own deployment plan
     * @param mixed $capabilityConfig Effective capability configuration
     * @param array<string, mixed> $persistedClientEnvironment Parsed client-scope .env only
     * @return array<string, mixed> Redacted candidate assignment; never grants writes
     */
    public function inspect(
        string $purpose,
        string $sourcePlatform,
        string $callerId,
        string $sourceOrigin,
        string $expectedClient,
        string $expectedEnvironment,
        array $deploymentPlan,
        mixed $capabilityConfig,
        array $persistedClientEnvironment,
        array $persistedRootEnvironment = [],
        array $environmentIssues = [],
    ): array {
        $recipient = $this->recipients->inspect(
            purpose: $purpose,
            sourcePlatform: $sourcePlatform,
            callerId: $callerId,
            sourceOrigin: $sourceOrigin,
            expectedClient: $expectedClient,
            expectedEnvironment: $expectedEnvironment,
            deploymentPlan: $deploymentPlan,
            capabilityConfig: $capabilityConfig,
            persistedClientEnvironment: $persistedClientEnvironment,
            persistedRootEnvironment: $persistedRootEnvironment,
            environmentIssues: $environmentIssues,
        );
        $findings = $recipient['findings'];
        $status = $recipient['status'];
        $candidateKey = null;
        $bindingDeclared = false;
        $ownerRequirementVerified = false;

        if ($status === 'needs_provisioning' || $status === 'configured_unverified') {
            if (! $recipient['caller_slot_present']) {
                $findings[] = 'caller_metadata_not_declared';
            } elseif (! is_array($capabilityConfig)
                || ! is_array($capabilityConfig['clients'] ?? null)
                || ! is_array($capabilityConfig['clients'][$callerId] ?? null)
            ) {
                $findings[] = 'caller_slot_invalid';
            } else {
                $slot = $capabilityConfig['clients'][$callerId];
                $candidateKey = $this->keyFor($purpose, $sourcePlatform, $callerId);

                // A module-owned caller slot must refer to the one derived
                // selected-client key; an inline secret is never a binding.
                $declared = $slot['secret_env_key'] ?? null;
                $hasCandidateValue = $candidateKey !== null
                    && array_key_exists($candidateKey, $persistedClientEnvironment);
                if ($declared === null) {
                    $findings[] = 'owner_runtime_binding_not_declared';
                } elseif (! is_string($declared) || $declared !== $candidateKey) {
                    $findings[] = 'owner_runtime_binding_mismatch';
                } else {
                    $bindingDeclared = true;
                }
                if ($recipient['secret_present']) {
                    $status = 'existing_secret_unverified';
                } elseif ($hasCandidateValue) {
                    $findings[] = 'existing_internal_credential_invalid';
                }
            }
        }

        // No purpose-specific entitlement. The selected owner must contribute
        // the exact required per-caller client-scoped secret in its resolved plan.
        if ($candidateKey !== null && $bindingDeclared && $findings === []) {
            $owner = explode('.', $purpose, 2)[0];
            $ownerRequirementVerified = $this->ownerRequirementMatches(
                $deploymentPlan, $owner, $candidateKey, $expectedClient,
                array_key_exists($candidateKey, $persistedClientEnvironment),
            );
            if (! $ownerRequirementVerified) {
                $findings[] = 'owner_environment_requirement_invalid';
            }
        }

        $findings = array_values(array_unique($findings));
        if ($findings !== []) {
            $status = 'blocked';
        } elseif ($status === 'needs_provisioning') {
            $status = $ownerRequirementVerified
                ? 'pending_delivery'
                : 'pending_owner_runtime_support';
        }

        // Runtime load and credential equality are separate post-delivery
        // checks. A recognized target never grants permission to write.
        return [
            'schema' => 1,
            'platform' => 'core',
            'client_key' => $recipient['client_key'],
            'environment' => $recipient['environment'],
            'purpose' => $recipient['purpose'],
            'source_platform' => $recipient['source_platform'],
            'caller_id' => $recipient['caller_id'],
            'status' => $status,
            'recipient_status' => $recipient['status'],
            'candidate_client_environment_key' => $status === 'blocked' || $status === 'not_required'
                ? null : $candidateKey,
            'caller_slot_declared' => (bool) $recipient['caller_slot_present'],
            'owner_binding_declared' => $bindingDeclared,
            'owner_requirement_verified' => $status !== 'blocked' && $ownerRequirementVerified,
            'installation_target_recognized' => $status !== 'blocked' && $ownerRequirementVerified,
            'findings' => $findings,
            'runtime_binding_verified' => false,
            'credential_equality_verified' => false,
            'delivery_allowed' => false,
            'writes_allowed' => false,
        ];
    }

    /**
     * Confirm the selected Forms/Donations owner contributed its exact required
     * client-scoped secret definition, not merely static catalog entries or a
     * proposed unregistered environment key.
     *
     * @param array<string, mixed> $deploymentPlan
     */
    private function ownerRequirementMatches(
        array $deploymentPlan,
        string $owner,
        string $key,
        string $client,
        bool $persisted,
    ): bool {
        $requirements = $deploymentPlan['environment_requirements'] ?? null;
        if (! is_array($requirements) || ! array_is_list($requirements)) {
            return false;
        }

        $matches = array_values(array_filter(
            $requirements,
            static fn (mixed $item): bool => is_array($item)
                && ($item['key'] ?? null) === $key,
        ));
        if (count($matches) !== 1) {
            return false;
        }

        $definition = $matches[0];
        return ($definition['owner'] ?? null) === $owner
            && ($definition['scope'] ?? null) === 'client'
            && ($definition['secret'] ?? null) === true
            && ($definition['requirement'] ?? null) === 'required'
            && ($definition['target_path'] ?? null) === 'client/'.$client.'/.env'
            && ($definition['persisted'] ?? null) === $persisted
            && ($definition['status'] ?? null) === ($persisted ? 'ready' : 'missing');
    }

    /**
     * Candidate key for a module-owned secret binding. Callers must never
     * use this derivation alone as authorization to write the environment.
     */
    public function keyFor(string $purpose, string $sourcePlatform, string $callerId): ?string
    {
        return CoreCredentialCallerKey::forCaller($purpose, $sourcePlatform, $callerId);
    }
}
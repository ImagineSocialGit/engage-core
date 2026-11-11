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

                // Never infer a package's runtime consumer from a candidate
                // key alone. Donations declares one exact per-caller binding;
                // Forms is still waiting for its owner-owned consumer.
                $declared = $slot['secret_env_key'] ?? null;
                $hasCandidateValue = $candidateKey !== null
                    && array_key_exists($candidateKey, $persistedClientEnvironment);

                if ($recipient['secret_present']) {
                    $status = 'existing_secret_unverified';
                    if ($purpose !== 'donations.external_checkout') {
                        $candidateKey = null;
                    }
                    // Donations' package consumer (B15) reads the selected-client
                    // per-caller key, not an inline 'secret' value.
                    if ($purpose === 'donations.external_checkout') {
                        $bindingDeclared = $declared === $this->keyFor($purpose, $sourcePlatform, $callerId);
                    } else {
                        if ($declared !== null) {
                            $findings[] = 'existing_secret_binding_requires_reconciliation';
                        }
                        if ($hasCandidateValue) {
                            $findings[] = 'coexisting_internal_credential_requires_reconciliation';
                        }
                    }
                } else {
                    // New slots must declare their exact per-caller key.
                    // A computed key by itself is not a consumer binding.
                    if ($declared === null) {
                        $findings[] = 'owner_runtime_binding_not_declared';
                    } elseif (! is_string($declared) || $declared !== $candidateKey) {
                        $findings[] = 'owner_runtime_binding_mismatch';
                    } else {
                        $bindingDeclared = true;
                    }

                    if ($hasCandidateValue) {
                        $value = $persistedClientEnvironment[$candidateKey];
                        if (! is_string($value)
                            || preg_match('/\A[A-Za-z0-9_-]{64}\z/D', $value) !== 1
                        ) {
                            $findings[] = 'existing_internal_credential_invalid';
                        } else {
                            // Syntax validity is not credential equality or
                            // evidence that the PHP consumer loaded the key.
                            $status = 'existing_secret_unverified';
                        }
                    }
                }
            }
        }

        // B15 supplies Donations' actual runtime consumer and B18 supplies
        // its active package-owned requirement. An installation target is
        // recognized only when BOTH agree with the selected caller slot and
        // Core's resolved deployment plan. This is still read-only evidence,
        // not approval for Infrastructure to write into a recipient runtime.
        if ($purpose === 'donations.external_checkout'
            && $candidateKey !== null
            && $bindingDeclared
            && $findings === []
        ) {
            $ownerRequirementVerified = $this->donationsRequirementMatches(
                $deploymentPlan,
                $candidateKey,
                $expectedClient,
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
     * Confirm the active Donations package contributed its exact required
     * client-scoped secret definition for this caller, not merely the legacy
     * static catalog ownership entries or an operator-proposed key.
     *
     * @param array<string, mixed> $deploymentPlan
     */
    private function donationsRequirementMatches(
        array $deploymentPlan,
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
        return ($definition['owner'] ?? null) === 'donations'
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
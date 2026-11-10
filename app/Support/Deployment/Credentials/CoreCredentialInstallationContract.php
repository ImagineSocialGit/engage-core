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
    /**
     * These namespaces describe Core's existing Forms/Donations recipient
     * keys, not feature implementations or a credential issuance registry.
     * Each feature owner is responsible for its eventual runtime binding.
     */
    private const PURPOSES = [
        'forms.external_intake' => [
            'prefix' => 'FORMS_EXTERNAL_INTAKE_CALLER_',
            'suffix' => '_SECRET',
        ],
        'donations.external_checkout' => [
            'prefix' => 'DONATIONS_EXTERNAL_CHECKOUT_CALLER_',
            'suffix' => '_SIGNING_SECRET',
        ],
    ];

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
        );
        $findings = $recipient['findings'];
        $status = $recipient['status'];
        $candidateKey = null;
        $bindingDeclared = false;

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

                // Existing explicit/legacy client secrets retain their current
                // runtime representation. Never force a migration or assume
                // the controller store owns that material.
                $declared = $slot['secret_env_key'] ?? null;
                $hasCandidateValue = $candidateKey !== null
                    && array_key_exists($candidateKey, $persistedClientEnvironment);

                if ($recipient['secret_present']) {
                    $status = 'existing_secret_unverified';
                    $candidateKey = null;
                    if ($declared !== null) {
                        $findings[] = 'existing_secret_binding_requires_reconciliation';
                    }
                    if ($hasCandidateValue) {
                        $findings[] = 'coexisting_internal_credential_requires_reconciliation';
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

        $findings = array_values(array_unique($findings));
        if ($findings !== []) {
            $status = 'blocked';
        } elseif ($status === 'needs_provisioning') {
            $status = 'pending_owner_runtime_support';
        }

        // The contract intentionally does not expose an install-ready flag:
        // capability consumers must first implement and verify the binding.
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
            'findings' => $findings,
            'runtime_binding_verified' => false,
            'credential_equality_verified' => false,
            'delivery_allowed' => false,
            'writes_allowed' => false,
        ];
    }

    /**
     * Candidate key for a module-owned secret binding. Callers must never
     * use this derivation alone as authorization to write the environment.
     */
    public function keyFor(string $purpose, string $sourcePlatform, string $callerId): ?string
    {
        $route = self::PURPOSES[$purpose] ?? null;
        if ($route === null || ! in_array($sourcePlatform, ['seo', 'artist'], true)
            || ($purpose === 'donations.external_checkout' && $sourcePlatform !== 'seo')
            || preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $callerId) !== 1
        ) {
            return null;
        }

        // Fixed-length digest avoids collisions from replacing dots/hyphens
        // and never leaks the caller identity through an env variable name.
        $digest = strtoupper(substr(hash('sha256', implode("\0", [
            'engage-core-recipient-v1', $purpose, $sourcePlatform, $callerId,
        ])), 0, 32));

        return $route['prefix'].$digest.$route['suffix'];
    }
}
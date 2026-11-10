<?php

namespace App\Support\Deployment\Credentials;

/**
 * Read-only Core-side recipient evidence for a proposed first-party signing pair.
 *
 * Effective selected-client configuration is evidence of a caller slot, NOT
 * proof that its peer is authorized or that both sides hold the same secret.
 * Neither a missing slot nor a present secret authorizes a write.
 */
final class CoreCredentialRecipientInspector
{
    private const ROUTES = [
        'forms.external_intake' => [
            'module' => 'forms',
            'owner' => 'forms',
            'source_platforms' => ['seo', 'artist'],
            'identity_key' => 'FORMS_EXTERNAL_INTAKE_CLIENT_ID',
            'secret_key' => 'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET',
        ],
        'donations.external_checkout' => [
            'module' => 'donations',
            'owner' => 'donations',
            'source_platforms' => ['seo'],
            'identity_key' => 'DONATIONS_EXTERNAL_CHECKOUT_CLIENT_ID',
            'secret_key' => 'DONATIONS_EXTERNAL_CHECKOUT_SIGNING_SECRET',
        ],
    ];

    /**
     * @param array<string, mixed> $deploymentPlan Existing Core deployment-plan JSON shape
     * @param mixed $capabilityConfig Effective selected-client Forms/Donations configuration
     * @return array<string, mixed> Redacted evidence; never an issuance authorization
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
    ): array {
        $findings = [];
        $route = self::ROUTES[$purpose] ?? null;

        if ($route === null || ! in_array($sourcePlatform, $route['source_platforms'], true)) {
            $findings[] = 'unsupported_pairing_route';
        }

        if (preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $callerId) !== 1) {
            $findings[] = 'invalid_caller_identity';
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $expectedClient) !== 1
            || $expectedClient !== ($deploymentPlan['client_key'] ?? null)
            || ! in_array($expectedEnvironment, ['local', 'staging', 'production'], true)
            || $expectedEnvironment !== ($deploymentPlan['environment'] ?? null)
        ) {
            $findings[] = 'target_identity_mismatch';
        }

        if (! $this->validOrigin($sourceOrigin, $expectedEnvironment)) {
            $findings[] = 'invalid_source_origin';
        }

        $selected = false;
        $slotPresent = false;
        $secretPresent = false;
        $originMatch = null;

        if ($route !== null && in_array($sourcePlatform, $route['source_platforms'], true)) {
            $selection = $deploymentPlan['module_selection'] ?? null;
            $selectedModules = is_array($selection) ? ($selection['selected_modules'] ?? null) : null;
            $knownModules = is_array($selection) ? ($selection['known_modules'] ?? null) : null;
            $missing = is_array($selection) ? ($selection['missing_module_definitions'] ?? null) : null;
            $enabledModules = $deploymentPlan['enabled_modules'] ?? null;
            $integrity = $deploymentPlan['environment_integrity_findings'] ?? null;
            $owners = $deploymentPlan['covered_owners'] ?? null;

            if (! is_array($selectedModules) || ! is_array($knownModules) || ! is_array($enabledModules)
                || ! is_array($missing) || $missing !== []
                || ! is_array($integrity) || $integrity !== []
            ) {
                $findings[] = 'deployment_evidence_incomplete';
            } elseif (in_array($route['module'], $enabledModules, true)) {
                if (! in_array($route['module'], $knownModules, true)
                    || ! is_array($owners)
                    || ! in_array($route['owner'], $owners, true)
                    || ! $this->validKeyDefinitions($deploymentPlan, $route)
                ) {
                    $findings[] = 'module_or_environment_contract_mismatch';
                } else {
                    $selected = true;
                }
            }

            if ($selected) {
                if (! is_array($capabilityConfig) || ! array_key_exists('enabled', $capabilityConfig)
                    || ! is_bool($capabilityConfig['enabled'])
                ) {
                    $findings[] = 'capability_selection_invalid';
                } elseif ($capabilityConfig['enabled']) {
                    $slots = $capabilityConfig['clients'] ?? null;

                    if (! is_array($slots)) {
                        $findings[] = 'caller_slots_invalid';
                    } elseif (array_key_exists($callerId, $slots)) {
                        $slotPresent = true;
                        $slot = $slots[$callerId];

                        if (! is_array($slot)) {
                            $findings[] = 'caller_slot_invalid';
                        } else {
                            $secret = $slot['secret'] ?? null;
                            $secretPresent = is_string($secret) && strlen($secret) >= 32
                                && ($purpose !== 'forms.external_intake' || strlen($secret) <= 4096);

                            if ($purpose === 'donations.external_checkout') {
                                $configuredOrigin = $slot['site_origin'] ?? null;
                                $originMatch = $this->validOrigin($configuredOrigin, $expectedEnvironment)
                                    && $configuredOrigin === $sourceOrigin;

                                if (! $originMatch) {
                                    $findings[] = 'caller_origin_mismatch';
                                }
                            } elseif (! $this->validFormsCallerMetadata($slot)) {
                                $findings[] = 'caller_metadata_invalid';
                            }
                        }
                    }

                    // An approved provisioning operation must create missing slots.
                    // Presence of a capability alone does not authorize the writer.
                } else {
                    $selected = false;
                }
            }
        }

        $findings = array_values(array_unique($findings));
        $status = match (true) {
            $findings !== [] => 'blocked',
            ! $selected => 'not_required',
            ! $slotPresent || ! $secretPresent => 'needs_provisioning',
            default => 'configured_unverified',
        };

        return [
            'schema' => 1,
            'platform' => 'core',
            'client_key' => preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $expectedClient) === 1 ? $expectedClient : null,
            'environment' => in_array($expectedEnvironment, ['local', 'staging', 'production'], true) ? $expectedEnvironment : null,
            'purpose' => $route !== null ? $purpose : null,
            'source_platform' => in_array($sourcePlatform, ['seo', 'artist'], true) ? $sourcePlatform : null,
            'caller_id' => preg_match('/\A[a-z][a-z0-9_.-]{0,127}\z/D', $callerId) === 1 ? $callerId : null,
            'status' => $status,
            'capability_selected' => $selected,
            'caller_slot_present' => $slotPresent,
            'secret_present' => $secretPresent,
            'source_origin_match' => $originMatch,
            'findings' => $findings,
            'peer_verified' => false,
            'credential_match_verified' => false,
            'authorization_verified' => false,
            'issuance_allowed' => false,
            'writes_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $plan @param array<string, mixed> $route */
    private function validKeyDefinitions(array $plan, array $route): bool
    {
        $requirements = $plan['environment_requirements'] ?? null;

        if (! is_array($requirements)) {
            return false;
        }

        foreach ([$route['identity_key'] => false, $route['secret_key'] => true] as $key => $secret) {
            $matches = array_values(array_filter(
                $requirements,
                static fn (mixed $value): bool => is_array($value) && ($value['key'] ?? null) === $key,
            ));

            if (count($matches) !== 1
                || ($matches[0]['owner'] ?? null) !== $route['owner']
                || ($matches[0]['scope'] ?? null) !== 'client'
                || ($matches[0]['secret'] ?? null) !== $secret
            ) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $slot */
    private function validFormsCallerMetadata(array $slot): bool
    {
        foreach (['source', 'provider'] as $key) {
            if (! is_string($slot[$key] ?? null)
                || trim($slot[$key]) === '' || strlen($slot[$key]) > 255
            ) {
                return false;
            }
        }

        $forms = $slot['allowed_forms'] ?? null;

        if (! is_array($forms) || $forms === [] || ! array_is_list($forms)) {
            return false;
        }

        foreach ($forms as $form) {
            if (! is_string($form) || preg_match('/\A[a-z][a-z0-9_]*\z/D', $form) !== 1) {
                return false;
            }
        }

        return count(array_unique($forms)) === count($forms);
    }

    private function validOrigin(mixed $value, string $environment): bool
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value) {
            return false;
        }

        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, $environment === 'local' ? ['http', 'https'] : ['https'], true)
            || ! is_string($parts['host'] ?? null) || $parts['host'] === ''
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || str_ends_with($value, '/')
        ) {
            return false;
        }

        foreach (['user', 'pass', 'query', 'fragment'] as $part) {
            if (array_key_exists($part, $parts)) {
                return false;
            }
        }

        return true;
    }
}
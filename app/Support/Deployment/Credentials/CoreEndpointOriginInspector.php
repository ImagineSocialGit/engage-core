<?php

namespace App\Support\Deployment\Credentials;

/**
 * Read-only Core-owned origin configuration evidence.
 *
 * The webhook origin is derived ONLY from the effective selected Core runtime
 * and the persisted selected-client environment. An operator-supplied pairing
 * URL must never become evidence of Core endpoint ownership. This inspector
 * does not verify DNS, web-server configuration, route installation or peer
 * identity, and therefore does not authorize credential issuance.
 */
final class CoreEndpointOriginInspector
{
    private const REQUIRED_KEYS = ['ROOT_DOMAIN', 'APP_URL', 'CRM_APP_URL'];

    /**
     * @param array<string, mixed> $deploymentPlan Resolved Core deployment-plan JSON
     * @param array<string, mixed> $clientEnvironment Persisted selected-client values
     * @return array<string, mixed> Non-secret, non-authorizing configured-origin report
     */
    public function inspect(
        string $expectedClient,
        string $expectedEnvironment,
        array $deploymentPlan,
        array $clientEnvironment,
        mixed $effectiveRootDomain,
        mixed $effectiveAppUrl,
    ): array {
        $findings = [];
        $clientValid = preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $expectedClient) === 1;
        $environmentValid = in_array($expectedEnvironment, ['local', 'staging', 'production'], true);

        if (! $clientValid || ! $environmentValid
            || ($deploymentPlan['client_key'] ?? null) !== $expectedClient
            || ($deploymentPlan['environment'] ?? null) !== $expectedEnvironment
        ) {
            $findings[] = 'target_identity_mismatch';
        }

        $selection = $deploymentPlan['module_selection'] ?? null;
        $integrity = $deploymentPlan['environment_integrity_findings'] ?? null;
        if (! is_array($selection)
            || ! is_array($selection['selected_modules'] ?? null)
            || ! is_array($selection['known_modules'] ?? null)
            || ! is_array($selection['missing_module_definitions'] ?? null)
            || $selection['missing_module_definitions'] !== []
            || ! is_array($integrity)
            || $integrity !== []
        ) {
            $findings[] = 'deployment_integrity_unresolved';
        }

        if (! $this->validCoreRequirements($deploymentPlan)) {
            $findings[] = 'core_origin_contract_unresolved';
        }

        $rootDomain = $clientEnvironment['ROOT_DOMAIN'] ?? null;
        $appUrl = $clientEnvironment['APP_URL'] ?? null;
        $crmUrl = $clientEnvironment['CRM_APP_URL'] ?? null;
        $validDomain = $this->validRootDomain($rootDomain, $expectedEnvironment);
        if (! $validDomain || ! is_string($effectiveRootDomain)
            || $effectiveRootDomain !== $rootDomain
        ) {
            $findings[] = 'effective_root_domain_mismatch';
        }

        $appOrigin = $this->originParts($appUrl, $expectedEnvironment);
        $crmOrigin = $this->originParts($crmUrl, $expectedEnvironment);
        if ($appOrigin === null || $crmOrigin === null
            || ! is_string($effectiveAppUrl) || $effectiveAppUrl !== $appUrl
        ) {
            $findings[] = 'effective_application_origin_mismatch';
        } elseif ($validDomain) {
            $expectedCrmHost = 'crm.'.$rootDomain;
            if ($crmOrigin['host'] !== $expectedCrmHost
                || ($appOrigin['host'] !== $rootDomain
                    && ! str_ends_with($appOrigin['host'], '.'.$rootDomain))
                || $appOrigin['scheme'] !== $crmOrigin['scheme']
            ) {
                $findings[] = 'core_origin_host_or_scheme_mismatch';
            }
        }

        // The webhook host follows the established Core subdomain contract,
        // but neither that convention nor a valid .env proves routing/network.
        $origin = $findings === [] && $validDomain && $appOrigin !== null
            ? $appOrigin['scheme'].'://webhooks.'.$rootDomain
            : null;
        $findings = array_values(array_unique($findings));

        return [
            'schema' => 1,
            'platform' => 'core',
            'client_key' => $clientValid ? $expectedClient : null,
            'environment' => $environmentValid ? $expectedEnvironment : null,
            'status' => $findings === [] ? 'configured_unverified' : 'blocked',
            'configured_webhook_origin' => $origin,
            'origin_evidence' => $origin !== null ? 'effective_and_persisted_selected_client' : null,
            'findings' => $findings,
            'core_configuration_consistent' => $origin !== null,
            'webhook_routes_verified' => false,
            'network_routing_verified' => false,
            'runtime_attested' => false,
            'issuance_allowed' => false,
            'writes_allowed' => false,
        ];
    }

    /** @param array<string, mixed> $plan */
    private function validCoreRequirements(array $plan): bool
    {
        $requirements = $plan['environment_requirements'] ?? null;
        if (! is_array($requirements)) {
            return false;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            $matches = array_values(array_filter(
                $requirements,
                static fn (mixed $item): bool => is_array($item) && ($item['key'] ?? null) === $key,
            ));

            if (count($matches) !== 1
                || ($matches[0]['scope'] ?? null) !== 'client'
                || ($matches[0]['owner'] ?? null) !== 'core'
                || ($matches[0]['secret'] ?? null) !== false
                || ($matches[0]['requirement'] ?? null) !== 'required'
                || ($matches[0]['status'] ?? null) !== 'ready'
                || ($matches[0]['persisted'] ?? null) !== true
            ) {
                return false;
            }
        }

        return true;
    }

    private function validRootDomain(mixed $value, string $environment): bool
    {
        if (! is_string($value) || $value === '' || strlen($value) > 240
            || $value !== strtolower($value) || ! str_contains($value, '.')
        ) {
            return false;
        }

        foreach (explode('.', $value) as $part) {
            if (strlen($part) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $part) !== 1
            ) {
                return false;
            }
        }

        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($environment !== 'local'
            && preg_match('/\.(?:test|example|invalid|localhost)\z/D', $value) === 1
        ) {
            return false;
        }

        return true;
    }

    /** @return array{scheme: string, host: string}|null */
    private function originParts(mixed $value, string $environment): ?array
    {
        if (! is_string($value) || $value === '' || trim($value) !== $value
            || str_ends_with($value, '/')
        ) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, $environment === 'local' ? ['http', 'https'] : ['https'], true)
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['path'])
        ) {
            return null;
        }

        return ['scheme' => $parts['scheme'], 'host' => $parts['host']];
    }
}
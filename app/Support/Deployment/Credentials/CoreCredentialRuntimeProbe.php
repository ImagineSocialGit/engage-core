<?php

namespace App\Support\Deployment\Credentials;

use RuntimeException;
use Throwable;

/** Read-only runtime-consumer evidence. The controller must verify every MAC. */
final class CoreCredentialRuntimeProbe
{
    /** @param iterable<CoreCredentialRuntimeContributor> $contributors */
    public function __construct(private readonly iterable $contributors) {}

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    public function inventory(array $plan, string $destinationOrigin): array
    {
        try {
            $bindings = $this->bindings($plan, $destinationOrigin);

            return [
                ...$this->envelope($plan, $destinationOrigin),
                'status' => 'runtime_bindings_declared',
                'bindings' => array_map(static fn (CoreCredentialRuntimeBinding $binding): array => $binding->metadata(), $bindings),
            ];
        } catch (Throwable) {
            throw new RuntimeException('credential_runtime_inventory_failed');
        }
    }

    /**
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $clientEnvironment
     * @param array<string, mixed> $rootEnvironment
     * @return array<string, mixed>
     */
    public function prove(
        array $plan,
        string $destinationOrigin,
        string $purpose,
        string $sourcePlatform,
        string $callerId,
        string $challenge,
        array $clientEnvironment,
        array $rootEnvironment = [],
    ): array {
        try {
            if (preg_match('/\A[0-9a-f]{64}\z/D', $challenge) !== 1) {
                throw new RuntimeException();
            }
            $matches = array_values(array_filter(
                $this->bindings($plan, $destinationOrigin),
                static fn (CoreCredentialRuntimeBinding $binding): bool => $binding->purpose === $purpose
                    && $binding->sourcePlatform === $sourcePlatform && $binding->callerId === $callerId,
            ));
            if (count($matches) !== 1) {
                throw new RuntimeException();
            }
            $binding = $matches[0];
            $persisted = $clientEnvironment[$binding->secretEnvironmentKey] ?? null;
            if (array_key_exists($binding->secretEnvironmentKey, $rootEnvironment)
                || ! is_string($persisted)
                || preg_match('/\A[A-Za-z0-9_-]{64}\z/D', $persisted) !== 1
            ) {
                throw new RuntimeException();
            }
            // The enabled owner supplies the SAME resolver used by authentication.
            // A file read alone, cached old value, or a different caller cannot prove it.
            $loaded = $binding->resolveSecret();
            if (! is_string($loaded) || ! hash_equals($persisted, $loaded)) {
                throw new RuntimeException();
            }
            $canonical = implode("\n", [
                'engage-internal-runtime-v1', $challenge, 'core',
                $plan['client_key'], $plan['environment'], $purpose,
                $sourcePlatform, $callerId, $binding->sourceOrigin, $destinationOrigin,
            ]);

            return [
                ...$this->envelope($plan, $destinationOrigin),
                'status' => 'runtime_binding_proven',
                'purpose' => $purpose,
                'peer_platform' => $sourcePlatform,
                'caller_id' => $callerId,
                'source_origin' => $binding->sourceOrigin,
                'challenge' => $challenge,
                'proof' => hash_hmac('sha256', $canonical, $loaded),
            ];
        } catch (Throwable) {
            throw new RuntimeException('credential_runtime_verification_failed');
        }
    }

    /** @param array<string, mixed> $plan @return list<CoreCredentialRuntimeBinding> */
    private function bindings(array $plan, string $destinationOrigin): array
    {
        $client = $plan['client_key'] ?? null;
        $environment = $plan['environment'] ?? null;
        if (! is_string($client) || preg_match('/\A[a-z][a-z0-9_-]{0,127}\z/D', $client) !== 1
            || ! in_array($environment, ['local', 'staging', 'production'], true)
            || ! $this->validOrigin($destinationOrigin, $environment)
            || ($plan['environment_integrity_findings'] ?? null) !== []
            || ! is_array($plan['covered_owners'] ?? null)
            || ! is_array($plan['environment_requirements'] ?? null)
        ) {
            throw new RuntimeException();
        }

        $result = [];
        $keys = [];
        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof CoreCredentialRuntimeContributor) {
                throw new RuntimeException();
            }
            foreach ($contributor->runtimeBindings() as $binding) {
                if (! $binding instanceof CoreCredentialRuntimeBinding
                    || $binding->owner !== $contributor->owner()
                    || ! in_array($binding->owner, $plan['covered_owners'], true)
                    || ! $this->validOrigin($binding->sourceOrigin, $environment)
                ) {
                    throw new RuntimeException();
                }
                $requirements = array_values(array_filter(
                    $plan['environment_requirements'],
                    static fn (mixed $item): bool => is_array($item)
                        && ($item['key'] ?? null) === $binding->secretEnvironmentKey,
                ));
                if (count($requirements) !== 1
                    || ($requirements[0]['owner'] ?? null) !== $binding->owner
                    || ($requirements[0]['scope'] ?? null) !== 'client'
                    || ($requirements[0]['secret'] ?? null) !== true
                    || ($requirements[0]['requirement'] ?? null) !== 'required'
                    || ($requirements[0]['target_path'] ?? null) !== 'client/'.$client.'/.env'
                ) {
                    throw new RuntimeException();
                }
                $identity = implode("\0", [$binding->purpose, $binding->sourcePlatform, $binding->callerId]);
                if (isset($result[$identity]) || isset($keys[$binding->secretEnvironmentKey])) {
                    throw new RuntimeException();
                }
                $result[$identity] = $binding;
                $keys[$binding->secretEnvironmentKey] = true;
            }
        }
        ksort($result, SORT_STRING);

        return array_values($result);
    }

    /** @param array<string, mixed> $plan @return array<string, mixed> */
    private function envelope(array $plan, string $destinationOrigin): array
    {
        return [
            'schema' => 1,
            'platform' => 'core',
            'client_key' => $plan['client_key'],
            'environment' => $plan['environment'],
            'destination_origin' => $destinationOrigin,
            'proof_protocol' => 'engage-internal-runtime-v1',
            'credential_equality_verified' => false,
            'network_verified' => false,
            'writes_allowed' => false,
        ];
    }

    private function validOrigin(string $origin, string $environment): bool
    {
        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, $environment === 'local' ? ['http', 'https'] : ['https'], true)
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && filter_var($parts['host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts)) === []
            && ! str_contains($origin, '\\') && trim($origin) === $origin;
    }
}
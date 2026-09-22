<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceProvider;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class CommerceProviderRegistry
{
    public const PROVIDER_TAG = 'commerce.providers';

    /** @var array<string, CommerceProvider>|null */
    private ?array $resolved = null;

    /** @param iterable<int, CommerceProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /** @return array<string, CommerceProvider> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof CommerceProvider) {
                throw new InvalidArgumentException(
                    'Tagged Commerce providers must implement '.CommerceProvider::class.'.',
                );
            }

            $key = trim($provider->key());

            if ($key === '') {
                throw new InvalidArgumentException('Commerce provider keys cannot be empty.');
            }

            if (array_key_exists($key, $resolved)) {
                throw new LogicException("Duplicate Commerce provider key [{$key}].");
            }

            $resolved[$key] = $provider;
        }

        ksort($resolved);

        return $this->resolved = $resolved;
    }

    public function has(string $key): bool
    {
        return array_key_exists(trim($key), $this->all());
    }

    public function get(string $key): CommerceProvider
    {
        $key = trim($key);
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof CommerceProvider) {
            throw new RuntimeException("Commerce provider [{$key}] is not registered.");
        }

        return $provider;
    }
}
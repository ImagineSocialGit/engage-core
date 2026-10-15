<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingCollectionProvider;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class BillingCollectionProviderRegistry
{
    public const PROVIDER_TAG = 'billing.collection_providers';

    /** @var array<string, BillingCollectionProvider>|null */
    private ?array $resolved = null;

    /** @param iterable<int, BillingCollectionProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /** @return array<string, BillingCollectionProvider> */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->providers as $provider) {
            if (! $provider instanceof BillingCollectionProvider) {
                throw new InvalidArgumentException(
                    'Tagged Billing collection providers must implement '
                    .BillingCollectionProvider::class.'.',
                );
            }

            $key = trim($provider->key());

            if ($key === '') {
                throw new InvalidArgumentException(
                    'Billing collection provider keys cannot be empty.',
                );
            }

            if (array_key_exists($key, $resolved)) {
                throw new LogicException(
                    "Duplicate Billing collection provider key [{$key}].",
                );
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

    public function get(string $key): BillingCollectionProvider
    {
        $key = trim($key);
        $provider = $this->all()[$key] ?? null;

        if (! $provider instanceof BillingCollectionProvider) {
            throw new RuntimeException(
                "Billing collection provider [{$key}] is not registered.",
            );
        }

        return $provider;
    }
}
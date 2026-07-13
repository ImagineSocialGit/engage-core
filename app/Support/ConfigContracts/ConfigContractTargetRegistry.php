<?php

namespace App\Support\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use InvalidArgumentException;

final class ConfigContractTargetRegistry
{
    /**
     * @var array<int, ConfigContractTargetProvider>
     */
    private array $providers = [];

    /**
     * @var array<string, ConfigContractTargetProvider>
     */
    private array $providersByContractKey = [];

    /**
     * @param iterable<int, ConfigContractTargetProvider> $providers
     */
    public function __construct(
        private readonly ConfigContractRegistry $contracts,
        iterable $providers = [],
    ) {
        foreach ($providers as $provider) {
            if (! $provider instanceof ConfigContractTargetProvider) {
                throw new InvalidArgumentException(sprintf(
                    'Config contract target registry received invalid provider [%s].',
                    get_debug_type($provider),
                ));
            }

            $providerKeys = $this->normalizeProviderKeys($provider);

            foreach ($providerKeys as $contractKey) {
                if (! $this->contracts->has($contractKey)) {
                    throw new InvalidArgumentException(sprintf(
                        'Config contract target provider [%s] references unregistered contract [%s].',
                        $provider::class,
                        $contractKey,
                    ));
                }

                if (isset($this->providersByContractKey[$contractKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Config contract [%s] has multiple target providers [%s] and [%s].',
                        $contractKey,
                        $this->providersByContractKey[$contractKey]::class,
                        $provider::class,
                    ));
                }

                $this->providersByContractKey[$contractKey] = $provider;
            }

            $this->providers[] = $provider;
        }

        ksort($this->providersByContractKey);

        $missingContractKeys = array_values(array_diff(
            array_keys($this->contracts->all()),
            array_keys($this->providersByContractKey),
        ));

        if ($missingContractKeys !== []) {
            sort($missingContractKeys);

            throw new InvalidArgumentException(sprintf(
                'Registered config contracts are missing target providers: [%s].',
                implode(', ', $missingContractKeys),
            ));
        }
    }

    /**
     * @return array<int, string>
     */
    public function contractKeys(): array
    {
        return array_keys($this->providersByContractKey);
    }

    /**
     * @return array<int, ConfigContractTargetProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * @return array<int, ConfigContractTarget>
     */
    public function targets(ConfigContractTargetContext $context): array
    {
        $targets = [];
        $seenIdentities = [];

        foreach ($this->providers as $provider) {
            $providerKeys = $this->normalizeProviderKeys($provider);

            foreach ($provider->targets($context) as $target) {
                if (! $target instanceof ConfigContractTarget) {
                    throw new InvalidArgumentException(sprintf(
                        'Config contract target provider [%s] returned invalid target [%s].',
                        $provider::class,
                        get_debug_type($target),
                    ));
                }

                if (! in_array($target->contractKey, $providerKeys, true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Config contract target provider [%s] returned target for undeclared contract [%s].',
                        $provider::class,
                        $target->contractKey,
                    ));
                }

                if (! $this->contracts->has($target->contractKey)) {
                    throw new InvalidArgumentException(sprintf(
                        'Config contract target provider [%s] returned target for unregistered contract [%s].',
                        $provider::class,
                        $target->contractKey,
                    ));
                }

                $identity = $target->identity();

                if (isset($seenIdentities[$identity])) {
                    throw new InvalidArgumentException(sprintf(
                        'Duplicate config contract target [%s] at path [%s].',
                        $target->contractKey,
                        $target->path,
                    ));
                }

                $seenIdentities[$identity] = true;
                $targets[] = $target;
            }
        }

        usort($targets, fn (
            ConfigContractTarget $left,
            ConfigContractTarget $right,
        ): int => [
            $left->contractKey,
            $left->path,
        ] <=> [
            $right->contractKey,
            $right->path,
        ]);

        return $targets;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeProviderKeys(ConfigContractTargetProvider $provider): array
    {
        $keys = [];

        foreach ($provider->contractKeys() as $key) {
            if (! is_string($key) || trim($key) === '') {
                throw new InvalidArgumentException(sprintf(
                    'Config contract target provider [%s] returned an invalid contract key.',
                    $provider::class,
                ));
            }

            $key = trim($key);

            if (in_array($key, $keys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Config contract target provider [%s] declares contract [%s] more than once.',
                    $provider::class,
                    $key,
                ));
            }

            $keys[] = $key;
        }

        if ($keys === []) {
            throw new InvalidArgumentException(sprintf(
                'Config contract target provider [%s] must declare at least one contract key.',
                $provider::class,
            ));
        }

        sort($keys);

        return $keys;
    }
}

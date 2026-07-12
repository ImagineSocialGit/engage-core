<?php

namespace App\Support\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContract;
use InvalidArgumentException;

class ConfigContractRegistry
{
    /**
     * @var array<string, ConfigContract>
     */
    private array $contracts = [];

    /**
     * @param iterable<int, ConfigContract> $contracts
     */
    public function __construct(iterable $contracts = [])
    {
        foreach ($contracts as $contract) {
            if (! $contract instanceof ConfigContract) {
                throw new InvalidArgumentException(sprintf(
                    'Config contract registry received invalid contract [%s].',
                    get_debug_type($contract),
                ));
            }

            $key = trim($contract->key());

            if ($key === '') {
                throw new InvalidArgumentException('Config contract keys must be non-empty strings.');
            }

            if (array_key_exists($key, $this->contracts)) {
                throw new InvalidArgumentException("Config contract [{$key}] is registered more than once.");
            }

            $this->contracts[$key] = $contract;
        }

        ksort($this->contracts);
    }

    public function get(string $key): ConfigContract
    {
        $contract = $this->contracts[$key] ?? null;

        if (! $contract instanceof ConfigContract) {
            throw new InvalidArgumentException("Config contract [{$key}] is not registered.");
        }

        return $contract;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->contracts);
    }

    /**
     * @return array<string, ConfigContract>
     */
    public function all(): array
    {
        return $this->contracts;
    }
}

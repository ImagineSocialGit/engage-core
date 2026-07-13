<?php

namespace Tests\Feature\ConfigContracts;

use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\ConfigContractTargetRegistry;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use App\Support\ConfigContracts\Data\ConfigSchema;
use InvalidArgumentException;
use Tests\TestCase;

class ConfigContractTargetRegistryTest extends TestCase
{
    public function test_every_registered_contract_has_exactly_one_registered_target_provider(): void
    {
        $contracts = app(ConfigContractRegistry::class);
        $targets = app(ConfigContractTargetRegistry::class);

        $this->assertSame(
            array_keys($contracts->all()),
            $targets->contractKeys(),
        );
    }

    public function test_it_rejects_a_provider_for_an_unregistered_contract(): void
    {
        $contracts = new ConfigContractRegistry([
            $this->contract('test.registered'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'references unregistered contract [test.missing].'
        );

        new ConfigContractTargetRegistry(
            contracts: $contracts,
            providers: [
                $this->provider(['test.missing']),
            ],
        );
    }

    public function test_it_rejects_registered_contracts_without_a_target_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Registered config contracts are missing target providers: [test.registered].'
        );

        new ConfigContractTargetRegistry(
            contracts: new ConfigContractRegistry([
                $this->contract('test.registered'),
            ]),
            providers: [],
        );
    }

    public function test_it_rejects_duplicate_provider_ownership_of_a_contract(): void
    {
        $contracts = new ConfigContractRegistry([
            $this->contract('test.contract'),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Config contract [test.contract] has multiple target providers'
        );

        new ConfigContractTargetRegistry(
            contracts: $contracts,
            providers: [
                $this->provider(['test.contract']),
                $this->provider(['test.contract']),
            ],
        );
    }

    public function test_zero_current_targets_is_valid_when_provider_coverage_exists(): void
    {
        $contracts = new ConfigContractRegistry([
            $this->contract('test.contract'),
        ]);

        $registry = new ConfigContractTargetRegistry(
            contracts: $contracts,
            providers: [
                $this->provider(['test.contract'], []),
            ],
        );

        $this->assertSame([], $registry->targets(ConfigContractTargetContext::current()));
    }

    public function test_it_rejects_duplicate_contract_key_and_canonical_path_targets(): void
    {
        $contracts = new ConfigContractRegistry([
            $this->contract('test.contract'),
        ]);

        $target = new ConfigContractTarget(
            contractKey: 'test.contract',
            path: 'test.definitions.same',
            value: ['name' => 'Same'],
        );

        $registry = new ConfigContractTargetRegistry(
            contracts: $contracts,
            providers: [
                $this->provider('test.contract', [$target, $target]),
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Duplicate config contract target [test.contract] at path [test.definitions.same].'
        );

        $registry->targets(ConfigContractTargetContext::current());
    }

    private function contract(string $key): ConfigContract
    {
        return new class ($key) implements ConfigContract
        {
            public function __construct(
                private readonly string $contractKey,
            ) {}

            public function key(): string
            {
                return $this->contractKey;
            }

            public function owner(): string
            {
                return 'test';
            }

            public function sourcePattern(): string
            {
                return 'test.definitions.{key}';
            }

            public function schema(): ConfigSchema
            {
                return ConfigSchema::mixed();
            }

            public function example(): array
            {
                return [];
            }
        };
    }

    /**
     * @param string|array<int, string> $contractKeys
     * @param array<int, ConfigContractTarget> $targets
     */
    private function provider(
        string|array $contractKeys,
        array $targets = [],
    ): ConfigContractTargetProvider {
        $contractKeys = is_string($contractKeys)
            ? [$contractKeys]
            : $contractKeys;

        return new class ($contractKeys, $targets) implements ConfigContractTargetProvider
        {
            /**
             * @param array<int, string> $contractKeys
             * @param array<int, ConfigContractTarget> $targets
             */
            public function __construct(
                private readonly array $contractKeys,
                private readonly array $targetDefinitions,
            ) {}

            public function contractKeys(): array
            {
                return $this->contractKeys;
            }

            public function targets(ConfigContractTargetContext $context): iterable
            {
                yield from $this->targetDefinitions;
            }
        };
    }
}

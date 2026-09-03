<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class EnvironmentRequirementPositiveIntegerRuleTest extends TestCase
{
    public function test_defaulted_positive_integer_may_be_omitted(): void
    {
        $requirement = $this->resolve(null);

        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_DEFAULT,
            $requirement->status,
        );
        $this->assertFalse($requirement->blocksDeployment());
    }

    public function test_positive_integer_accepts_canonical_values(): void
    {
        foreach (['1', '25', '500'] as $value) {
            $requirement = $this->resolve($value);

            $this->assertSame(
                ResolvedEnvironmentRequirement::STATUS_READY,
                $requirement->status,
                "Expected [{$value}] to be valid.",
            );
            $this->assertFalse($requirement->blocksDeployment());
        }
    }

    public function test_positive_integer_rejects_invalid_persisted_overrides(): void
    {
        foreach ([
            '0',
            '-1',
            '1.5',
            'abc',
            '01',
            '999999999999999999999999999999999999',
        ] as $value) {
            $requirement = $this->resolve($value);

            $this->assertSame(
                ResolvedEnvironmentRequirement::STATUS_INVALID,
                $requirement->status,
                "Expected [{$value}] to be invalid.",
            );
            $this->assertTrue(
                $requirement->blocksDeployment(),
                "Expected [{$value}] to block deployment.",
            );
        }
    }

    private function resolve(?string $value): ResolvedEnvironmentRequirement
    {
        $root = $value === null
            ? []
            : ['FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET' => $value];

        $plan = (new DeploymentPlanResolver(
            contributors: [$this->contributor()],
            environmentFiles: $this->repository($root),
            modules: new ModuleManager(),
        ))->resolve();

        return $plan->environmentRequirements[0];
    }

    private function repository(array $root): EnvironmentFileRepository
    {
        return new class ($root) extends EnvironmentFileRepository
        {
            public function __construct(private readonly array $root) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'root' ? $this->root : [];
            }
        };
    }

    private function contributor(): DeploymentPlanContributor
    {
        return new class implements DeploymentPlanContributor
        {
            public function owner(): string
            {
                return 'flow_routes';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::defaulted(
                    'FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET',
                    'Test positive integer override.',
                    valueRule: EnvironmentRequirement::VALUE_RULE_POSITIVE_INTEGER,
                );
            }
        };
    }
}
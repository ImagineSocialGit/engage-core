<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;
use Tests\TestCase;

class EnvironmentRequirementValueRuleTest extends TestCase
{
    public function test_unknown_value_rule_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported environment value rule [not_a_rule]');

        EnvironmentRequirement::optional(
            'SCHEDULING_APP_URL',
            'Test requirement.',
            valueRule: 'not_a_rule',
        );
    }

    public function test_optional_http_origin_may_be_omitted(): void
    {
        $requirement = $this->resolve(null);

        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_OPTIONAL,
            $requirement->status,
        );
        $this->assertFalse($requirement->blocksDeployment());
    }

    public function test_optional_http_origin_accepts_a_valid_persisted_origin(): void
    {
        $requirement = $this->resolve('https://booking.example.test:8443/');

        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_READY,
            $requirement->status,
        );
        $this->assertFalse($requirement->blocksDeployment());
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            $requirement->toArray()['value_rule'],
        );
    }

    public function test_optional_http_origin_rejects_a_malformed_persisted_override(): void
    {
        foreach ([
            'booking.example.test',
            'ftp://booking.example.test',
            'https://user:pass@booking.example.test',
            'https://booking.example.test/path',
            'https://booking.example.test?source=test',
            'https://booking.example.test#fragment',
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
        $client = $value === null
            ? []
            : ['SCHEDULING_APP_URL' => $value];

        $plan = (new DeploymentPlanResolver(
            contributors: [$this->contributor()],
            environmentFiles: $this->repository($client),
            modules: new ModuleManager(),
        ))->resolve();

        return $plan->environmentRequirements[0];
    }

    private function repository(array $client): EnvironmentFileRepository
    {
        return new class ($client) extends EnvironmentFileRepository
        {
            public function __construct(private readonly array $client) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'client' ? $this->client : [];
            }
        };
    }

    private function contributor(): DeploymentPlanContributor
    {
        return new class implements DeploymentPlanContributor
        {
            public function owner(): string
            {
                return 'scheduling';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::optional(
                    'SCHEDULING_APP_URL',
                    'Optional public Scheduling origin.',
                    valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
                );
            }
        };
    }
}
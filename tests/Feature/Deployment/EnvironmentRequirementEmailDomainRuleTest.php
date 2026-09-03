<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class EnvironmentRequirementEmailDomainRuleTest extends TestCase
{
    public function test_optional_email_domain_may_be_omitted(): void
    {
        $requirement = $this->resolve(null);

        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_OPTIONAL,
            $requirement->status,
        );
        $this->assertFalse($requirement->blocksDeployment());
    }

    public function test_email_domain_accepts_a_valid_bare_domain(): void
    {
        $requirement = $this->resolve('replies.example.test');

        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_READY,
            $requirement->status,
        );
        $this->assertFalse($requirement->blocksDeployment());
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
            $requirement->toArray()['value_rule'],
        );
    }

    public function test_email_domain_rejects_non_domain_values(): void
    {
        foreach ([
            'localhost',
            'https://replies.example.test',
            'reply@replies.example.test',
            'replies.example.test/path',
            'replies.example.test?source=test',
            '-replies.example.test',
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
            : ['INBOUND_EMAIL_DOMAIN' => $value];

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
                return 'inbound_messaging';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::optional(
                    'INBOUND_EMAIL_DOMAIN',
                    'Optional inbound email domain.',
                    valueRule: EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
                );
            }
        };
    }
}
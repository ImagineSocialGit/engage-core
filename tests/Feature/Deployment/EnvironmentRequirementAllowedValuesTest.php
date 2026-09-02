<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class EnvironmentRequirementAllowedValuesTest extends TestCase
{
    public function test_required_value_outside_allowed_set_blocks_deployment(): void
    {
        $plan = $this->resolver(['EMAIL_PROVIDER' => 'unsupported'])->resolve();
        $requirement = $plan->environmentRequirements[0];

        $this->assertFalse($plan->ready());
        $this->assertSame(ResolvedEnvironmentRequirement::STATUS_INVALID, $requirement->status);
        $this->assertSame(['resend'], $requirement->toArray()['allowed_values']);
    }

    public function test_required_value_inside_allowed_set_is_ready(): void
    {
        $plan = $this->resolver(['EMAIL_PROVIDER' => 'resend'])->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_READY,
            $plan->environmentRequirements[0]->status,
        );
    }

    /** @param array<string, string> $clientValues */
    private function resolver(array $clientValues): DeploymentPlanResolver
    {
        $contributor = new class implements DeploymentPlanContributor
        {
            public function owner(): string
            {
                return 'messaging';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::required(
                    'EMAIL_PROVIDER',
                    'Select one supported email provider.',
                    allowedValues: ['resend'],
                );
            }
        };

        $repository = new class ($clientValues) extends EnvironmentFileRepository
        {
            /** @param array<string, string> $clientValues */
            public function __construct(private readonly array $clientValues) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'client' ? $this->clientValues : [];
            }
        };

        return new DeploymentPlanResolver(
            contributors: [$contributor],
            environmentFiles: $repository,
            modules: new ModuleManager(),
        );
    }
}
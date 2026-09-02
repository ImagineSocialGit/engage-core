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
        $plan = $this->resolver(
            clientValues: ['EMAIL_PROVIDER' => 'unsupported'],
            requirement: EnvironmentRequirement::required(
                'EMAIL_PROVIDER',
                'Select one supported email provider.',
                allowedValues: ['resend'],
            ),
        )->resolve();
        $requirement = $plan->environmentRequirements[0];

        $this->assertFalse($plan->ready());
        $this->assertSame(ResolvedEnvironmentRequirement::STATUS_INVALID, $requirement->status);
        $this->assertSame(['resend'], $requirement->toArray()['allowed_values']);
    }

    public function test_required_value_inside_allowed_set_is_ready(): void
    {
        $plan = $this->resolver(
            clientValues: ['EMAIL_PROVIDER' => 'resend'],
            requirement: EnvironmentRequirement::required(
                'EMAIL_PROVIDER',
                'Select one supported email provider.',
                allowedValues: ['resend'],
            ),
        )->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_READY,
            $plan->environmentRequirements[0]->status,
        );
    }

    public function test_defaulted_selector_can_omit_safe_default_without_blocking_deployment(): void
    {
        $plan = $this->resolver(
            clientValues: [],
            requirement: EnvironmentRequirement::defaulted(
                'WEBINAR_PROVIDER',
                'Zoom is the safe default Webinar provider.',
                allowedValues: ['zoom'],
            ),
        )->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_DEFAULT,
            $plan->environmentRequirements[0]->status,
        );
    }

    public function test_defaulted_selector_accepts_supported_persisted_override(): void
    {
        $plan = $this->resolver(
            clientValues: ['WEBINAR_PROVIDER' => 'zoom'],
            requirement: EnvironmentRequirement::defaulted(
                'WEBINAR_PROVIDER',
                'Zoom is the safe default Webinar provider.',
                allowedValues: ['zoom'],
            ),
        )->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_READY,
            $plan->environmentRequirements[0]->status,
        );
    }

    public function test_invalid_defaulted_selector_override_blocks_deployment(): void
    {
        $plan = $this->resolver(
            clientValues: ['WEBINAR_PROVIDER' => 'unsupported'],
            requirement: EnvironmentRequirement::defaulted(
                'WEBINAR_PROVIDER',
                'Zoom is the safe default Webinar provider.',
                allowedValues: ['zoom'],
            ),
        )->resolve();
        $requirement = $plan->environmentRequirements[0];

        $this->assertFalse($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_INVALID,
            $requirement->status,
        );
        $this->assertTrue($requirement->blocksDeployment());
    }

    /** @param array<string, string> $clientValues */
    private function resolver(
        array $clientValues,
        EnvironmentRequirement $requirement,
    ): DeploymentPlanResolver {
        $contributor = new class ($requirement) implements DeploymentPlanContributor
        {
            public function __construct(
                private readonly EnvironmentRequirement $requirement,
            ) {}

            public function owner(): string
            {
                return $this->requirement->key === 'WEBINAR_PROVIDER'
                    ? 'webinars'
                    : 'messaging';
            }

            public function environmentRequirements(): iterable
            {
                yield $this->requirement;
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
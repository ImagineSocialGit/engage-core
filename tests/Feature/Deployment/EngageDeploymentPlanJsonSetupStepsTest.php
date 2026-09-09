<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EngageDeploymentPlanJsonSetupStepsTest extends TestCase
{
    public function test_json_output_exposes_structured_operator_setup_steps(): void
    {
        $repository = new class extends EnvironmentFileRepository
        {
            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'root'
                    ? ['APP_ENV' => 'testing']
                    : [];
            }
        };

        $contributor = new class implements DeploymentPlanContributor, DeploymentSetupContributor
        {
            public function owner(): string
            {
                return 'core';
            }

            public function environmentRequirements(): iterable
            {
                yield EnvironmentRequirement::required(
                    'APP_ENV',
                    'Runtime environment must be explicit.',
                );
            }

            public function setupSteps(): iterable
            {
                yield new DeploymentSetupStep(
                    key: 'core.example',
                    title: 'Example external setup',
                    reason: 'The launcher needs structured operator instructions.',
                    instructions: ['Open the external service.'],
                    environmentKeys: ['TURNSTILE_SITE_KEY'],
                    verification: ['Verify the external service.'],
                    priority: 10,
                );
            }
        };

        $this->app->instance(
            DeploymentPlanResolver::class,
            new DeploymentPlanResolver(
                contributors: [$contributor],
                environmentFiles: $repository,
                modules: new ModuleManager(),
            ),
        );

        $exitCode = Artisan::call('engage:deployment-plan', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('core.example', $payload['setup_steps'][0]['key']);
        $this->assertSame('core', $payload['setup_steps'][0]['owner']);
        $this->assertSame(
            ['TURNSTILE_SITE_KEY'],
            $payload['setup_steps'][0]['environment_keys'],
        );
    }
}
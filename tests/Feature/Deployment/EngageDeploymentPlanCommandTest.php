<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class EngageDeploymentPlanCommandTest extends TestCase
{
    public function test_normal_output_shows_required_and_active_overrides_while_verbose_shows_everything(): void
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
                    ? ['APP_ENV' => 'testing', 'APP_DEBUG' => 'true']
                    : [];
            }
        };

        $contributor = new class implements DeploymentPlanContributor
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
                yield EnvironmentRequirement::defaulted(
                    'APP_DEBUG',
                    'Persisted value is an active override.',
                );
                yield EnvironmentRequirement::defaulted(
                    'APP_NAME',
                    'Missing value uses the Core default.',
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

        $exitCode = Artisan::call('engage:deployment-plan');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('APP_ENV', $output);
        $this->assertStringContainsString('APP_DEBUG', $output);
        $this->assertStringNotContainsString('APP_NAME', $output);
        $this->assertStringContainsString(
            '1 inactive optional/defaulted requirement(s) hidden.',
            $output,
        );

        $exitCode = Artisan::call('engage:deployment-plan', ['--verbose' => true]);
        $verboseOutput = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('APP_ENV', $verboseOutput);
        $this->assertStringContainsString('APP_DEBUG', $verboseOutput);
        $this->assertStringContainsString('APP_NAME', $verboseOutput);
        $this->assertStringNotContainsString('inactive optional/defaulted requirement(s) hidden.', $verboseOutput);
    }
}
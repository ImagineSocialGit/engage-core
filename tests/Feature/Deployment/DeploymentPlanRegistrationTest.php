<?php

namespace Tests\Feature\Deployment;

use App\Modules\Core\Deployment\CoreDeploymentPlanContributor;
use App\Modules\Forms\Deployment\FormsDeploymentPlanContributor;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Support\Deployment\DeploymentPlanResolver;
use Tests\TestCase;

class DeploymentPlanRegistrationTest extends TestCase
{
    public function test_core_deployment_contributor_is_registered_by_the_active_core_module(): void
    {
        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(CoreDeploymentPlanContributor::class, $classes);
        $this->assertInstanceOf(DeploymentPlanResolver::class, app(DeploymentPlanResolver::class));
    }

    public function test_forms_provider_registers_forms_deployment_contributor(): void
    {
        $this->app->register(FormsModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(FormsDeploymentPlanContributor::class, $classes);
    }
}
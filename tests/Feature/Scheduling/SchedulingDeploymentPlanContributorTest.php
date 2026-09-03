<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Deployment\SchedulingDeploymentPlanContributor;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class SchedulingDeploymentPlanContributorTest extends TestCase
{
    public function test_public_scheduling_origin_is_optional_and_format_validated(): void
    {
        $requirements = iterator_to_array(
            app(SchedulingDeploymentPlanContributor::class)->environmentRequirements(),
            false,
        );

        $this->assertCount(1, $requirements);

        $requirement = $requirements[0];

        $this->assertSame('SCHEDULING_APP_URL', $requirement->key);
        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirement->requirement);
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            $requirement->valueRule,
        );
    }

    public function test_scheduling_provider_registers_deployment_contributor(): void
    {
        $this->app->register(
            SchedulingModuleServiceProvider::class,
            force: true,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('deployment.plan_contributors'),
                false,
            ),
        );

        $this->assertContains(
            SchedulingDeploymentPlanContributor::class,
            $classes,
        );
    }
}
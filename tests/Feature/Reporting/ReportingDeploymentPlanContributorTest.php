<?php

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Deployment\ReportingDeploymentPlanContributor;
use App\Modules\Reporting\Providers\ReportingModuleServiceProvider;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Container\Container;
use Tests\TestCase;

class ReportingDeploymentPlanContributorTest extends TestCase
{
    public function test_reporting_declares_an_audited_zero_environment_contract(): void
    {
        $contributor = new ReportingDeploymentPlanContributor();

        $this->assertSame('reporting', $contributor->owner());
        $this->assertSame([], $contributor->environmentRequirements());

        $ownedEnvironmentKeys = collect(EnvironmentVariableCatalog::definitions())
            ->filter(
                static fn ($definition): bool => $definition->owner === 'reporting',
            )
            ->keys()
            ->values()
            ->all();

        $this->assertSame([], $ownedEnvironmentKeys);
    }

    public function test_reporting_provider_registers_the_deployment_contributor(): void
    {
        $container = new Container();

        (new ReportingModuleServiceProvider($container))->register();

        $contributors = collect(
            $container->tagged('deployment.plan_contributors'),
        );

        $this->assertCount(1, $contributors);
        $this->assertInstanceOf(
            ReportingDeploymentPlanContributor::class,
            $contributors->first(),
        );
    }
}
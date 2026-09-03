<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Deployment\FlowRoutesDeploymentPlanContributor;
use App\Modules\FlowRoutes\Providers\FlowRoutesModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Container\Container;
use Tests\TestCase;

class FlowRoutesDeploymentPlanContributorTest extends TestCase
{
    public function test_flow_routes_claims_its_complete_environment_vocabulary_as_defaulted_process_overrides(): void
    {
        $contributor = new FlowRoutesDeploymentPlanContributor();

        $requirements = collect($contributor->environmentRequirements())
            ->keyBy(
                static fn (EnvironmentRequirement $requirement): string => $requirement->key,
            );

        $this->assertSame('flow_routes', $contributor->owner());
        $this->assertSame(
            [
                'FLOW_ROUTE_CONTINUATION_QUEUE',
                'FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET',
            ],
            $requirements->keys()->sort()->values()->all(),
        );

        $this->assertSame(
            EnvironmentRequirement::DEFAULTED,
            $requirements['FLOW_ROUTE_CONTINUATION_QUEUE']->requirement,
        );
        $this->assertNull(
            $requirements['FLOW_ROUTE_CONTINUATION_QUEUE']->valueRule,
        );

        $this->assertSame(
            EnvironmentRequirement::DEFAULTED,
            $requirements['FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_POSITIVE_INTEGER,
            $requirements['FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET']->valueRule,
        );

        $ownedKeys = collect(EnvironmentVariableCatalog::definitions())
            ->filter(
                static fn ($definition): bool => $definition->owner === 'flow_routes',
            )
            ->keys()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $ownedKeys,
            $requirements->keys()->sort()->values()->all(),
        );
    }

    public function test_flow_routes_provider_registers_the_deployment_contributor(): void
    {
        $container = new Container();

        (new FlowRoutesModuleServiceProvider($container))->register();

        $contributors = collect(
            $container->tagged('deployment.plan_contributors'),
        );

        $this->assertCount(1, $contributors);
        $this->assertInstanceOf(
            FlowRoutesDeploymentPlanContributor::class,
            $contributors->first(),
        );
    }
}
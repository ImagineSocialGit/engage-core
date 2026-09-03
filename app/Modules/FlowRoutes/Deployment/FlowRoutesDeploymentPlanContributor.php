<?php

namespace App\Modules\FlowRoutes\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;

final class FlowRoutesDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'flow_routes';
    }

    public function environmentRequirements(): iterable
    {
        yield EnvironmentRequirement::defaulted(
            'FLOW_ROUTE_CONTINUATION_QUEUE',
            'Flow Routes uses the default queue unless this deployment intentionally routes continuation work to a different worker queue.',
        );

        yield EnvironmentRequirement::defaulted(
            'FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET',
            'Flow Routes defaults to 25 immediately advancing points per process slice; persist this root/process override only when deliberately tuning execution.',
            valueRule: EnvironmentRequirement::VALUE_RULE_POSITIVE_INTEGER,
        );
    }
}
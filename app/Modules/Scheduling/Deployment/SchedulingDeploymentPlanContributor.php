<?php

namespace App\Modules\Scheduling\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;

final class SchedulingDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'scheduling';
    }

    /** @return iterable<int, EnvironmentRequirement> */
    public function environmentRequirements(): iterable
    {
        yield EnvironmentRequirement::optional(
            'SCHEDULING_APP_URL',
            'Public Scheduling is optional. Persist SCHEDULING_APP_URL only when this client exposes the generic public booking surface; when present it must be a root-level http:// or https:// origin without credentials, path, query, or fragment.',
            valueRule: EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
        );
    }
}
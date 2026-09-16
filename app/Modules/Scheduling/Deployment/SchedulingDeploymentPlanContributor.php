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

        yield EnvironmentRequirement::defaulted(
            'SCHEDULING_TRAVEL_PROVIDER',
            'Scheduling always has conservative travel protection. Set google_routes when this client should use real drive-time estimates between physical appointments.',
            allowedValues: ['conservative', 'google_routes'],
        );

        if ((string) config('scheduling.travel.provider') === 'google_routes') {
            yield EnvironmentRequirement::required(
                'GOOGLE_ROUTES_API_KEY',
                'Google Routes travel-time resolution is selected for Scheduling and requires a client API key with Routes API access.',
            );
        } else {
            yield EnvironmentRequirement::optional(
                'GOOGLE_ROUTES_API_KEY',
                'Unused unless SCHEDULING_TRAVEL_PROVIDER is google_routes.',
            );
        }
    }
}
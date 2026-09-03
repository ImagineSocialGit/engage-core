<?php

namespace App\Modules\Reporting\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;

final class ReportingDeploymentPlanContributor implements DeploymentPlanContributor
{
    public function owner(): string
    {
        return 'reporting';
    }

    public function environmentRequirements(): iterable
    {
        // Reporting currently has no deployment-owned environment vocabulary.
        // Its runtime policy is committed config/database state layered on
        // Core-owned infrastructure such as APP_KEY and the scheduler.
        return [];
    }
}
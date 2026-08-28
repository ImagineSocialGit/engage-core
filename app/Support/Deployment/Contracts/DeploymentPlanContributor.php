<?php

namespace App\Support\Deployment\Contracts;

use App\Support\Deployment\Data\EnvironmentRequirement;

interface DeploymentPlanContributor
{
    public function owner(): string;

    /**
     * @return iterable<int, EnvironmentRequirement>
     */
    public function environmentRequirements(): iterable;
}
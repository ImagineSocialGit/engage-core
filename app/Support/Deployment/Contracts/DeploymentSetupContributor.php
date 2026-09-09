<?php

namespace App\Support\Deployment\Contracts;

use App\Support\Deployment\Data\DeploymentSetupStep;

interface DeploymentSetupContributor
{
    /**
     * @return iterable<int, DeploymentSetupStep>
     */
    public function setupSteps(): iterable;
}
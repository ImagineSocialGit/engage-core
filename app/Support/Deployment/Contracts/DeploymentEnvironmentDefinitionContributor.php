<?php

namespace App\Support\Deployment\Contracts;

use App\Support\Environment\Data\EnvironmentVariableDefinition;

/**
 * Optional extension of an already selected deployment-plan contributor.
 *
 * The enabled module/package owns its per-client dynamic environment keys.
 * Environment definitions describe ownership, scope and sensitivity; the
 * normal environmentRequirements() method independently determines which
 * keys are required for this selected client and active capability.
 *
 * Implementations are discovered through the EXISTING
 * deployment.plan_contributors tag, not through a second registry.
 */
interface DeploymentEnvironmentDefinitionContributor extends DeploymentPlanContributor
{
    /** @return iterable<int, EnvironmentVariableDefinition> */
    public function environmentDefinitions(): iterable;
}
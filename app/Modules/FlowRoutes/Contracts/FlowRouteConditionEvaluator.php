<?php

namespace App\Modules\FlowRoutes\Contracts;

use App\Modules\FlowRoutes\Data\FlowRouteConditionEvaluation;
use App\Modules\FlowRoutes\Data\PointExecutionContext;

interface FlowRouteConditionEvaluator
{
    /**
     * @param array<string, mixed> $condition
     */
    public function supports(array $condition, PointExecutionContext $context): bool;

    /**
     * @param array<string, mixed> $condition
     */
    public function evaluate(array $condition, PointExecutionContext $context): FlowRouteConditionEvaluation;
}
<?php

namespace App\Modules\FlowRoutes\Contracts;

use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;

interface PointHandler
{
    public function type(): string;

    public function handle(PointExecutionContext $context): PointExecutionResult;
}
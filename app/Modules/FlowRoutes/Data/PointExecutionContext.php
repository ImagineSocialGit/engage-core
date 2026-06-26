<?php

namespace App\Modules\FlowRoutes\Data;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;

class PointExecutionContext
{
    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ContactFlowRouteProgress $progress,
        public readonly FlowRoutePoint $flowRoutePoint,
        public readonly array $definition,
        public readonly array $settings,
        public readonly array $meta = [],
    ) {}

    public function pointType(): string
    {
        return (string) $this->flowRoutePoint->point->type;
    }
}
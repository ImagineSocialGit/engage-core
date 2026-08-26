<?php

namespace App\Modules\FlowRoutes\PointHandlers;

use App\Modules\Core\Services\BusinessCalendar\BusinessCalendarDateCalculator;
use App\Modules\FlowRoutes\Contracts\PointHandler;
use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Data\Points\WaitPointDefinition;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use Carbon\CarbonImmutable;

class WaitPointHandler implements PointHandler
{
    public function __construct(
        private readonly ?BusinessCalendarDateCalculator $businessCalendar = null,
    ) {}

    public function type(): string { return FlowRoutePointType::Wait->value; }

    public function handle(PointExecutionContext $context): PointExecutionResult
    {
        $now = CarbonImmutable::now('UTC');
        $waitingState = $context->progress->waitingState();
        $waitingFlowRoutePointId = $context->progress->waitingFlowRoutePointId();
        $resumeAt = $context->progress->waitingResumeAt();

        if ($waitingFlowRoutePointId === (int) $context->flowRoutePoint->getKey() && $resumeAt instanceof CarbonImmutable) {
            if ($resumeAt->greaterThan($now)) {
                return PointExecutionResult::waiting('wait_point_not_due', [
                    'wait' => array_replace_recursive($waitingState, [
                        'checked_at' => $now->toISOString(),
                    ]),
                    'flow_routes' => $context->flowRouteProvenance(),
                ]);
            }

            return PointExecutionResult::completed('wait_point_due', [
                'wait' => array_replace_recursive($waitingState, [
                    'resumed_at' => $now->toISOString(),
                ]),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        $definition = WaitPointDefinition::from(
            definition: $context->definition,
            settings: $context->settings,
            now: $now,
            businessDayCalculator: fn (
                int $businessDays,
                CarbonImmutable $from,
                string $timezone,
            ): CarbonImmutable => ($this->businessCalendar ?? app(BusinessCalendarDateCalculator::class))->addBusinessDays(
                from: $from,
                businessDays: $businessDays,
                timezone: $timezone,
            ),
        );

        if (! $definition->isValid()) {
            return PointExecutionResult::failed($definition->invalidReason ?? 'invalid_wait_point_definition', [
                'wait_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        if ($definition->isImmediate($now)) {
            return PointExecutionResult::completed('wait_point_immediate', [
                'wait_definition' => $definition->toMetaPayload(),
                'flow_routes' => $context->flowRouteProvenance(),
            ]);
        }

        return PointExecutionResult::waiting('wait_point_scheduled', [
            'wait' => [
                ...$context->flowRouteProvenance(),
                'flow_route_point_key' => $context->flowRoutePoint->key,
                'point_type' => FlowRoutePointType::Wait->value,
                'started_waiting_at' => $now->toISOString(),
                'resume_at' => $definition->resumeAt?->toISOString(),
                'definition' => $definition->toMetaPayload(),
            ],
            'flow_routes' => $context->flowRouteProvenance(),
        ]);
    }
}
<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRouteTriggerBindingResolver;
use Illuminate\Support\Facades\DB;

class StartFlowRoutesFromAutomationEventAction
{
    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
        private readonly FlowRouteTriggerBindingResolver $flowRouteTriggerBindingResolver,
    ) {}

    public function handle(FlowRouteExternalEvent $event): void
    {
        if (trim($event->name) === '' || $event->contactId === null) {
            return;
        }

        $flowRoutes = $this->flowRouteTriggerBindingResolver
            ->selectedFlowRoutes(
                triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
                triggerKey: $event->name,
                contextType: $event->subjectType,
                contextId: $event->subjectId,
            );

        foreach ($flowRoutes as $flowRoute) {
            if (! $flowRoute instanceof FlowRoute) {
                continue;
            }

            $flowRoute->loadMissing('activeFlowRoutePoints.point');

            $progress = $this->startProgress($flowRoute, $event);

            if ($progress instanceof ContactFlowRouteProgress) {
                $this->executeProgressUntilIdle($progress);
            }
        }
    }

    private function executeProgressUntilIdle(ContactFlowRouteProgress $progress): void
    {
        $attempts = 0;
        $result = null;

        do {
            $result = $this->executeCurrentFlowRoutePoint->handle($progress);
            $progress->refresh();
            $attempts++;
        } while ($attempts < 25 && $result->shouldAdvance() && $progress->isActive());
    }

    private function startProgress(
        FlowRoute $flowRoute,
        FlowRouteExternalEvent $event,
    ): ?ContactFlowRouteProgress {
        return DB::transaction(function () use ($flowRoute, $event) {
            $existingProgress = ContactFlowRouteProgress::query()
                ->active()
                ->forContact($event->contactId)
                ->where('flow_route_id', $flowRoute->getKey())
                ->first();

            if ($existingProgress instanceof ContactFlowRouteProgress) {
                return $existingProgress;
            }

            $currentFlowRoutePoint = $this->startingFlowRoutePoint($flowRoute);

            return ContactFlowRouteProgress::query()->create([
                'contact_id' => $event->contactId,
                'contact_status_id' => null,
                'contact_workflow_profile_id' => null,
                'flow_route_id' => $flowRoute->getKey(),
                'current_flow_route_point_id' => $currentFlowRoutePoint?->getKey(),
                'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
                'started_at' => $event->occurredAt,
                'meta' => [
                    'started_from_automation_event' => $event->toMetaPayload(),
                ],
            ]);
        });
    }

    private function startingFlowRoutePoint(FlowRoute $flowRoute): ?FlowRoutePoint
    {
        $startPoint = $flowRoute->activeFlowRoutePoints()
            ->start()
            ->ordered()
            ->first();

        if ($startPoint instanceof FlowRoutePoint) {
            return $startPoint;
        }

        return $flowRoute->activeFlowRoutePoints()
            ->ordered()
            ->first();
    }
}

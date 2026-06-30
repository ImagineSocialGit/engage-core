<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Support\Facades\DB;

class StartFlowRoutesFromAutomationEventAction
{
    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
    ) {}

    public function handle(FlowRouteExternalEvent $event): void
    {
        if (trim($event->name) === '' || $event->contactId === null) {
            return;
        }

        FlowRoute::query()
            ->active()
            ->forAutomationEvent($event->name)
            ->with('activeFlowRoutePoints.point')
            ->orderByDesc('version')
            ->chunkById(100, function ($flowRoutes) use ($event) {
                foreach ($flowRoutes as $flowRoute) {
                    $progress = $this->startProgress($flowRoute, $event);

                    if ($progress instanceof ContactFlowRouteProgress) {
                        $this->executeCurrentFlowRoutePoint->handle($progress);
                    }
                }
            });
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

            $currentFlowRoutePoint = $flowRoute->activeFlowRoutePoints()
                ->ordered()
                ->first();

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
}
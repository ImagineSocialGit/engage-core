<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;

class StartFlowRouteProgressAction
{
    public function handle(ContactWorkflowStatusTransition $transition): ?ContactFlowRouteProgress
    {
        $flowRoute = $this->activeFlowRouteForTransition($transition);

        if (! $flowRoute) {
            return null;
        }

        $existingProgress = ContactFlowRouteProgress::query()
            ->active()
            ->forWorkflowProfile($transition->contactWorkflowProfileId)
            ->where('flow_route_id', $flowRoute->getKey())
            ->first();

        if ($existingProgress instanceof ContactFlowRouteProgress) {
            return $existingProgress;
        }

        $currentFlowRoutePoint = $this->startingFlowRoutePoint($flowRoute);

        return ContactFlowRouteProgress::query()->create([
            'contact_id' => $transition->contactId,
            'contact_status_id' => $transition->toContactStatusId,
            'contact_workflow_profile_id' => $transition->contactWorkflowProfileId,
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $currentFlowRoutePoint?->getKey(),
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => $transition->occurredAt,
            'meta' => [
                'started_from_workflow_transition' => $transition->toMetaPayload(),
            ],
        ]);
    }

    private function activeFlowRouteForTransition(ContactWorkflowStatusTransition $transition): ?FlowRoute
    {
        return FlowRoute::query()
            ->active()
            ->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS)
            ->forContactStatus($transition->toContactStatusId)
            ->orderByDesc('version')
            ->first();
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
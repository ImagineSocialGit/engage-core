<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRouteTriggerBindingResolver;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Support\Facades\DB;

class StartFlowRouteProgressAction
{
    public function __construct(
        private readonly FlowRouteTriggerBindingResolver $flowRouteTriggerBindingResolver,
        private readonly CreateContactFlowRoutePlanAction $createContactFlowRoutePlan,
    ) {}

    public function handle(ContactWorkflowStatusTransition $transition): ?ContactFlowRouteProgress
    {
        $flowRoute = $this->activeFlowRouteForTransition($transition);

        if (! $flowRoute) {
            return null;
        }

        return DB::transaction(function () use ($transition, $flowRoute) {
            $existingProgress = ContactFlowRouteProgress::query()
                ->active()
                ->forWorkflowProfile($transition->contactWorkflowProfileId)
                ->where('flow_route_id', $flowRoute->getKey())
                ->forSubject(null, null)
                ->first();

            if ($existingProgress instanceof ContactFlowRouteProgress) {
                $this->createContactFlowRoutePlan->handle($existingProgress, $flowRoute);

                return $existingProgress;
            }

            $currentFlowRoutePoint = $this->startingFlowRoutePoint($flowRoute);

            $progress = ContactFlowRouteProgress::query()->create([
                'contact_id' => $transition->contactId,
                'subject_type' => null,
                'subject_id' => null,
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

            $this->createContactFlowRoutePlan->handle($progress, $flowRoute);

            return $progress->refresh();
        });
    }

    private function activeFlowRouteForTransition(ContactWorkflowStatusTransition $transition): ?FlowRoute
    {
        return $this->flowRouteTriggerBindingResolver
            ->selectedFlowRouteForContactStatus($transition->toContactStatusId);
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

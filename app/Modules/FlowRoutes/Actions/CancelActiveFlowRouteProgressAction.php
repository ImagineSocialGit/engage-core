<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;

class CancelActiveFlowRouteProgressAction
{
    public function handle(
        ContactWorkflowStatusTransition $transition,
        string $status = ContactFlowRouteProgress::STATUS_SUPERSEDED,
        string $reason = 'workflow_status_changed',
    ): int {
        $cancelledAt = $transition->occurredAt;

        $progresses = ContactFlowRouteProgress::query()
            ->with('plan.items')
            ->runnable()
            ->forWorkflowProfile($transition->contactWorkflowProfileId)
            ->get();

        foreach ($progresses as $progress) {
            if ($progress->plan instanceof ContactFlowRoutePlan) {
                $progress->plan->forceFill([
                    'status' => ContactFlowRoutePlan::STATUS_CANCELLED,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_reason' => $reason,
                ])->save();

                $progress->plan->items()
                    ->whereIn('status', [
                        ContactFlowRoutePlanItem::STATUS_PENDING,
                        ContactFlowRoutePlanItem::STATUS_ACTIVE,
                        ContactFlowRoutePlanItem::STATUS_WAITING,
                    ])
                    ->update([
                        'status' => ContactFlowRoutePlanItem::STATUS_CANCELLED,
                        'cancelled_at' => $cancelledAt,
                        'result_reason' => $reason,
                    ]);
            }

            $progress->forceFill([
                'status' => $status,
                'cancelled_at' => $cancelledAt,
                'resume_at' => null,
                'waiting_event_key' => null,
                'cancellation_reason' => $reason,
                'updated_at' => $cancelledAt,
            ])->save();
        }

        return $progresses->count();
    }
}

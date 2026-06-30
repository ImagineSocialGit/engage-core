<?php

namespace App\Modules\FlowRoutes\Actions;

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

        return ContactFlowRouteProgress::query()
            ->runnable()
            ->forWorkflowProfile($transition->contactWorkflowProfileId)
            ->update([
                'status' => $status,
                'cancelled_at' => $cancelledAt,
                'resume_at' => null,
                'waiting_event_key' => null,
                'cancellation_reason' => $reason,
                'updated_at' => $cancelledAt,
            ]);
    }
}
<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;

class CancelActiveFlowRouteProgressAction
{
    public function handle(
        ContactWorkflowStatusTransition $transition,
        string $status = ContactFlowRouteProgress::STATUS_SUPERSEDED,
        string $reason = 'workflow_status_changed',
        ?int $exceptProgressId = null,
    ): int {
        $cancelledAt = $transition->occurredAt;

        $progresses = ContactFlowRouteProgress::query()
            ->with(['plan.items', 'progressItems'])
            ->runnable()
            ->forWorkflowProfile($transition->contactWorkflowProfileId)
            ->when($exceptProgressId !== null, fn ($query) => $query->whereKeyNot($exceptProgressId))
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
                        ContactFlowRoutePlanItem::STATUS_BLOCKED,
                    ])
                    ->update([
                        'status' => ContactFlowRoutePlanItem::STATUS_CANCELLED,
                        'cancelled_at' => $cancelledAt,
                        'resume_at' => null,
                        'waiting_event_key' => null,
                        'result_reason' => $reason,
                        'result_payload' => json_encode([
                            'status' => ContactFlowRoutePlanItem::STATUS_CANCELLED,
                            'reason' => $reason,
                            'meta' => [
                                'source' => 'flow_routes',
                                'cancelled_by' => 'CancelActiveFlowRouteProgressAction',
                            ],
                        ]),
                    ]);
            }

            $progress->progressItems()
                ->whereIn('status', [
                    ContactFlowRouteProgressItem::STATUS_STARTED,
                    ContactFlowRouteProgressItem::STATUS_WAITING,
                    ContactFlowRouteProgressItem::STATUS_BLOCKED,
                ])
                ->update([
                    'status' => ContactFlowRouteProgressItem::STATUS_CANCELLED,
                    'cancelled_at' => $cancelledAt,
                    'resume_at' => null,
                    'waiting_event_key' => null,
                    'result_reason' => $reason,
                    'result_payload' => json_encode([
                        'status' => ContactFlowRouteProgressItem::STATUS_CANCELLED,
                        'reason' => $reason,
                        'meta' => [
                            'source' => 'flow_routes',
                            'cancelled_by' => 'CancelActiveFlowRouteProgressAction',
                        ],
                    ]),
                ]);

            $meta = $progress->meta ?? [];
            unset($meta['waiting']);
            $meta['cancelled'] = [
                'cancelled_at' => $cancelledAt?->toISOString(),
                'status' => $status,
                'reason' => $reason,
            ];

            $progress->forceFill([
                'status' => $status,
                'cancelled_at' => $cancelledAt,
                'resume_at' => null,
                'waiting_event_key' => null,
                'cancellation_reason' => $reason,
                'meta' => $meta,
                'updated_at' => $cancelledAt,
            ])->save();
        }

        return $progresses->count();
    }
}


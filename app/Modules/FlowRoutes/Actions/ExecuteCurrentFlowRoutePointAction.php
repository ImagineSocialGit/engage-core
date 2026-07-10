<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExecuteCurrentFlowRoutePointAction
{
    public function __construct(
        private readonly PointHandlerRegistry $pointHandlerRegistry,
        private readonly AdvanceContactFlowRouteProgressAction $advanceContactFlowRouteProgress,
        private readonly MarkFlowRouteProgressWaitingAction $markFlowRouteProgressWaiting,
        private readonly CreateContactFlowRoutePlanAction $createContactFlowRoutePlan,
    ) {}

    public function handle(ContactFlowRouteProgress $progress): PointExecutionResult
    {
        return DB::transaction(function () use ($progress) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with([
                    'currentFlowRoutePoint',
                    'currentFlowRoutePoint.capability',
                    'flowRoute.activeFlowRoutePoints',
                    'flowRoute.activeFlowRoutePoints.capability',
                    'contactWorkflowProfile',
                    'plan.items.flowRoutePoint',
                ])
                ->findOrFail($progress->getKey());

            if (! $progress->isActive()) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_not_active',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ],
                );
            }

            $plan = $progress->plan instanceof ContactFlowRoutePlan
                ? $progress->plan
                : $this->createContactFlowRoutePlan->handle($progress, $progress->flowRoute);

            $planItem = $this->currentPlanItem($progress, $plan);

            if (! $planItem instanceof ContactFlowRoutePlanItem) {
                $this->completeProgressWithoutCurrentPoint($progress, $plan);

                return PointExecutionResult::completed(
                    reason: 'flow_route_progress_completed_without_current_plan_item',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_plan_id' => $plan->getKey(),
                    ],
                );
            }

            $flowRoutePoint = $planItem->flowRoutePoint ?: $progress->currentFlowRoutePoint;

            if (! $flowRoutePoint instanceof FlowRoutePoint) {
                $result = PointExecutionResult::failed(
                    reason: 'flow_route_plan_item_missing_route_point',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_plan_id' => $plan->getKey(),
                        'flow_route_plan_item_id' => $planItem->getKey(),
                    ],
                );

                $this->failProgress($progress, $plan, $planItem, null, $result);

                return $result;
            }

            $flowRoutePoint->loadMissing('capability');

            $progressItem = $this->startProgressItem($progress, $plan, $planItem, $flowRoutePoint);

            if (! $flowRoutePoint->is_active) {
                $result = PointExecutionResult::skipped(
                    reason: 'flow_route_point_inactive',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_plan_id' => $plan->getKey(),
                        'flow_route_plan_item_id' => $planItem->getKey(),
                        'flow_route_progress_item_id' => $progressItem->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                    ],
                );

                $this->finishProgressItem($progressItem, $planItem, $result);
                $this->recordExecutionResult($progress, $plan, $planItem, $progressItem, $flowRoutePoint, $result);
                $this->advanceContactFlowRouteProgress->handle($progress, $planItem, $flowRoutePoint, $result);

                return $result;
            }

            $pointType = (string) $flowRoutePoint->type;
            $handler = $this->pointHandlerRegistry->resolve($pointType);

            if (! $handler) {
                $result = PointExecutionResult::failed(
                    reason: 'point_handler_not_registered',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_plan_id' => $plan->getKey(),
                        'flow_route_plan_item_id' => $planItem->getKey(),
                        'flow_route_progress_item_id' => $progressItem->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                        'point_type' => $pointType,
                    ],
                );

                $this->failProgress($progress, $plan, $planItem, $progressItem, $result);

                return $result;
            }

            try {
                $result = $handler->handle($this->executionContext($progress, $plan, $planItem, $progressItem, $flowRoutePoint));
            } catch (Throwable $exception) {
                $result = PointExecutionResult::failed(
                    reason: 'point_handler_exception',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_plan_id' => $plan->getKey(),
                        'flow_route_plan_item_id' => $planItem->getKey(),
                        'flow_route_progress_item_id' => $progressItem->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                        'point_type' => $pointType,
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ],
                );

                $this->failProgress($progress, $plan, $planItem, $progressItem, $result);

                return $result;
            }

            if ($result->isFailed()) {
                $this->failProgress($progress, $plan, $planItem, $progressItem, $result);

                return $result;
            }

            $this->finishProgressItem($progressItem, $planItem, $result);
            $this->recordExecutionResult($progress, $plan, $planItem, $progressItem, $flowRoutePoint, $result);

            if ($result->isWaiting()) {
                $this->markFlowRouteProgressWaiting->handle(
                    progress: $progress,
                    plan: $plan,
                    planItem: $planItem,
                    progressItem: $progressItem,
                    flowRoutePoint: $flowRoutePoint,
                    result: $result,
                );

                return $result;
            }

            if ($result->shouldAdvance()) {
                $this->advanceContactFlowRouteProgress->handle(
                    progress: $progress,
                    fromPlanItem: $planItem,
                    fromFlowRoutePoint: $flowRoutePoint,
                    result: $result,
                );
            }

            return $result;
        });
    }

    private function currentPlanItem(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
    ): ?ContactFlowRoutePlanItem {
        $currentPointId = $progress->current_flow_route_point_id !== null
            ? (int) $progress->current_flow_route_point_id
            : null;

        if ($currentPointId !== null) {
            $current = $plan->items
                ->first(fn (ContactFlowRoutePlanItem $item): bool => (int) $item->flow_route_point_id === $currentPointId && ! in_array($item->status, [
                    ContactFlowRoutePlanItem::STATUS_COMPLETED,
                    ContactFlowRoutePlanItem::STATUS_SKIPPED,
                    ContactFlowRoutePlanItem::STATUS_CANCELLED,
                    ContactFlowRoutePlanItem::STATUS_FAILED,
                ], true));

            if ($current instanceof ContactFlowRoutePlanItem) {
                return $current;
            }
        }

        return $plan->items
            ->first(fn (ContactFlowRoutePlanItem $item): bool => in_array($item->status, [
                ContactFlowRoutePlanItem::STATUS_ACTIVE,
                ContactFlowRoutePlanItem::STATUS_WAITING,
                ContactFlowRoutePlanItem::STATUS_PENDING,
            ], true));
    }

    private function executionContext(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ContactFlowRoutePlanItem $planItem,
        ContactFlowRouteProgressItem $progressItem,
        FlowRoutePoint $flowRoutePoint,
    ): PointExecutionContext {
        return new PointExecutionContext(
            progress: $progress,
            flowRoutePoint: $flowRoutePoint,
            definition: $planItem->definition_snapshot ?? [],
            settings: $planItem->settings_snapshot ?? [],
            meta: [
                'started_from_workflow_transition' => $progress->meta['started_from_workflow_transition'] ?? null,
                'started_from_automation_event' => $progress->meta['started_from_automation_event'] ?? null,
                'waiting' => $progress->waitingState(),
            ],
            plan: $plan,
            planItem: $planItem,
            progressItem: $progressItem,
        );
    }

    private function startProgressItem(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ContactFlowRoutePlanItem $planItem,
        FlowRoutePoint $flowRoutePoint,
    ): ContactFlowRouteProgressItem {
        $now = Carbon::now();
        $attempt = ((int) $planItem->attempt) + 1;

        $planItem->forceFill([
            'status' => ContactFlowRoutePlanItem::STATUS_ACTIVE,
            'attempt' => $attempt,
            'started_at' => $planItem->started_at ?? $now,
        ])->save();

        $progress->forceFill([
            'current_flow_route_point_id' => $flowRoutePoint->getKey(),
        ])->save();

        return ContactFlowRouteProgressItem::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_flow_route_plan_id' => $plan->getKey(),
            'contact_flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_id' => $progress->flow_route_id,
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_capability_id' => $flowRoutePoint->flow_route_capability_id,
            'key' => $planItem->key,
            'point_type' => $flowRoutePoint->type,
            'sequence' => $planItem->sequence,
            'attempt' => $attempt,
            'status' => ContactFlowRouteProgressItem::STATUS_STARTED,
            'started_at' => $now,
            'meta' => [
                'source' => 'flow_routes',
            ],
        ]);
    }

    private function finishProgressItem(
        ContactFlowRouteProgressItem $progressItem,
        ContactFlowRoutePlanItem $planItem,
        PointExecutionResult $result,
    ): void {
        $now = Carbon::now();

        $progressItemStatus = match ($result->status) {
            PointExecutionResult::STATUS_WAITING => ContactFlowRouteProgressItem::STATUS_WAITING,
            PointExecutionResult::STATUS_SKIPPED => ContactFlowRouteProgressItem::STATUS_SKIPPED,
            PointExecutionResult::STATUS_BLOCKED => ContactFlowRouteProgressItem::STATUS_BLOCKED,
            PointExecutionResult::STATUS_FAILED => ContactFlowRouteProgressItem::STATUS_FAILED,
            default => ContactFlowRouteProgressItem::STATUS_COMPLETED,
        };

        $planItemStatus = match ($result->status) {
            PointExecutionResult::STATUS_WAITING => ContactFlowRoutePlanItem::STATUS_WAITING,
            PointExecutionResult::STATUS_SKIPPED => ContactFlowRoutePlanItem::STATUS_SKIPPED,
            PointExecutionResult::STATUS_BLOCKED => ContactFlowRoutePlanItem::STATUS_BLOCKED,
            PointExecutionResult::STATUS_FAILED => ContactFlowRoutePlanItem::STATUS_FAILED,
            default => ContactFlowRoutePlanItem::STATUS_COMPLETED,
        };

        $progressItem->forceFill(array_filter([
            'status' => $progressItemStatus,
            'result_reason' => $result->reason,
            'completed_at' => $progressItemStatus === ContactFlowRouteProgressItem::STATUS_COMPLETED ? $now : null,
            'skipped_at' => $progressItemStatus === ContactFlowRouteProgressItem::STATUS_SKIPPED ? $now : null,
            'failed_at' => $progressItemStatus === ContactFlowRouteProgressItem::STATUS_FAILED ? $now : null,
            'result_payload' => $result->toMetaPayload(),
        ], fn (mixed $value): bool => $value !== null))->save();

        $planItem->forceFill(array_filter([
            'status' => $planItemStatus,
            'result_reason' => $result->reason,
            'completed_at' => $planItemStatus === ContactFlowRoutePlanItem::STATUS_COMPLETED ? $now : null,
            'skipped_at' => $planItemStatus === ContactFlowRoutePlanItem::STATUS_SKIPPED ? $now : null,
            'failed_at' => $planItemStatus === ContactFlowRoutePlanItem::STATUS_FAILED ? $now : null,
            'result_payload' => $result->toMetaPayload(),
        ], fn (mixed $value): bool => $value !== null))->save();
    }

    private function completeProgressWithoutCurrentPoint(ContactFlowRouteProgress $progress, ContactFlowRoutePlan $plan): void
    {
        $completedAt = Carbon::now();

        $plan->forceFill([
            'status' => ContactFlowRoutePlan::STATUS_COMPLETED,
            'completed_at' => $completedAt,
        ])->save();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'resume_at' => null,
            'waiting_event_key' => null,
            'meta' => array_replace_recursive($progress->meta ?? [], [
                'waiting' => null,
                'completed' => [
                    'completed_at' => $completedAt->toISOString(),
                    'reason' => 'no_current_flow_route_plan_item',
                ],
            ]),
        ])->save();
    }

    private function recordExecutionResult(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ContactFlowRoutePlanItem $planItem,
        ContactFlowRouteProgressItem $progressItem,
        FlowRoutePoint $flowRoutePoint,
        PointExecutionResult $result,
    ): void {
        $executedAt = Carbon::now();
        $meta = $progress->meta ?? [];

        $payload = [
            'executed_at' => $executedAt->toISOString(),
            'flow_route_plan_id' => $plan->getKey(),
            'flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_progress_item_id' => $progressItem->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_point_key' => $flowRoutePoint->key,
            'point_type' => $flowRoutePoint->type,
            'result' => $result->toMetaPayload(),
        ];

        $meta['last_point_execution'] = $payload;
        $history = $meta['point_execution_history'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $payload;
        $meta['point_execution_history'] = array_slice($history, -50);

        $progress->forceFill(['meta' => $meta])->save();
    }

    private function failProgress(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ?ContactFlowRoutePlanItem $planItem,
        ?ContactFlowRouteProgressItem $progressItem,
        PointExecutionResult $result,
    ): void {
        $failedAt = Carbon::now();

        if ($progressItem instanceof ContactFlowRouteProgressItem && $planItem instanceof ContactFlowRoutePlanItem) {
            $this->finishProgressItem($progressItem, $planItem, $result);
        }

        $plan->forceFill([
            'status' => ContactFlowRoutePlan::STATUS_FAILED,
            'failed_at' => $failedAt,
            'failure_reason' => $result->reason,
        ])->save();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_FAILED,
            'failed_at' => $failedAt,
            'resume_at' => null,
            'waiting_event_key' => null,
            'failure_reason' => $result->reason,
            'meta' => array_replace_recursive($progress->meta ?? [], [
                'waiting' => null,
                'failed' => [
                    'failed_at' => $failedAt->toISOString(),
                    'flow_route_plan_id' => $plan->getKey(),
                    'flow_route_plan_item_id' => $planItem?->getKey(),
                    'flow_route_progress_item_id' => $progressItem?->getKey(),
                    'result' => $result->toMetaPayload(),
                ],
            ]),
        ])->save();
    }
}

<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionContext;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
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
    ) {}

    public function handle(ContactFlowRouteProgress $progress): PointExecutionResult
    {
        return DB::transaction(function () use ($progress) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with([
                    'currentFlowRoutePoint.point',
                    'flowRoute',
                    'contactWorkflowProfile',
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

            $flowRoutePoint = $progress->currentFlowRoutePoint;

            if (! $flowRoutePoint instanceof FlowRoutePoint) {
                $this->completeProgressWithoutCurrentPoint($progress);

                return PointExecutionResult::completed(
                    reason: 'flow_route_progress_completed_without_current_point',
                    meta: [
                        'progress_id' => $progress->getKey(),
                    ],
                );
            }

            $flowRoutePoint->loadMissing('point');

            if (! $flowRoutePoint->is_active) {
                $result = PointExecutionResult::skipped(
                    reason: 'flow_route_point_inactive',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                    ],
                );

                $this->recordExecutionResult($progress, $flowRoutePoint, $result);

                $this->advanceContactFlowRouteProgress->handle(
                    progress: $progress,
                    fromFlowRoutePoint: $flowRoutePoint,
                    result: $result,
                );

                return $result;
            }

            if (! $flowRoutePoint->point || ! $flowRoutePoint->point->is_active) {
                $result = PointExecutionResult::skipped(
                    reason: 'point_inactive_or_missing',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                        'point_id' => $flowRoutePoint->point_id,
                    ],
                );

                $this->recordExecutionResult($progress, $flowRoutePoint, $result);

                $this->advanceContactFlowRouteProgress->handle(
                    progress: $progress,
                    fromFlowRoutePoint: $flowRoutePoint,
                    result: $result,
                );

                return $result;
            }

            $pointType = (string) $flowRoutePoint->point->type;
            $handler = $this->pointHandlerRegistry->resolve($pointType);

            if (! $handler) {
                $result = PointExecutionResult::failed(
                    reason: 'point_handler_not_registered',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                        'point_id' => $flowRoutePoint->point_id,
                        'point_type' => $pointType,
                    ],
                );

                $this->failProgress($progress, $flowRoutePoint, $result);

                return $result;
            }

            try {
                $result = $handler->handle($this->executionContext($progress, $flowRoutePoint));
            } catch (Throwable $exception) {
                $result = PointExecutionResult::failed(
                    reason: 'point_handler_exception',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'flow_route_point_id' => $flowRoutePoint->getKey(),
                        'flow_route_point_key' => $flowRoutePoint->key,
                        'point_id' => $flowRoutePoint->point_id,
                        'point_type' => $pointType,
                        'exception_class' => $exception::class,
                        'exception_message' => $exception->getMessage(),
                    ],
                );

                $this->failProgress($progress, $flowRoutePoint, $result);

                return $result;
            }

            if ($result->isFailed()) {
                $this->failProgress($progress, $flowRoutePoint, $result);

                return $result;
            }

            $this->recordExecutionResult($progress, $flowRoutePoint, $result);

            if ($result->isWaiting()) {
                $this->markFlowRouteProgressWaiting->handle(
                    progress: $progress,
                    flowRoutePoint: $flowRoutePoint,
                    result: $result,
                );

                return $result;
            }

            if ($result->shouldAdvance()) {
                $this->advanceContactFlowRouteProgress->handle(
                    progress: $progress,
                    fromFlowRoutePoint: $flowRoutePoint,
                    result: $result,
                );
            }

            return $result;
        });
    }

    private function executionContext(
        ContactFlowRouteProgress $progress,
        FlowRoutePoint $flowRoutePoint,
    ): PointExecutionContext {
        $point = $flowRoutePoint->point;

        return new PointExecutionContext(
            progress: $progress,
            flowRoutePoint: $flowRoutePoint,
            definition: $this->mergedArray(
                base: $point->default_definition ?? [],
                override: $flowRoutePoint->definition ?? [],
            ),
            settings: $this->mergedArray(
                base: $point->default_settings ?? [],
                override: $flowRoutePoint->settings ?? [],
            ),
            meta: [
                'started_from_workflow_transition' => $progress->meta['started_from_workflow_transition'] ?? null,
                'started_from_automation_event' => $progress->meta['started_from_automation_event'] ?? null,
                'waiting' => $progress->waitingState(),
            ],
        );
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergedArray(array $base, array $override): array
    {
        return array_replace_recursive($base, $override);
    }

    private function completeProgressWithoutCurrentPoint(ContactFlowRouteProgress $progress): void
    {
        $completedAt = Carbon::now();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_COMPLETED,
            'completed_at' => $completedAt,
            'resume_at' => null,
            'waiting_event_key' => null,
            'meta' => array_replace_recursive($progress->meta ?? [], [
                'waiting' => null,
                'completed' => [
                    'completed_at' => $completedAt->toISOString(),
                    'reason' => 'no_current_flow_route_point',
                ],
            ]),
        ])->save();
    }

    private function recordExecutionResult(
        ContactFlowRouteProgress $progress,
        FlowRoutePoint $flowRoutePoint,
        PointExecutionResult $result,
    ): void {
        $executedAt = Carbon::now();
        $meta = $progress->meta ?? [];

        $payload = [
            'executed_at' => $executedAt->toISOString(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_point_key' => $flowRoutePoint->key,
            'point_id' => $flowRoutePoint->point_id,
            'point_key' => $flowRoutePoint->point?->key,
            'point_type' => $flowRoutePoint->point?->type,
            'result' => $result->toMetaPayload(),
        ];

        $meta['last_point_execution'] = $payload;

        $history = $meta['point_execution_history'] ?? [];

        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $payload;

        $meta['point_execution_history'] = array_slice($history, -50);

        $progress->forceFill([
            'meta' => $meta,
        ])->save();
    }

    private function failProgress(
        ContactFlowRouteProgress $progress,
        FlowRoutePoint $flowRoutePoint,
        PointExecutionResult $result,
    ): void {
        $failedAt = Carbon::now();

        $this->recordExecutionResult($progress, $flowRoutePoint, $result);

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
                    'flow_route_point_id' => $flowRoutePoint->getKey(),
                    'flow_route_point_key' => $flowRoutePoint->key,
                    'point_id' => $flowRoutePoint->point_id,
                    'point_key' => $flowRoutePoint->point?->key,
                    'result' => $result->toMetaPayload(),
                ],
            ]),
        ])->save();
    }
}
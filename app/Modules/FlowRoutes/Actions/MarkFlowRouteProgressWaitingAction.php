<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

class MarkFlowRouteProgressWaitingAction
{
    public function handle(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ContactFlowRoutePlanItem $planItem,
        ContactFlowRouteProgressItem $progressItem,
        FlowRoutePoint $flowRoutePoint,
        PointExecutionResult $result,
    ): ContactFlowRouteProgress {
        $waitingAt = Carbon::now();
        $resultWait = $result->meta['wait'] ?? [];

        if (! is_array($resultWait)) {
            $resultWait = [];
        }

        $resumeAt = $this->resumeAt($resultWait);
        $waitingEventKey = $this->waitingEventKey($resultWait);
        $correlation = $this->correlation($resultWait);

        $waitingState = array_replace_recursive([
            'flow_route_plan_id' => $plan->getKey(),
            'flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_progress_item_id' => $progressItem->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_point_key' => $flowRoutePoint->key,
            'point_type' => $flowRoutePoint->type,
            'waiting_at' => $waitingAt->toISOString(),
            'resume_at' => $resumeAt?->toISOString(),
            'expected_event' => $waitingEventKey,
            'correlation' => $correlation,
            'reason' => $result->reason,
            'resume_job_dispatched_at' => $resumeAt instanceof CarbonImmutable ? $waitingAt->toISOString() : null,
        ], $resultWait);

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_WAITING,
            'current_flow_route_point_id' => $flowRoutePoint->getKey(),
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'meta' => array_replace_recursive($progress->meta ?? [], [
                'waiting' => $waitingState,
            ]),
        ])->save();

        $planItem->forceFill([
            'status' => ContactFlowRoutePlanItem::STATUS_WAITING,
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'correlation' => $correlation,
            'result_payload' => $result->toMetaPayload(),
        ])->save();

        $progressItem->forceFill([
            'status' => ContactFlowRouteProgressItem::STATUS_WAITING,
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'correlation' => $correlation,
            'result_payload' => $result->toMetaPayload(),
        ])->save();

        if ($resumeAt instanceof CarbonImmutable) {
            ResumeFlowRouteProgressJob::dispatch($progress->getKey())
                ->delay($resumeAt)
                ->afterCommit();
        }

        return $progress->refresh();
    }

    /**
     * @param array<string, mixed> $waitingState
     */
    private function resumeAt(array $waitingState): ?CarbonImmutable
    {
        $resumeAt = $waitingState['resume_at'] ?? null;

        if (! is_string($resumeAt) || trim($resumeAt) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($resumeAt)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $waitingState
     */
    private function waitingEventKey(array $waitingState): ?string
    {
        $eventKey = $waitingState['expected_event'] ?? null;

        if (! is_string($eventKey)) {
            return null;
        }

        $eventKey = trim($eventKey);

        return $eventKey !== '' ? $eventKey : null;
    }

    /**
     * @param array<string, mixed> $waitingState
     * @return array<string, mixed>
     */
    private function correlation(array $waitingState): array
    {
        $correlation = $waitingState['correlation'] ?? [];

        return is_array($correlation) ? $correlation : [];
    }
}

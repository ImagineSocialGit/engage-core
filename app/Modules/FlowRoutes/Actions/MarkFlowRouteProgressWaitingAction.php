<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Throwable;

class MarkFlowRouteProgressWaitingAction
{
    public function handle(
        ContactFlowRouteProgress $progress,
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

        $waitingState = array_replace_recursive([
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_point_key' => $flowRoutePoint->key,
            'point_id' => $flowRoutePoint->point_id,
            'point_key' => $flowRoutePoint->point?->key,
            'waiting_at' => $waitingAt->toISOString(),
            'resume_at' => $resumeAt?->toISOString(),
            'expected_event' => $waitingEventKey,
            'reason' => $result->reason,
            'resume_job_dispatched_at' => $resumeAt instanceof CarbonImmutable
                ? $waitingAt->toISOString()
                : null,
        ], $resultWait);

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_WAITING,
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'meta' => array_replace_recursive($progress->meta ?? [], [
                'waiting' => $waitingState,
            ]),
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
}
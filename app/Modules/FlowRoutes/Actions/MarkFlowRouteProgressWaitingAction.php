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

        $waitingState = array_replace_recursive([
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'point_id' => $flowRoutePoint->point_id,
            'waiting_at' => $waitingAt->toISOString(),
            'resume_at' => $resumeAt?->toISOString(),
            'reason' => $result->reason,
            'resume_job_dispatched_at' => $waitingAt->toISOString(),
        ], $resultWait);

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_WAITING,
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
}
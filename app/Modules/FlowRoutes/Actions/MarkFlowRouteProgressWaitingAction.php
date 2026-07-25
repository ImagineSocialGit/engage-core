<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Carbon\CarbonImmutable;
use Throwable;

class MarkFlowRouteProgressWaitingAction
{
    public function __construct(
        private readonly FlowRouteProgressMetaCanonicalizer $progressMetaCanonicalizer,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlan $plan,
        ContactFlowRoutePlanItem $planItem,
        ContactFlowRouteProgressItem $progressItem,
        FlowRoutePoint $flowRoutePoint,
        PointExecutionResult $result,
    ): ContactFlowRouteProgress {
        $resultWait = $result->meta['wait'] ?? [];

        if (! is_array($resultWait)) {
            $resultWait = [];
        }

        $resumeAt = $this->resumeAt($resultWait);
        $waitingEventKey = $this->waitingEventKey($resultWait);
        $correlation = $this->correlation($resultWait);

        $waitingState = [
            'flow_route_plan_id' => $plan->getKey(),
            'flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_progress_item_id' => $progressItem->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'correlation' => $correlation,
        ];

        $meta = $this->progressMetaCanonicalizer->forPersistence(
            array_replace($progress->meta ?? [], [
                'waiting' => $waitingState,
            ]),
        );

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_WAITING,
            'current_flow_route_point_id' => $flowRoutePoint->getKey(),
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'meta' => $meta,
        ])->save();

        $planItem->forceFill([
            'status' => ContactFlowRoutePlanItem::STATUS_WAITING,
            'resume_at' => $resumeAt,
            'waiting_event_key' => $waitingEventKey,
            'correlation' => $correlation,
            'result_payload' => null,
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
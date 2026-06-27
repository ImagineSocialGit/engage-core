<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Data\FlowRouteExternalEventResumeResult;
use App\Modules\FlowRoutes\Data\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Support\Facades\DB;

class ResumeFlowRouteProgressFromEventAction
{
    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
    ) {}

    public function handle(FlowRouteExternalEvent $event): FlowRouteExternalEventResumeResult
    {
        $result = new FlowRouteExternalEventResumeResult();

        if (trim($event->name) === '') {
            return $result;
        }

        $query = ContactFlowRouteProgress::query()
            ->waiting()
            ->with('currentFlowRoutePoint.point');

        if ($event->contactId !== null) {
            $query->forContact($event->contactId);
        }

        $query->chunkById(100, function ($progresses) use ($event, $result) {
            foreach ($progresses as $progress) {
                $result->recordChecked();

                if (! $this->matches($progress, $event)) {
                    $result->recordIgnored();

                    continue;
                }

                $prepared = $this->prepareProgressForEventResume($progress, $event);

                if ($prepared instanceof PointExecutionResult) {
                    $result->recordResumed($prepared);

                    continue;
                }

                $result->recordMatched($prepared->getKey());

                $executionResult = $this->executeCurrentFlowRoutePoint->handle($prepared);

                $result->recordResumed($executionResult);
            }
        });

        return $result;
    }

    private function prepareProgressForEventResume(
        ContactFlowRouteProgress $progress,
        FlowRouteExternalEvent $event,
    ): ContactFlowRouteProgress|PointExecutionResult {
        return DB::transaction(function () use ($progress, $event) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with('currentFlowRoutePoint.point')
                ->findOrFail($progress->getKey());

            if ($progress->isTerminal()) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_terminal',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ],
                );
            }

            if (! $progress->isWaiting()) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_progress_not_waiting',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'progress_status' => $progress->status,
                    ],
                );
            }

            if (! $this->matches($progress, $event)) {
                return PointExecutionResult::blocked(
                    reason: 'flow_route_external_event_no_longer_matches',
                    meta: [
                        'progress_id' => $progress->getKey(),
                        'event' => $event->toMetaPayload(),
                    ],
                );
            }

            $meta = $progress->meta ?? [];
            $waiting = $progress->waitingState();

            $waiting['matched_event'] = $event->toMetaPayload();
            $waiting['matched_at'] = now()->toISOString();

            $resumeAttempts = $meta['event_resume_attempts'] ?? [];

            if (! is_array($resumeAttempts)) {
                $resumeAttempts = [];
            }

            $resumeAttempts[] = [
                'attempted_at' => now()->toISOString(),
                'waiting' => $waiting,
                'event' => $event->toMetaPayload(),
            ];

            $meta['waiting'] = $waiting;
            $meta['event_resume_attempts'] = array_slice($resumeAttempts, -50);
            $meta['last_event_resume_attempt'] = end($meta['event_resume_attempts']) ?: null;

            $progress->forceFill([
                'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
                'meta' => $meta,
            ])->save();

            return $progress->refresh();
        });
    }

    private function matches(
        ContactFlowRouteProgress $progress,
        FlowRouteExternalEvent $event,
    ): bool {
        $waiting = $progress->waitingState();

        $expectedEvent = $waiting['expected_event'] ?? null;

        if (! is_string($expectedEvent) || trim($expectedEvent) === '') {
            return false;
        }

        if ($expectedEvent !== $event->name) {
            return false;
        }

        $waitingFlowRoutePointId = $progress->waitingFlowRoutePointId();

        if (
            $waitingFlowRoutePointId !== null
            && $progress->current_flow_route_point_id !== null
            && $waitingFlowRoutePointId !== (int) $progress->current_flow_route_point_id
        ) {
            return false;
        }

        if (
            $event->contactId !== null
            && (int) $progress->contact_id !== $event->contactId
        ) {
            return false;
        }

        $correlation = $waiting['correlation'] ?? [];

        if (! is_array($correlation) || $correlation === []) {
            return $event->contactId !== null
                && (int) $progress->contact_id === $event->contactId;
        }

        foreach ($correlation as $key => $expectedValue) {
            if (! is_string($key) || trim($key) === '') {
                return false;
            }

            $actualValue = $this->eventValue($event, $key);

            if (! $this->correlationValueMatches(
                progress: $progress,
                expectedValue: $expectedValue,
                actualValue: $actualValue,
            )) {
                return false;
            }
        }

        return true;
    }

    private function eventValue(FlowRouteExternalEvent $event, string $key): mixed
    {
        if (str_starts_with($key, 'payload.')) {
            return data_get($event->payload, substr($key, 8));
        }

        return $event->value($key);
    }

    private function correlationValueMatches(
        ContactFlowRouteProgress $progress,
        mixed $expectedValue,
        mixed $actualValue,
    ): bool {
        if ($expectedValue === true) {
            return $actualValue !== null && $actualValue !== '';
        }

        if (is_array($expectedValue)) {
            return in_array($actualValue, array_map(
                fn (mixed $value) => $this->resolvedExpectedValue($progress, $value),
                $expectedValue,
            ), true);
        }

        return $actualValue === $this->resolvedExpectedValue($progress, $expectedValue);
    }

    private function resolvedExpectedValue(
        ContactFlowRouteProgress $progress,
        mixed $expectedValue,
    ): mixed {
        return match ($expectedValue) {
            '{contact.id}' => (int) $progress->contact_id,
            '{contact_status.id}' => (int) $progress->contact_status_id,
            '{workflow_profile.id}' => (int) $progress->contact_workflow_profile_id,
            '{flow_route.id}' => (int) $progress->flow_route_id,
            '{flow_route_point.id}' => (int) $progress->current_flow_route_point_id,
            default => $expectedValue,
        };
    }
}
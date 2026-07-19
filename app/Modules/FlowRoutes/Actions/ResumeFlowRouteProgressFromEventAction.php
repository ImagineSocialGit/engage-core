<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEventResumeResult;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use Illuminate\Support\Facades\DB;

class ResumeFlowRouteProgressFromEventAction
{
    private const TASK_COMPLETED_EVENT = 'task.completed';

    public function __construct(
        private readonly ExecuteFlowRouteProgressUntilIdleAction $executeFlowRouteProgressUntilIdle,
    ) {}

    public function handle(FlowRouteExternalEvent $event): FlowRouteExternalEventResumeResult
    {
        $result = new FlowRouteExternalEventResumeResult();

        if (trim($event->name) === '') {
            return $result;
        }

        $query = ContactFlowRouteProgress::query()
            ->waitingForEvent($event->name)
            ->with(['currentFlowRoutePoint', 'plan.items']);

        if ($event->contactId !== null) {
            $query->forContact($event->contactId);
        }

        if ($this->shouldApplyEventSubjectQueryFilter($event)) {
            $query->forSubject($event->subjectType, $event->subjectId);
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

                $executionResult = $this->executeFlowRouteProgressUntilIdle->handle(
                    progress: $prepared,
                    source: 'automation_event_resume',
                );

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
                ->with(['currentFlowRoutePoint', 'plan.items'])
                ->findOrFail($progress->getKey());

            if ($progress->isTerminal()) {
                return PointExecutionResult::blocked('flow_route_progress_terminal', [
                    'progress_id' => $progress->getKey(),
                    'progress_status' => $progress->status,
                ]);
            }

            if (! $progress->isWaiting()) {
                return PointExecutionResult::blocked('flow_route_progress_not_waiting', [
                    'progress_id' => $progress->getKey(),
                    'progress_status' => $progress->status,
                ]);
            }

            if (! $this->matches($progress, $event)) {
                return PointExecutionResult::blocked('flow_route_external_event_no_longer_matches', [
                    'progress_id' => $progress->getKey(),
                    'event' => $event->toMetaPayload(),
                ]);
            }

            $now = now();
            $meta = $progress->meta ?? [];
            $waiting = $progress->waitingState();

            $waiting['matched_event'] = $event->toMetaPayload();
            $waiting['matched_at'] = $now->toISOString();

            $waitingPlanItemId = $this->nullableInt($waiting['flow_route_plan_item_id'] ?? null);
            $waitingProgressItemId = $this->nullableInt($waiting['flow_route_progress_item_id'] ?? null);

            if ($waitingPlanItemId !== null) {
                ContactFlowRoutePlanItem::query()
                    ->whereKey($waitingPlanItemId)
                    ->where('contact_flow_route_progress_id', $progress->getKey())
                    ->where('status', ContactFlowRoutePlanItem::STATUS_WAITING)
                    ->update([
                        'status' => ContactFlowRoutePlanItem::STATUS_ACTIVE,
                        'result_payload' => [
                            'matched_event' => $event->toMetaPayload(),
                        ],
                    ]);
            }

            if ($waitingProgressItemId !== null) {
                ContactFlowRouteProgressItem::query()
                    ->whereKey($waitingProgressItemId)
                    ->where('contact_flow_route_progress_id', $progress->getKey())
                    ->where('status', ContactFlowRouteProgressItem::STATUS_WAITING)
                    ->update([
                        'status' => ContactFlowRouteProgressItem::STATUS_COMPLETED,
                        'completed_at' => $now,
                        'resume_at' => null,
                        'waiting_event_key' => null,
                        'result_reason' => 'event_wait_matched',
                        'result_payload' => [
                            'matched_event' => $event->toMetaPayload(),
                        ],
                    ]);
            }

            $resumeAttempts = is_array($meta['event_resume_attempts'] ?? null)
                ? $meta['event_resume_attempts']
                : [];

            $resumeAttempts[] = [
                'attempted_at' => $now->toISOString(),
                'waiting' => $waiting,
                'event' => $event->toMetaPayload(),
            ];

            $meta['waiting'] = $waiting;
            $meta['event_resume_attempts'] = array_slice($resumeAttempts, -50);
            $meta['last_event_resume_attempt'] = end($meta['event_resume_attempts']) ?: null;

            $progress->forceFill([
                'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
                'resume_at' => null,
                'meta' => $meta,
            ])->save();

            return $progress->refresh();
        });
    }

    private function matches(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        foreach ($this->matchRules() as $rule) {
            if (! $this->{$rule}($progress, $event)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function matchRules(): array
    {
        return [
            'matchesExpectedEvent',
            'matchesWaitingPoint',
            'matchesContactWhenPresent',
            'matchesSubjectWhenRequired',
            'matchesCorrelationPolicy',
        ];
    }

    private function matchesExpectedEvent(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        return $progress->waitingExpectedEvent() === $event->name;
    }

    private function matchesWaitingPoint(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        $waitingFlowRoutePointId = $progress->waitingFlowRoutePointId();

        return $waitingFlowRoutePointId === null
            || $progress->current_flow_route_point_id === null
            || $waitingFlowRoutePointId === (int) $progress->current_flow_route_point_id;
    }

    private function matchesContactWhenPresent(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        return $event->contactId === null
            || (int) $progress->contact_id === $event->contactId;
    }

    private function matchesSubjectWhenRequired(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        if ($this->isTaskCompletedEvent($event)) {
            return true;
        }

        if ($progress->subject_type === null && $progress->subject_id === null) {
            return true;
        }

        return $progress->subject_type === $event->subjectType
            && (string) $progress->subject_id === (string) $event->subjectId;
    }

    private function matchesCorrelationPolicy(ContactFlowRouteProgress $progress, FlowRouteExternalEvent $event): bool
    {
        $correlation = $progress->waitingCorrelation();

        if ($this->isTaskCompletedEvent($event)) {
            if (! $this->matchesRouteCreatedTaskArtifact($progress, $event)) {
                return false;
            }

            if ($correlation !== []) {
                return $this->matchesExplicitCorrelation(
                    $progress,
                    $event,
                    $correlation,
                );
            }

            return $this->hasSingleRouteCreatedTaskArtifact($progress);
        }

        if ($correlation !== []) {
            return $this->matchesExplicitCorrelation($progress, $event, $correlation);
        }

        return $event->contactId !== null
            && (int) $progress->contact_id === $event->contactId;
    }

    /**
     * @param array<string, mixed> $correlation
     */
    private function matchesExplicitCorrelation(
        ContactFlowRouteProgress $progress,
        FlowRouteExternalEvent $event,
        array $correlation,
    ): bool {
        foreach ($correlation as $key => $expectedValue) {
            if (! is_string($key) || trim($key) === '') {
                return false;
            }

            $actualValue = $this->eventValue($event, $key);

            if (! $this->correlationValueMatches($progress, $expectedValue, $actualValue)) {
                return false;
            }
        }

        return true;
    }

    private function matchesRouteCreatedTaskArtifact(
        ContactFlowRouteProgress $progress,
        FlowRouteExternalEvent $event,
    ): bool {
        $taskId = $this->taskId($event);

        if ($taskId === null) {
            return false;
        }

        return ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('correlation_type', 'task')
            ->where('created_subject_id', $taskId)
            ->when(
                $event->subjectType !== null,
                fn ($query) => $query->where(
                    'created_subject_type',
                    $event->subjectType,
                ),
            )
            ->exists();
    }

    private function hasSingleRouteCreatedTaskArtifact(
        ContactFlowRouteProgress $progress,
    ): bool {
        return ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('correlation_type', 'task')
            ->whereNotNull('created_subject_type')
            ->whereNotNull('created_subject_id')
            ->get(['created_subject_type', 'created_subject_id'])
            ->unique(fn (ContactFlowRouteProgressItem $item): string => implode(':', [
                $item->created_subject_type,
                $item->created_subject_id,
            ]))
            ->count() === 1;
    }

    private function shouldApplyEventSubjectQueryFilter(FlowRouteExternalEvent $event): bool
    {
        return ! $this->isTaskCompletedEvent($event)
            && ($event->subjectType !== null || $event->subjectId !== null);
    }

    private function isTaskCompletedEvent(FlowRouteExternalEvent $event): bool
    {
        return $event->name === self::TASK_COMPLETED_EVENT;
    }

    private function taskId(FlowRouteExternalEvent $event): ?int
    {
        return $this->nullableInt($this->eventValue($event, 'task.id'))
            ?? $this->nullableInt($event->subjectId);
    }

    private function eventValue(FlowRouteExternalEvent $event, string $key): mixed
    {
        if (str_starts_with($key, 'payload.')) {
            return data_get($event->payload, substr($key, 8));
        }

        if (str_starts_with($key, 'meta.')) {
            return data_get($event->payload['automation_event_meta'] ?? [], substr($key, 5));
        }

        if (str_starts_with($key, 'automation_event_meta.')) {
            return data_get($event->payload['automation_event_meta'] ?? [], substr($key, 22));
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

    private function resolvedExpectedValue(ContactFlowRouteProgress $progress, mixed $expectedValue): mixed
    {
        $waiting = $progress->waitingState();

        return match ($expectedValue) {
            '{contact.id}' => (int) $progress->contact_id,
            '{contact_status.id}' => $progress->contact_status_id !== null ? (int) $progress->contact_status_id : null,
            '{workflow_profile.id}' => $progress->contact_workflow_profile_id !== null ? (int) $progress->contact_workflow_profile_id : null,
            '{flow_route_progress.id}' => (int) $progress->getKey(),
            '{flow_route_plan.id}' => $this->nullableInt($waiting['flow_route_plan_id'] ?? null),
            '{flow_route_plan_item.id}' => $this->nullableInt($waiting['flow_route_plan_item_id'] ?? null),
            '{flow_route_progress_item.id}' => $this->nullableInt($waiting['flow_route_progress_item_id'] ?? null),
            '{flow_route.id}' => (int) $progress->flow_route_id,
            '{flow_route_point.id}' => $progress->current_flow_route_point_id !== null ? (int) $progress->current_flow_route_point_id : null,
            '{subject.type}' => $progress->subject_type,
            '{subject.id}' => $progress->subject_id !== null ? (int) $progress->subject_id : null,
            default => $expectedValue,
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
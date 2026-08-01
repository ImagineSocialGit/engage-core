<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Actions\StartWebinarMessageChainEnrollmentAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarFollowUpDispatchResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchPostWebinarFollowUpsAction
{
    private const OUTCOME_AREA_KEYS = [
        'post_attended',
        'post_missed',
    ];

    private const IN_PROGRESS_STALE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly ConditionChecker $conditionChecker,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly StartWebinarMessageChainEnrollmentAction $startMessageChainEnrollment,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        $webinar = $webinar->fresh() ?? $webinar;
        $followUpsComplete = true;

        if ($this->hasEnabledOutcomeMessages()) {
            $conditions = config('webinars.post_event.outcome_messages.conditions', []);
            $conditionsPass = ! is_array($conditions)
                || $this->conditionChecker->passes(
                    $conditions,
                    $this->conditionContext($webinar, $event),
                );

            if (! $conditionsPass) {
                $followUpsComplete = false;
            } elseif (! data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at')) {
                $this->dispatchTransactionalFollowUps($webinar);
                $followUpsComplete = $this->refreshWebinarFollowUpCompletion($webinar);
                $webinar = $webinar->fresh() ?? $webinar;
            }
        }

        if (! data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at')) {
            $this->emitWebinarAutomationEvent->forWebinar(
                eventKey: config('webinars.post_event.automation_events.webinar_ended.event_key', 'webinar.ended'),
                webinar: $webinar,
                occurredAt: $webinar->ends_at ?? now(),
                payload: [
                    'provider' => [
                        'key' => $provider->key(),
                    ],
                    'post_event' => [
                        'event' => $event,
                    ],
                ],
            );

            $this->markMeta($webinar, [
                'automation_events' => [
                    'webinar_ended_recorded_at' => now()->toIso8601String(),
                ],
            ]);
        }

        return $followUpsComplete;
    }

    public function executeForRegistration(
        WebinarRegistration $registration,
    ): WebinarFollowUpDispatchResult {
        $registration = $registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]) ?? $registration;

        $outcome = filled($registration->attended_at) ? 'attended' : 'missed';
        $claim = $this->claimAttempt($registration, $outcome);

        if ($claim instanceof WebinarFollowUpDispatchResult) {
            return $claim;
        }

        try {
            if ($registration->status === 'cancelled' || filled($registration->cancelled_at)) {
                return $this->recordNotApplicable(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'registration_cancelled',
                );
            }

            if (! $registration->webinar) {
                return $this->recordFailure(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'webinar_missing',
                );
            }

            if (! $registration->contact) {
                return $this->recordFailure(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'contact_missing',
                );
            }

            $areaKey = $outcome === 'attended'
                ? 'post_attended'
                : 'post_missed';
            $messageArea = $this->messageAreaRegistry->get($areaKey);

            if (! $messageArea?->enabled || ! $messageArea->isTemplate()) {
                return $this->recordNotApplicable(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'message_area_disabled',
                );
            }

            $enrollment = $this->startMessageChainEnrollment->handle(
                webinar: $registration->webinar,
                messageAreaKey: $areaKey,
                recipient: $registration->contact,
                context: $registration,
                startedAt: now(),
            );

            if (! $this->enrollmentHasPlannedDelivery($enrollment)) {
                return $this->recordNotApplicable(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'no_channels_eligible',
                    messageChainEnrollmentId: (int) $enrollment->getKey(),
                );
            }

            $enrollmentId = (int) $enrollment->getKey();

            $this->updateClaimedState($registration, [
                'status' => 'scheduled',
                'outcome' => $outcome,
                'message_chain_enrollment_id' => $enrollmentId,
                'completed_at' => now()->toISOString(),
                'reason' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ]);

            return new WebinarFollowUpDispatchResult(
                status: WebinarFollowUpDispatchResult::STATUS_SCHEDULED,
                registrationId: (int) $registration->getKey(),
                outcome: $outcome,
                messageChainEnrollmentId: $enrollmentId,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->recordFailure(
                registration: $registration,
                outcome: $outcome,
                reason: 'message_chain_enrollment_exception',
                exception: $exception,
            );
        }
    }

    public function refreshWebinarFollowUpCompletion(Webinar $webinar): bool
    {
        $registrations = WebinarRegistration::query()
            ->where('webinar_id', $webinar->getKey())
            ->get(['id', 'meta']);

        $counts = [
            'scheduled' => 0,
            'not_applicable' => 0,
            'failed' => 0,
            'in_progress' => 0,
            'unresolved' => 0,
        ];

        foreach ($registrations as $registration) {
            $status = data_get($registration->meta, 'post_event_follow_up.status');

            if ($status === 'scheduled') {
                $counts['scheduled']++;
            } elseif ($status === 'not_applicable') {
                $counts['not_applicable']++;
            } elseif ($status === 'failed') {
                $counts['failed']++;
            } elseif ($status === 'planning') {
                $counts['in_progress']++;
            } else {
                $counts['unresolved']++;
            }
        }

        $complete = $counts['failed'] === 0
            && $counts['in_progress'] === 0
            && $counts['unresolved'] === 0;

        $postEvent = [
            'follow_up_summary' => [
                'complete' => $complete,
                'registrations_total' => $registrations->count(),
                ...$counts,
                'updated_at' => now()->toISOString(),
            ],
        ];

        if ($complete) {
            $postEvent['follow_ups_dispatched_at'] = data_get(
                $webinar->fresh()?->meta,
                'normalized.post_event.follow_ups_dispatched_at',
            ) ?? now()->toISOString();
        }

        $this->markMeta($webinar, [
            'normalized' => [
                'post_event' => $postEvent,
            ],
        ]);

        return $complete;
    }

    /** @return array<int, WebinarFollowUpDispatchResult> */
    private function dispatchTransactionalFollowUps(Webinar $webinar): array
    {
        return WebinarRegistration::query()
            ->where('webinar_id', $webinar->getKey())
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->get()
            ->map(fn (WebinarRegistration $registration): WebinarFollowUpDispatchResult =>
                $this->executeForRegistration($registration)
            )
            ->all();
    }

    private function enrollmentHasPlannedDelivery(
        MessageChainEnrollment $enrollment,
    ): bool {
        return $enrollment->scheduledMessages->isNotEmpty()
            || ($enrollment->isActive() && $enrollment->next_action_at !== null);
    }

    private function claimAttempt(
        WebinarRegistration $registration,
        string $outcome,
    ): true|WebinarFollowUpDispatchResult {
        return DB::transaction(function () use ($registration, $outcome): true|WebinarFollowUpDispatchResult {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = is_array($meta['post_event_follow_up'] ?? null)
                ? $meta['post_event_follow_up']
                : [];
            $storedOutcome = is_string($state['outcome'] ?? null)
                ? $state['outcome']
                : $outcome;
            $enrollmentId = $this->messageChainEnrollmentId($state);

            if (($state['status'] ?? null) === 'scheduled') {
                return new WebinarFollowUpDispatchResult(
                    status: WebinarFollowUpDispatchResult::STATUS_ALREADY_SCHEDULED,
                    registrationId: (int) $locked->getKey(),
                    outcome: $storedOutcome,
                    messageChainEnrollmentId: $enrollmentId,
                );
            }

            if (($state['status'] ?? null) === 'not_applicable') {
                return new WebinarFollowUpDispatchResult(
                    status: WebinarFollowUpDispatchResult::STATUS_NOT_APPLICABLE,
                    registrationId: (int) $locked->getKey(),
                    outcome: $storedOutcome,
                    messageChainEnrollmentId: $enrollmentId,
                    reason: is_string($state['reason'] ?? null)
                        ? $state['reason']
                        : null,
                );
            }

            if (
                ($state['status'] ?? null) === 'planning'
                && $this->isFreshTimestamp($state['last_attempted_at'] ?? null)
            ) {
                return new WebinarFollowUpDispatchResult(
                    status: WebinarFollowUpDispatchResult::STATUS_IN_PROGRESS,
                    registrationId: (int) $locked->getKey(),
                    outcome: $storedOutcome,
                    messageChainEnrollmentId: $enrollmentId,
                );
            }

            $attemptedAt = now()->toISOString();
            $state = $this->compactState($state);
            $meta['post_event_follow_up'] = array_replace($state, [
                'status' => 'planning',
                'outcome' => $outcome,
                'attempts' => ((int) ($state['attempts'] ?? 0)) + 1,
                'first_attempted_at' => $state['first_attempted_at'] ?? $attemptedAt,
                'last_attempted_at' => $attemptedAt,
                'completed_at' => null,
                'failed_at' => null,
                'failure_reason' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ]);

            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });
    }

    private function recordNotApplicable(
        WebinarRegistration $registration,
        string $outcome,
        string $reason,
        ?int $messageChainEnrollmentId = null,
    ): WebinarFollowUpDispatchResult {
        $this->updateClaimedState($registration, [
            'status' => 'not_applicable',
            'outcome' => $outcome,
            'reason' => $reason,
            'message_chain_enrollment_id' => $messageChainEnrollmentId,
            'completed_at' => now()->toISOString(),
            'failed_at' => null,
            'failure_reason' => null,
            'last_error_class' => null,
            'last_error_code' => null,
        ]);

        return new WebinarFollowUpDispatchResult(
            status: WebinarFollowUpDispatchResult::STATUS_NOT_APPLICABLE,
            registrationId: (int) $registration->getKey(),
            outcome: $outcome,
            messageChainEnrollmentId: $messageChainEnrollmentId,
            reason: $reason,
        );
    }

    private function recordFailure(
        WebinarRegistration $registration,
        string $outcome,
        string $reason,
        ?int $messageChainEnrollmentId = null,
        ?Throwable $exception = null,
    ): WebinarFollowUpDispatchResult {
        $this->updateClaimedState($registration, [
            'status' => 'failed',
            'outcome' => $outcome,
            'message_chain_enrollment_id' => $messageChainEnrollmentId,
            'failed_at' => now()->toISOString(),
            'failure_reason' => $reason,
            'last_error_class' => $exception ? $exception::class : null,
            'last_error_code' => $exception
                ? (string) $exception->getCode()
                : null,
        ]);

        return new WebinarFollowUpDispatchResult(
            status: WebinarFollowUpDispatchResult::STATUS_FAILED,
            registrationId: (int) $registration->getKey(),
            outcome: $outcome,
            messageChainEnrollmentId: $messageChainEnrollmentId,
            reason: $reason,
        );
    }

    /** @param array<string, mixed> $changes */
    private function updateClaimedState(
        WebinarRegistration $registration,
        array $changes,
    ): void {
        DB::transaction(function () use ($registration, $changes): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = is_array($meta['post_event_follow_up'] ?? null)
                ? $meta['post_event_follow_up']
                : [];

            if (($state['status'] ?? null) !== 'planning') {
                return;
            }

            $meta['post_event_follow_up'] = array_replace(
                $this->compactState($state),
                $changes,
            );
            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function compactState(array $state): array
    {
        unset(
            $state['channels'],
            $state['scheduled_message_ids'],
        );

        return $state;
    }

    /** @param array<string, mixed> $state */
    private function messageChainEnrollmentId(array $state): ?int
    {
        $value = $state['message_chain_enrollment_id'] ?? null;

        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function hasEnabledOutcomeMessages(): bool
    {
        foreach (self::OUTCOME_AREA_KEYS as $areaKey) {
            if ($this->messageAreaRegistry->isEnabled($areaKey)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function conditionContext(Webinar $webinar, string $event): array
    {
        return [
            'event' => [
                'name' => $event,
            ],
            'webinar' => $webinar->toArray(),
        ];
    }

    /** @param array<string, mixed> $meta */
    private function markMeta(Webinar $webinar, array $meta): Webinar
    {
        return DB::transaction(function () use ($webinar, $meta): Webinar {
            $locked = Webinar::query()
                ->lockForUpdate()
                ->findOrFail($webinar->getKey());

            $locked->forceFill([
                'meta' => array_replace_recursive(
                    is_array($locked->meta) ? $locked->meta : [],
                    $meta,
                ),
            ])->save();

            return $locked->fresh() ?? $locked;
        });
    }

    private function isFreshTimestamp(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(
                now()->subMinutes(self::IN_PROGRESS_STALE_AFTER_MINUTES),
            );
        } catch (Throwable) {
            return false;
        }
    }
}
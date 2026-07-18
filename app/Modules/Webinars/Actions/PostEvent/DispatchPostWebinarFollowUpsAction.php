<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarFollowUpDispatchResult;
use App\Modules\Webinars\Data\WebinarMessageAreaDefinition;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
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
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
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

            $webinar = $this->markMeta($webinar, [
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
                    channels: [],
                    scheduledMessageIds: [],
                );
            }

            if (! $registration->contact) {
                return $this->recordFailure(
                    registration: $registration,
                    outcome: $outcome,
                    reason: 'contact_missing',
                    channels: [],
                    scheduledMessageIds: [],
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

            $webinar = $registration->webinar;
            $messageData = array_replace_recursive(
                WebinarMessageData::fromRegistration($registration)->toArray(),
                [
                    'webinar_id' => $webinar->getKey(),
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_slug' => $registration->webinar_slug,
                    'webinar_title' => $webinar->title,
                    'webinar_playback_url' => $webinar->playback_url,
                    'registration_attended_at' => $registration->attended_at?->toIso8601String(),
                ],
            );

            unset($messageData['playback_url']);

            $payload = array_replace_recursive(
                $messageData,
                [
                    'tokens' => $messageData,
                    'context' => [
                        'contact' => $messageData['contact'] ?? [],
                        'webinar' => $messageData['webinar'] ?? [],
                        'webinar_registration' => $messageData['webinar_registration'] ?? [],
                        'webinar_series' => $messageData['webinar_series'] ?? [],
                    ],
                ],
            );

            return $this->dispatchRegistrationMessages(
                registration: $registration,
                webinar: $webinar,
                outcome: $outcome,
                payload: $payload,
                messageArea: $messageArea,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->recordFailure(
                registration: $registration,
                outcome: $outcome,
                reason: 'follow_up_planning_exception',
                channels: [],
                scheduledMessageIds: [],
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

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatchRegistrationMessages(
        WebinarRegistration $registration,
        Webinar $webinar,
        string $outcome,
        array $payload,
        WebinarMessageAreaDefinition $messageArea,
    ): WebinarFollowUpDispatchResult {
        $channelResults = [];
        $scheduledMessageIds = $this->scheduledMessageIds(
            data_get($registration->fresh()?->meta, 'post_event_follow_up', []),
        );
        $failureReason = null;
        $failureException = null;

        foreach ($this->channels() as $channel) {
            try {
                $definitions = $this->scheduleProfileDefinitionResolver->applyForWebinar(
                    webinar: $webinar,
                    definitions: $this->messageDefinitionResolver->resolve(
                        channel: $channel,
                        purpose: $messageArea->purpose,
                        scope: $messageArea->scope,
                    ),
                    dispatchKeys: $messageArea->dispatchKey,
                    surface: $messageArea->surface,
                );

                $definitions = $this->messageAreaRegistry->filterDefinitions(
                    definitions: $definitions,
                    areaKeys: [$messageArea->key],
                    surface: $messageArea->surface,
                );

                if ($definitions === []) {
                    $channelResults[$channel] = [
                        'status' => 'failed',
                        'reason' => 'message_definition_unavailable',
                        'scheduled_message_ids' => [],
                    ];
                    $failureReason ??= 'message_definition_unavailable';

                    continue;
                }

                $meta = [
                    'webinar_id' => $webinar->getKey(),
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_slug' => $registration->webinar_slug,
                    'webinar_message_area' => $messageArea->key,
                    'post_event' => [
                        'type' => 'transactional_follow_up',
                        'attended' => $outcome === 'attended',
                    ],
                    'webinar_schedule_profile_applied' => true,
                ];

                $messages = $this->dispatchMessageAction->handle(
                    recipient: $registration->contact,
                    channel: $channel,
                    purpose: $messageArea->purpose,
                    scope: $messageArea->scope,
                    dispatchKeys: $messageArea->dispatchKey,
                    payload: $payload,
                    context: $registration,
                    triggeredAt: now(),
                    anchor: $webinar->ends_at,
                    meta: $meta,
                    definitions: $definitions,
                    occurrenceKey: implode(':', [
                        'webinar_post_event',
                        $registration->getKey(),
                        $webinar->getKey(),
                    ]),
                );

                $messageIds = array_values(array_unique(array_map(
                    fn (ScheduledMessage $message): int => (int) $message->getKey(),
                    array_values(array_filter(
                        $messages,
                        fn (mixed $message): bool => $message instanceof ScheduledMessage,
                    )),
                )));

                if ($messageIds === []) {
                    $channelResults[$channel] = [
                        'status' => 'not_applicable',
                        'reason' => 'messaging_planning_gate_rejected',
                        'scheduled_message_ids' => [],
                    ];

                    continue;
                }

                $scheduledMessageIds = array_values(array_unique([
                    ...$scheduledMessageIds,
                    ...$messageIds,
                ]));
                $channelResults[$channel] = [
                    'status' => 'scheduled',
                    'scheduled_message_ids' => $messageIds,
                ];
            } catch (Throwable $exception) {
                report($exception);

                $channelResults[$channel] = [
                    'status' => 'failed',
                    'reason' => 'message_dispatch_exception',
                    'scheduled_message_ids' => [],
                    'last_error_class' => $exception::class,
                    'last_error_code' => (string) $exception->getCode(),
                ];
                $failureReason ??= 'message_dispatch_exception';
                $failureException ??= $exception;
            }
        }

        if ($failureReason !== null) {
            return $this->recordFailure(
                registration: $registration,
                outcome: $outcome,
                reason: $failureReason,
                channels: $channelResults,
                scheduledMessageIds: $scheduledMessageIds,
                exception: $failureException,
            );
        }

        if ($scheduledMessageIds === []) {
            return $this->recordNotApplicable(
                registration: $registration,
                outcome: $outcome,
                reason: 'no_channels_eligible',
                channels: $channelResults,
            );
        }

        $this->updateClaimedState($registration, [
            'status' => 'scheduled',
            'outcome' => $outcome,
            'channels' => $channelResults,
            'scheduled_message_ids' => $scheduledMessageIds,
            'completed_at' => now()->toISOString(),
            'failed_at' => null,
            'failure_reason' => null,
            'last_error_class' => null,
            'last_error_code' => null,
        ]);

        return new WebinarFollowUpDispatchResult(
            status: WebinarFollowUpDispatchResult::STATUS_SCHEDULED,
            registrationId: (int) $registration->getKey(),
            outcome: $outcome,
            scheduledMessageIds: $scheduledMessageIds,
        );
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

            if (($state['status'] ?? null) === 'scheduled') {
                return new WebinarFollowUpDispatchResult(
                    status: WebinarFollowUpDispatchResult::STATUS_ALREADY_SCHEDULED,
                    registrationId: (int) $locked->getKey(),
                    outcome: $storedOutcome,
                    scheduledMessageIds: $this->scheduledMessageIds($state),
                );
            }

            if (($state['status'] ?? null) === 'not_applicable') {
                return new WebinarFollowUpDispatchResult(
                    status: WebinarFollowUpDispatchResult::STATUS_NOT_APPLICABLE,
                    registrationId: (int) $locked->getKey(),
                    outcome: $storedOutcome,
                    reason: is_string($state['reason'] ?? null) ? $state['reason'] : null,
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
                    scheduledMessageIds: $this->scheduledMessageIds($state),
                );
            }

            $attemptedAt = now()->toISOString();
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
        array $channels = [],
    ): WebinarFollowUpDispatchResult {
        $this->updateClaimedState($registration, [
            'status' => 'not_applicable',
            'outcome' => $outcome,
            'reason' => $reason,
            'channels' => $channels,
            'scheduled_message_ids' => [],
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
            reason: $reason,
        );
    }

    /**
     * @param array<string, mixed> $channels
     * @param array<int, int> $scheduledMessageIds
     */
    private function recordFailure(
        WebinarRegistration $registration,
        string $outcome,
        string $reason,
        array $channels,
        array $scheduledMessageIds,
        ?Throwable $exception = null,
    ): WebinarFollowUpDispatchResult {
        $this->updateClaimedState($registration, [
            'status' => 'failed',
            'outcome' => $outcome,
            'channels' => $channels,
            'scheduled_message_ids' => $scheduledMessageIds,
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
            scheduledMessageIds: $scheduledMessageIds,
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

            $meta['post_event_follow_up'] = array_replace($state, $changes);
            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    /** @param array<string, mixed> $state */
    private function scheduledMessageIds(array $state): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $id): ?int => is_numeric($id) ? (int) $id : null,
            is_array($state['scheduled_message_ids'] ?? null)
                ? $state['scheduled_message_ids']
                : [],
        ), fn (?int $id): bool => $id !== null && $id > 0)));
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

    /** @return array<int, string> */
    private function channels(): array
    {
        $channels = config('webinars.post_event.outcome_messages.channels', [
            MessageChannel::Email->value,
        ]);

        if (! is_array($channels)) {
            $channels = [MessageChannel::Email->value];
        }

        $allowed = [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ];

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $channel): ?string => is_string($channel) && in_array(strtolower(trim($channel)), $allowed, true)
                ? strtolower(trim($channel))
                : null,
            $channels,
        )))) ?: [MessageChannel::Email->value];
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
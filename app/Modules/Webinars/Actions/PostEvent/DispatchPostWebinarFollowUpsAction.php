<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;

class DispatchPostWebinarFollowUpsAction
{
    private const OUTCOME_AREA_KEYS = [
        'post_attended',
        'post_missed',
    ];

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

        if ($this->hasEnabledOutcomeMessages()) {
            $conditions = config('webinars.post_event.outcome_messages.conditions', []);

            if (
                is_array($conditions)
                && ! $this->conditionChecker->passes($conditions, $this->conditionContext($webinar, $event))
            ) {
                return false;
            }

            if (! data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at')) {
                $this->dispatchTransactionalFollowUps($webinar);

                $webinar = $this->markMeta($webinar, [
                    'normalized' => [
                        'post_event' => [
                            'follow_ups_dispatched_at' => now()->toIso8601String(),
                        ],
                    ],
                ]);
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

        return true;
    }

    private function dispatchTransactionalFollowUps(Webinar $webinar): void
    {
        $registrations = WebinarRegistration::query()
            ->where('webinar_id', $webinar->getKey())
            ->whereNull('cancelled_at')
            ->whereNotNull('contact_id')
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->get();

        foreach ($registrations as $registration) {
            if (! $registration->contact) {
                continue;
            }

            $areaKey = filled($registration->attended_at)
                ? 'post_attended'
                : 'post_missed';
            $messageArea = $this->messageAreaRegistry->get($areaKey);

            if (! $messageArea?->enabled || ! $messageArea->isTemplate()) {
                continue;
            }

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

            foreach ($this->channels() as $channel) {
                $meta = [
                    'webinar_id' => $webinar->getKey(),
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_slug' => $registration->webinar_slug,
                    'webinar_message_area' => $messageArea->key,
                    'post_event' => [
                        'type' => 'transactional_follow_up',
                        'attended' => filled($registration->attended_at),
                    ],
                ];

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
                    continue;
                }

                $this->dispatchMessageAction->handle(
                    recipient: $registration->contact,
                    channel: $channel,
                    purpose: $messageArea->purpose,
                    scope: $messageArea->scope,
                    dispatchKeys: $messageArea->dispatchKey,
                    payload: $payload,
                    context: $registration,
                    triggeredAt: now(),
                    anchor: $webinar->ends_at,
                    meta: $meta + ['webinar_schedule_profile_applied' => true],
                    definitions: $definitions,
                    occurrenceKey: implode(':', [
                        'webinar_post_event',
                        $registration->getKey(),
                        $webinar->getKey(),
                    ]),
                );
            }
        }
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

    /**
     * @return array<int, string>
     */
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

    /**
     * @return array<string, mixed>
     */
    private function conditionContext(Webinar $webinar, string $event): array
    {
        return [
            'event' => [
                'name' => $event,
            ],
            'webinar' => $webinar->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function markMeta(Webinar $webinar, array $meta): Webinar
    {
        $webinar = $webinar->fresh() ?? $webinar;

        $webinar->forceFill([
            'meta' => array_replace_recursive($webinar->meta ?? [], $meta),
        ])->save();

        return $webinar->fresh() ?? $webinar;
    }
}

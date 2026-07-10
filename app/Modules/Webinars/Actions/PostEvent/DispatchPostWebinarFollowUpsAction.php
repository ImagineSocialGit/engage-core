<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;

class DispatchPostWebinarFollowUpsAction
{
    public function __construct(
        private readonly ConditionChecker $conditionChecker,
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly EmitWebinarAutomationEventAction $emitWebinarAutomationEvent,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ) {}

    public function execute(
        WebinarProvider $provider,
        Webinar $webinar,
        string $event,
    ): bool {
        if (! config('webinars.post_event.outcome_messages.enabled', true)) {
            return true;
        }

        $conditions = config('webinars.post_event.outcome_messages.conditions', []);

        if (
            is_array($conditions)
            && ! $this->conditionChecker->passes($conditions, $this->conditionContext($webinar, $event))
        ) {
            return false;
        }

        $webinar = $webinar->fresh() ?? $webinar;

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

            $dispatchKeys = config('webinars.post_event.outcome_messages.dispatch_key', 'webinar_ended');
            $purpose = $this->normalizedPurpose();
            $scope = $this->normalizedScope();

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
                    'post_event' => [
                        'type' => 'transactional_follow_up',
                        'attended' => filled($registration->attended_at),
                    ],
                ];

                $definitions = $this->scheduleProfileDefinitionResolver->applyForWebinar(
                    webinar: $webinar,
                    definitions: $this->messageDefinitionResolver->resolve(
                        channel: $channel,
                        purpose: $purpose,
                        scope: $scope,
                    ),
                    dispatchKeys: $dispatchKeys,
                    surface: 'webinar_registrations',
                );

                if ($definitions === []) {
                    continue;
                }

                $this->dispatchMessageAction->handle(
                    recipient: $registration->contact,
                    channel: $channel,
                    purpose: $purpose,
                    scope: $scope,
                    dispatchKeys: $dispatchKeys,
                    payload: $payload,
                    context: $registration,
                    triggeredAt: now(),
                    meta: $meta + ['webinar_schedule_profile_applied' => true],
                    definitions: $definitions,
                );
            }
        }
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

    private function normalizedPurpose(): string
    {
        $purpose = config('webinars.post_event.outcome_messages.purpose', MessagePurpose::Transactional->value);

        return is_string($purpose) && trim($purpose) !== ''
            ? str_replace('-', '_', strtolower(trim($purpose)))
            : MessagePurpose::Transactional->value;
    }

    private function normalizedScope(): string
    {
        $scope = config('webinars.post_event.outcome_messages.scope', 'webinar');

        return is_string($scope) && trim($scope) !== ''
            ? str_replace('-', '_', strtolower(trim($scope)))
            : 'webinar';
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

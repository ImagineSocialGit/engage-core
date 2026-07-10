<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;

class DispatchWebinarRegistrationMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageEligibilityGate $messageEligibilityGate,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ) {}

    public function handle(WebinarRegistration $registration): void
    {
        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        if (! $registration->contact) {
            return;
        }

        $messageData = WebinarMessageData::fromRegistration($registration)->toArray();

        foreach ($this->availableTransactionalChannels($registration) as $channel) {
            if (! $this->messageEligibilityGate->allows(
                contact: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
            )) {
                continue;
            }

            $definitions = $this->scheduleProfileDefinitionResolver->applyForWebinar(
                webinar: $registration->webinar,
                definitions: $this->messageDefinitionResolver->resolve(
                    channel: $channel,
                    purpose: MessagePurpose::Transactional->value,
                    scope: self::SCOPE,
                ),
                dispatchKeys: 'registration_created',
                surface: 'webinar_registrations',
            );

            if ($definitions === []) {
                continue;
            }

            $this->dispatchMessageAction->handle(
                recipient: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
                dispatchKeys: 'registration_created',
                payload: [
                    'tokens' => $messageData,
                    'context' => [
                        'contact' => $messageData['contact'] ?? [],
                        'webinar_registration' => $messageData['webinar_registration'] ?? [],
                        'webinar' => $messageData['webinar'] ?? [],
                        'webinar_series' => $messageData['webinar_series'] ?? [],
                    ],
                ],
                context: $registration,
                triggeredAt: $registration->registered_at ?? now(),
                anchor: $registration->webinar?->starts_at,
                meta: [
                    'webinar_schedule_profile_applied' => true,
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
                definitions: $definitions,
                occurrenceKey: 'webinar_registration:'.$registration->getKey(),
            );
        }
    }
    /**
     * @return array<int, MessageChannel>
     */
    private function availableTransactionalChannels(WebinarRegistration $registration): array
    {
        $channels = $this->messageChannelAvailability->visibleChannelsForSurface(
            surface: 'webinar_registrations',
            purpose: MessagePurpose::Transactional->value,
            scope: self::SCOPE,
        );

        $acceptedChannels = $registration->meta['accepted_channels']['transactional'] ?? null;

        if (is_array($acceptedChannels)) {
            $channels = array_values(array_intersect($channels, $acceptedChannels));
        }

        return collect($channels)
            ->map(fn (string $channel): ?MessageChannel => MessageChannel::tryFrom($channel))
            ->filter()
            ->values()
            ->all();
    }

}

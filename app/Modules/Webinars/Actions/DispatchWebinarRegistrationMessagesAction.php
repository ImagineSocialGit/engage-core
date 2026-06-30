<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\WebinarRegistration;

class DispatchWebinarRegistrationMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageEligibilityGate $messageEligibilityGate,
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

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            if (! $this->messageEligibilityGate->allows(
                contact: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
            )) {
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
                        'contact' => $registration->contact->toArray(),
                        'webinar_registration' => $registration->toArray(),
                        'webinar' => $registration->webinar?->toArray() ?? [],
                        'webinar_series' => $registration->webinar?->webinarSeries?->toArray() ?? [],
                    ],
                ],
                context: $registration,
                triggeredAt: $registration->registered_at ?? now(),
                anchor: $registration->webinar?->starts_at,
                meta: [
                    'webinar_registration_id' => $registration->getKey(),
                    'webinar_id' => $registration->webinar_id,
                    'webinar_slug' => $registration->webinar_slug,
                ],
            );
        }
    }
}
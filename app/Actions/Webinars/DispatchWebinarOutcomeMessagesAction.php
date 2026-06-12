<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\WebinarRegistration;

class DispatchWebinarOutcomeMessagesAction
{
    private const SCOPE = 'webinar';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
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
            foreach ([MessagePurpose::Transactional, MessagePurpose::Marketing] as $purpose) {
                $this->dispatchMessageAction->handle(
                    contact: $registration->contact,
                    channel: $channel,
                    purpose: $purpose,
                    scope: self::SCOPE,
                    dispatchKeys: 'webinar_ended',
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
                    triggeredAt: now(),
                    anchor: $registration->webinar?->ends_at,
                    meta: [
                        'webinar_registration_id' => $registration->getKey(),
                        'webinar_id' => $registration->webinar_id,
                        'webinar_slug' => $registration->webinar_slug,
                        'webinar_outcome' => $registration->attended_at ? 'attended' : 'missed',
                    ],
                );
            }
        }
    }
}
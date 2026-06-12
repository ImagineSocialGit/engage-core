<?php

namespace App\Actions\Webinars\PostEvent;

use App\Actions\Campaigns\EnrollContactInCampaignAction;
use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\WebinarRegistration;

class DispatchWebinarOutcomeMessagesAction
{
    private const SCOPE = 'webinar';
    private const DISPATCH_KEY = 'webinar_ended';
    private const CAMPAIGN_KEY = 'webinar_attended';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly EnrollContactInCampaignAction $enrollContactInCampaignAction,
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

        $payload = [
            'tokens' => $messageData,
            'context' => [
                'contact' => $registration->contact->toArray(),
                'webinar_registration' => $registration->toArray(),
                'webinar' => $registration->webinar?->toArray() ?? [],
                'webinar_series' => $registration->webinar?->webinarSeries?->toArray() ?? [],
            ],
        ];

        $meta = [
            'webinar_registration_id' => $registration->getKey(),
            'webinar_id' => $registration->webinar_id,
            'webinar_slug' => $registration->webinar_slug,
            'webinar_outcome' => $registration->attended_at ? 'attended' : 'missed',
        ];

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $this->dispatchMessageAction->handle(
                contact: $registration->contact,
                channel: $channel,
                purpose: MessagePurpose::Transactional,
                scope: self::SCOPE,
                dispatchKeys: self::DISPATCH_KEY,
                payload: $payload,
                context: $registration,
                triggeredAt: now(),
                anchor: $registration->webinar?->ends_at,
                meta: $meta,
            );

            $this->enrollContactInCampaignAction->handle(
                contact: $registration->contact,
                campaignKey: self::CAMPAIGN_KEY,
                channel: $channel->value,
                purpose: MessagePurpose::Marketing->value,
                scope: self::SCOPE,
                dispatchKey: self::DISPATCH_KEY,
                source: $registration,
                payload: $payload,
                meta: $meta,
            );
        }
    }
}
<?php

namespace App\Actions\Webinars;

use App\Actions\Messaging\DispatchMessageAction;
use App\Data\WebinarMessageData;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Webinar;
use App\Models\WebinarWaitlistSignup;

class DispatchWebinarWaitlistMessagesAction
{
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    public function handle(Webinar $webinar): void
    {
        $webinar->loadMissing('webinarSeries');

        $signups = WebinarWaitlistSignup::query()
            ->with(['contact', 'webinarSeries'])
            ->where('webinar_series_id', $webinar->webinar_series_id)
            ->whereNull('notified_at')
            ->get();

        foreach ($signups as $signup) {
            $this->dispatchForSignup($signup, $webinar);

            $signup->forceFill([
                'notified_at' => now(),
            ])->save();
        }
    }

    private function dispatchForSignup(WebinarWaitlistSignup $signup, Webinar $webinar): void
    {
        if (! $signup->contact) {
            return;
        }

        $messageData = WebinarMessageData::fromWaitlistSignup($signup, $webinar)->toArray();

        foreach ([MessageChannel::Email, MessageChannel::Sms] as $channel) {
            $this->dispatchMessageAction->handle(
                contact: $signup->contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing,
                scope: self::SCOPE,
                dispatchKeys: 'webinar_added',
                payload: [
                    'tokens' => $messageData,
                    'context' => $messageData,
                ],
                context: $signup,
                triggeredAt: now(),
                anchor: $webinar->starts_at,
                meta: [
                    'webinar_waitlist_signup_id' => $signup->getKey(),
                    'webinar_id' => $webinar->getKey(),
                    'webinar_series_id' => $webinar->webinar_series_id,
                ],
            );
        }
    }
}
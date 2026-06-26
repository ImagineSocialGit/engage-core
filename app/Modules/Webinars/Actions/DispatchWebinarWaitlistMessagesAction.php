<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;

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
                recipient: $signup->contact,
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
<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Data\WebinarMessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;

class DispatchWebinarWaitlistMessagesAction
{
    private const SURFACE = 'webinar_waitlists';
    private const PURPOSE = 'marketing';
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly MessageChannelAvailability $messageChannelAvailability,
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
            if (! $this->dispatchForSignup($signup, $webinar)) {
                continue;
            }

            $signup->forceFill([
                'notified_at' => now(),
            ])->save();
        }
    }

    private function dispatchForSignup(WebinarWaitlistSignup $signup, Webinar $webinar): bool
    {
        if (! $signup->contact) {
            return false;
        }

        $channels = $this->availableAcceptedChannels($signup);

        if ($channels === []) {
            return false;
        }

        $messageData = WebinarMessageData::fromWaitlistSignup($signup, $webinar)->toArray();

        foreach ($channels as $channel) {
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

        return true;
    }

    /**
     * @return array<int, MessageChannel>
     */
    private function availableAcceptedChannels(WebinarWaitlistSignup $signup): array
    {
        $acceptedChannels = $signup->meta['accepted_channels'][self::PURPOSE] ?? [];

        if (! is_array($acceptedChannels)) {
            return [];
        }

        return collect($this->messageChannelAvailability->normalizeVisibleChannelsForSurface(
            channels: $acceptedChannels,
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
        ))
            ->map(fn (string $channel): ?MessageChannel => MessageChannel::tryFrom($channel))
            ->filter()
            ->values()
            ->all();
    }
}

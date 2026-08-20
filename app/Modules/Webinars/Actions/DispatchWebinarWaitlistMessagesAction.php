<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarWaitlistNotificationStartResolver;

class DispatchWebinarWaitlistMessagesAction
{
    private const SURFACE = 'webinar_waitlists';
    private const PURPOSE = 'marketing';
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly StartWebinarMessageChainEnrollmentAction $startMessageChainEnrollment,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly WebinarMessageAreaRegistry $messageAreaRegistry,
        private readonly WebinarWaitlistNotificationStartResolver $notificationStartResolver,
    ) {}

    public function handle(
        Webinar $webinar,
        ?string $notificationMode = null,
    ): void {
        if (! $this->messageAreaRegistry->isEnabled('waitlist')) {
            return;
        }

        $webinar->loadMissing('webinarSeries');

        $signups = WebinarWaitlistSignup::query()
            ->with(['contact', 'webinarSeries'])
            ->where('webinar_series_id', $webinar->webinar_series_id)
            ->eligibleForNotification($notificationMode)
            ->get();

        foreach ($signups as $signup) {
            if (! $this->enrollSignup($signup, $webinar)) {
                continue;
            }

            if ($signup->notified_at === null) {
                $signup->forceFill([
                    'notified_at' => now(),
                ])->save();
            }
        }
    }

    private function enrollSignup(
        WebinarWaitlistSignup $signup,
        Webinar $webinar,
    ): bool {
        if (! $signup->contact || $this->availableAcceptedChannels($signup) === []) {
            return false;
        }

        $enrollment = $this->startMessageChainEnrollment->handle(
            webinar: $webinar,
            messageAreaKey: 'waitlist',
            recipient: $signup->contact,
            context: $signup,
            startedAt: $this->notificationStartResolver->resolve($signup, $webinar),
            required: false,
        );

        if ($enrollment === null) {
            return false;
        }

        return $enrollment->scheduledMessages->isNotEmpty()
            || ($enrollment->isActive() && $enrollment->next_action_at !== null);
    }

    /**
     * @return array<int, MessageChannel>
     */
    private function availableAcceptedChannels(
        WebinarWaitlistSignup $signup,
    ): array {
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
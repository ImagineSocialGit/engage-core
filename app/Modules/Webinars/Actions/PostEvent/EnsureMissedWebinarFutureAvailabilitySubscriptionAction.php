<?php

namespace App\Modules\Webinars\Actions\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class EnsureMissedWebinarFutureAvailabilitySubscriptionAction
{
    private const SURFACE = 'webinar_waitlists';
    private const PURPOSE = 'marketing';
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly ConsentDomainRegistry $consentDomainRegistry,
        private readonly MessageConsentStateResolver $messageConsentStateResolver,
    ) {}

    public function execute(
        WebinarRegistration $registration,
    ): ?WebinarWaitlistSignup {
        if (! (bool) config('webinars.post_event.future_availability_subscription.enabled', false)) {
            return null;
        }

        $registration->loadMissing([
            'contact',
            'webinar',
            'webinar.webinarSeries',
        ]);

        if ($registration->status !== 'missed'
            || $registration->attended_at !== null
            || ! $registration->contact
            || ! $registration->webinar
            || ! $registration->webinar->webinarSeries
        ) {
            return null;
        }

        $eligibleChannels = $this->eligibleChannels($registration->contact);

        if ($eligibleChannels === []) {
            return null;
        }

        $series = $registration->webinar->webinarSeries;
        $now = now();
        $expiresAt = $now->copy()->addDays($this->durationDays());

        $signup = DB::transaction(function () use (
            $registration,
            $series,
            $eligibleChannels,
            $now,
            $expiresAt,
        ): ?WebinarWaitlistSignup {
            $signup = WebinarWaitlistSignup::query()
                ->where('webinar_series_id', $series->getKey())
                ->where('contact_id', $registration->contact_id)
                ->lockForUpdate()
                ->first();

            if ($signup instanceof WebinarWaitlistSignup && $signup->ended_at !== null) {
                return null;
            }

            $meta = is_array($signup?->meta) ? $signup->meta : [];
            $acceptedChannels = data_get($meta, 'accepted_channels.'.self::PURPOSE, []);
            $acceptedChannels = is_array($acceptedChannels)
                ? $acceptedChannels
                : [];

            data_set(
                $meta,
                'accepted_channels.'.self::PURPOSE,
                array_values(array_unique(array_merge(
                    $acceptedChannels,
                    $eligibleChannels,
                ))),
            );

            $subscriptionMeta = data_get($meta, 'future_availability_subscription', []);
            $subscriptionMeta = is_array($subscriptionMeta)
                ? $subscriptionMeta
                : [];
            $subscriptionMeta['source'] = 'missed_webinar';
            $subscriptionMeta['started_at'] = $subscriptionMeta['started_at']
                ?? $now->toISOString();
            $subscriptionMeta['renewed_at'] = $now->toISOString();
            $subscriptionMeta['latest_webinar_registration_id'] = $registration->getKey();
            $subscriptionMeta['latest_webinar_id'] = $registration->webinar_id;

            data_set(
                $meta,
                'future_availability_subscription',
                $subscriptionMeta,
            );

            if (! $signup instanceof WebinarWaitlistSignup) {
                return WebinarWaitlistSignup::query()->create([
                    'contact_id' => $registration->contact_id,
                    'webinar_series_id' => $series->getKey(),
                    'notified_at' => null,
                    'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
                    'expires_at' => $expiresAt,
                    'ended_at' => null,
                    'source_page' => null,
                    'meta' => $meta,
                ]);
            }

            $signup->forceFill([
                'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
                'expires_at' => $this->renewedExpiry($signup, $expiresAt),
                'meta' => $meta,
            ])->save();

            return $signup->refresh();
        });

        if ($signup instanceof WebinarWaitlistSignup) {
            NotifyWebinarWaitlistJob::dispatch(
                (int) $signup->webinar_series_id,
                null,
                WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            );
        }

        return $signup;
    }

    /**
     * @return array<int, string>
     */
    private function eligibleChannels(Contact $contact): array
    {
        $configuredChannels = config(
            'webinars.post_event.future_availability_subscription.channels',
            [],
        );

        if (! is_array($configuredChannels) || $configuredChannels === []) {
            return [];
        }

        $availableChannels = $this->messageChannelAvailability
            ->normalizeVisibleChannelsForSurface(
                channels: $configuredChannels,
                surface: self::SURFACE,
                purpose: self::PURPOSE,
                scope: self::SCOPE,
            );
        $consentDomain = $this->consentDomainRegistry->domainForScope(self::SCOPE);

        return collect($availableChannels)
            ->map(fn (string $channel): ?MessageChannel => MessageChannel::tryFrom($channel))
            ->filter(fn (?MessageChannel $channel): bool => $channel instanceof MessageChannel)
            ->filter(fn (MessageChannel $channel): bool => $this->hasDestination($contact, $channel))
            ->filter(fn (MessageChannel $channel): bool => $this->messageConsentStateResolver->isActive(
                contact: $contact,
                channel: $channel,
                purpose: MessagePurpose::Marketing,
                scope: $consentDomain,
            ))
            ->map(fn (MessageChannel $channel): string => $channel->value)
            ->values()
            ->all();
    }

    private function hasDestination(
        Contact $contact,
        MessageChannel $channel,
    ): bool {
        return match ($channel) {
            MessageChannel::Email => filled($contact->email),
            MessageChannel::Sms => filled($contact->phone),
        };
    }

    private function durationDays(): int
    {
        return max(
            1,
            (int) config(
                'webinars.post_event.future_availability_subscription.duration_days',
                365,
            ),
        );
    }

    private function renewedExpiry(
        WebinarWaitlistSignup $signup,
        Carbon $requestedExpiry,
    ): ?Carbon {
        if ($signup->notification_mode === WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING
            && $signup->expires_at === null
        ) {
            return null;
        }

        if ($signup->expires_at !== null && $signup->expires_at->greaterThan($requestedExpiry)) {
            return $signup->expires_at;
        }

        return $requestedExpiry;
    }
}
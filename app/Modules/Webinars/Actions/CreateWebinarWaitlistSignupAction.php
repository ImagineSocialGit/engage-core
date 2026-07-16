<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Actions\GrantMessageConsentsAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\PhoneNumberNormalizer;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreateWebinarWaitlistSignupAction
{
    private const SURFACE = 'webinar_waitlists';
    private const PURPOSE = 'marketing';
    private const SCOPE = 'webinar_waitlist';

    public function __construct(
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly GrantMessageConsentsAction $grantMessageConsentsAction,
        private readonly DispatchConsentOptInMessageAction $dispatchConsentOptInMessageAction,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly PhoneNumberNormalizer $phoneNumberNormalizer,
    ) {}

    /**
     * @param array<string, mixed> $validated
     * @param array<int, string> $acceptedChannels
     */
    public function handle(
        array $validated,
        Request $request,
        WebinarSeries $series,
        array $acceptedChannels,
    ): WebinarWaitlistSignup {
        return DB::transaction(function () use ($validated, $request, $series, $acceptedChannels): WebinarWaitlistSignup {
            $acceptedChannels = $this->normalizeAcceptedChannels($acceptedChannels);

            if ($acceptedChannels === []) {
                throw new InvalidArgumentException('At least one available waitlist channel must be accepted.');
            }

            $normalizedPhone = $this->phoneNumberNormalizer->normalize($validated['phone'] ?? null);

            $contact = $this->createOrUpdateContact->handle([
                'email' => $validated['email'],
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $normalizedPhone,
                'source' => 'webinar_waitlist',
                'subsource' => $series->slug,
            ]);

            $signup = WebinarWaitlistSignup::query()->updateOrCreate(
                [
                    'webinar_series_id' => $series->getKey(),
                    'contact_id' => $contact->getKey(),
                ],
                [
                    'source_page' => route('webinar.show', $series->slug),
                    'meta' => [
                        'series_slug' => $series->slug,
                        'series_title' => $series->title,
                        'request_ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                        'accepted_channels' => [
                            self::PURPOSE => $acceptedChannels,
                        ],
                    ],
                ],
            );

            $this->storeMessageConsents(
                contact: $contact,
                signup: $signup,
                series: $series,
                channels: $acceptedChannels,
                request: $request,
            );

            return $signup->refresh();
        });
    }

    /**
     * @param array<int, string> $acceptedChannels
     * @return array<int, string>
     */
    private function normalizeAcceptedChannels(array $acceptedChannels): array
    {
        return $this->messageChannelAvailability->normalizeVisibleChannelsForSurface(
            channels: $acceptedChannels,
            surface: self::SURFACE,
            purpose: self::PURPOSE,
            scope: self::SCOPE,
        );
    }

    /**
     * @param array<int, string> $channels
     */
    private function storeMessageConsents(
        Contact $contact,
        WebinarWaitlistSignup $signup,
        WebinarSeries $series,
        array $channels,
        Request $request,
    ): void {
        $grants = [];

        foreach ($channels as $channel) {
            $messageChannel = MessageChannel::tryFrom($channel);

            if (! $messageChannel) {
                continue;
            }

            $grants[] = [
                'channel' => $messageChannel->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => self::SCOPE,
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_waitlist',
                'meta' => [
                    'webinar_waitlist_signup_id' => $signup->getKey(),
                    'webinar_series_id' => $series->getKey(),
                    'webinar_series_slug' => $series->slug,
                ],
            ];
        }

        $results = $this->grantMessageConsentsAction->handle(
            contact: $contact,
            grants: $grants,
        );

        foreach ($results as $result) {
            if (! $result->becameActive) {
                continue;
            }

            $this->dispatchConsentOptInMessageAction->handle(
                contact: $contact,
                grant: $result,
                payload: [
                    'webinar_waitlist_signup_id' => $signup->getKey(),
                    'webinar_series_id' => $series->getKey(),
                    'webinar_series_slug' => $series->slug,
                    'series_slug' => $series->slug,
                    'series_title' => $series->title,
                ],
                resolverContext: [
                    'series_slug' => $series->slug,
                ],
            );
        }
    }

}

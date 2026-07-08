<?php

namespace App\Modules\Webinars\Data;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\MessageData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Support\WebinarJoinLinkGenerator;
use App\Modules\Webinars\Support\WebinarPlaybackLinkGenerator;
use App\Modules\Webinars\Support\WebinarRegistrationCancelLinkGenerator;
use Illuminate\Support\Facades\URL;

readonly class WebinarMessageData extends MessageData
{
    public function __construct(
        Contact $contact,
        public Webinar $webinar,
        public ?WebinarRegistration $registration = null,
        public ?WebinarWaitlistSignup $waitlistSignup = null,
        public ?string $webinarJoinUrl = null,
        ?string $requestIp = null,
    ) {
        parent::__construct(
            contact: $contact,
            requestIp: $requestIp,
        );
    }

    public static function fromRegistration(WebinarRegistration $registration): self
    {
        $registration->loadMissing(['contact', 'webinar', 'webinar.webinarSeries']);

        return new self(
            contact: $registration->contact,
            webinar: $registration->webinar,
            registration: $registration,
            webinarJoinUrl: app(WebinarJoinLinkGenerator::class)->forRegistration($registration),
            requestIp: $registration->meta['request_ip']
                ?? $registration->meta['ip_address']
                ?? null,
        );
    }

    public static function fromWaitlistSignup(WebinarWaitlistSignup $signup, Webinar $webinar): self
    {
        $signup->loadMissing(['contact', 'webinarSeries']);
        $webinar->loadMissing('webinarSeries');

        return new self(
            contact: $signup->contact,
            webinar: $webinar,
            waitlistSignup: $signup,
            requestIp: $signup->meta['request_ip']
                ?? $signup->meta['ip_address']
                ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $timezone = $this->webinar->timezone ?: config('app.timezone', 'America/Chicago');
        $startsAt = $this->webinar->starts_at;
        $endsAt = $this->webinar->ends_at;
        $webinarSeries = $this->webinar->webinarSeries;

        $playbackUrl = filled($this->webinar->playback_url)
            ? app(WebinarPlaybackLinkGenerator::class)->forWebinar($this->webinar)
            : null;

        $cancelRegistrationUrl = $this->registration
            ? app(WebinarRegistrationCancelLinkGenerator::class)->forRegistration($this->registration)
            : null;

        return [
            ...parent::toArray(),

            'webinar_registration' => $this->compactRegistration(),
            'webinar_waitlist_signup' => $this->compactWaitlistSignup(),
            'webinar' => $this->compactWebinar($timezone),
            'webinar_series' => $this->compactWebinarSeries($webinarSeries),

            'registration_id' => $this->registration?->getKey(),
            'webinar_registration_id' => $this->registration?->getKey(),
            'registration_attended_at' => $this->registration?->attended_at?->toIso8601String(),
            'waitlist_signup_id' => $this->waitlistSignup?->getKey(),

            'webinar_id' => $this->webinar->getKey(),
            'webinar_slug' => $this->webinar->slug,
            'webinar_title' => $this->webinar->title,
            'webinar_description' => $this->webinar->description,
            'webinar_status' => $this->webinar->status,
            'webinar_timezone' => $timezone,
            'webinar_platform' => $this->webinar->platform,
            'webinar_join_url' => $this->webinarJoinUrl,
            'webinar_registration_url' => $this->webinar->registration_url,
            'webinar_waitlist_registration_url' => $this->waitlistRegistrationUrl(),
            'cancel_registration_url' => $cancelRegistrationUrl,
            'webinar_playback_url' => $playbackUrl,
            'webinar_playback_passcode' => $this->webinar->playback_passcode,

            'webinar_start_date' => $this->formatDate($startsAt, $timezone),
            'webinar_start_time' => $this->formatTime($startsAt, $timezone),
            'webinar_start_datetime' => $this->formatDateTime($startsAt, $timezone),
            'webinar_end_date' => $this->formatDate($endsAt, $timezone),
            'webinar_end_time' => $this->formatTime($endsAt, $timezone),
            'webinar_end_datetime' => $this->formatDateTime($endsAt, $timezone),

            'webinar_series_id' => $webinarSeries?->getKey(),
            'webinar_series_slug' => $webinarSeries?->slug,
            'webinar_series_title' => $webinarSeries?->title,
            'webinar_series_status' => $webinarSeries?->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            contact: Contact::query()->findOrFail($data['contact_id']),
            webinar: Webinar::query()->findOrFail($data['webinar_id']),
            registration: isset($data['registration_id'])
                ? WebinarRegistration::query()->find($data['registration_id'])
                : null,
            waitlistSignup: isset($data['waitlist_signup_id'])
                ? WebinarWaitlistSignup::query()->find($data['waitlist_signup_id'])
                : null,
            webinarJoinUrl: $data['webinar_join_url'] ?? null,
            requestIp: $data['request_ip'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compactRegistration(): array
    {
        if (! $this->registration instanceof WebinarRegistration) {
            return [];
        }

        return [
            'id' => $this->registration->getKey(),
            'contact_id' => $this->registration->contact_id,
            'webinar_id' => $this->registration->webinar_id,
            'webinar_slug' => $this->registration->webinar_slug,
            'status' => $this->registration->status,
            'source' => $this->registration->source,
            'registered_at' => $this->registration->registered_at?->toIso8601String(),
            'attended_at' => $this->registration->attended_at?->toIso8601String(),
            'cancelled_at' => $this->registration->cancelled_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactWaitlistSignup(): array
    {
        if (! $this->waitlistSignup instanceof WebinarWaitlistSignup) {
            return [];
        }

        return [
            'id' => $this->waitlistSignup->getKey(),
            'contact_id' => $this->waitlistSignup->contact_id,
            'webinar_series_id' => $this->waitlistSignup->webinar_series_id,
            'source_page' => $this->waitlistSignup->source_page,
            'notified_at' => $this->waitlistSignup->notified_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactWebinar(string $timezone): array
    {
        return [
            'id' => $this->webinar->getKey(),
            'webinar_series_id' => $this->webinar->webinar_series_id,
            'webinar_schedule_profile_id' => $this->webinar->webinar_schedule_profile_id,
            'title' => $this->webinar->title,
            'slug' => $this->webinar->slug,
            'status' => $this->webinar->status,
            'platform' => $this->webinar->platform,
            'external_id' => $this->webinar->external_id,
            'timezone' => $timezone,
            'starts_at' => $this->webinar->starts_at?->toIso8601String(),
            'ends_at' => $this->webinar->ends_at?->toIso8601String(),
            'description' => $this->webinar->description,
            'registration_url' => $this->webinar->registration_url,
            'playback_url' => $this->webinar->playback_url,
            'playback_passcode' => $this->webinar->playback_passcode,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compactWebinarSeries(mixed $webinarSeries): array
    {
        if (! $webinarSeries) {
            return [];
        }

        return [
            'id' => $webinarSeries->getKey(),
            'webinar_schedule_profile_id' => $webinarSeries->webinar_schedule_profile_id,
            'title' => $webinarSeries->title,
            'slug' => $webinarSeries->slug,
            'status' => $webinarSeries->status,
        ];
    }

    private function waitlistRegistrationUrl(): ?string
    {
        if (! $this->waitlistSignup instanceof WebinarWaitlistSignup) {
            return null;
        }

        $series = $this->webinar->webinarSeries;

        if (! $series || blank($series->slug)) {
            return null;
        }

        $path = URL::temporarySignedRoute(
            name: 'webinar.waitlist.register',
            expiration: now()->addDays((int) config('webinars.waitlist_registration_link_days', 14)),
            parameters: [
                'seriesSlug' => $series->slug,
                'signup' => $this->waitlistSignup->getKey(),
            ],
            absolute: false,
        );

        $baseUrl = rtrim((string) config('app.webinar_url', config('app.url')), '/');

        if ($baseUrl === '') {
            return $path;
        }

        return $baseUrl.$path;
    }

    public function formattedStart(string $format = 'M j g:i A'): string
    {
        return $this->webinar->starts_at
            ? $this->webinar->starts_at->copy()->setTimezone($this->webinar->timezone ?: config('app.timezone'))->format($format)
            : '';
    }
}

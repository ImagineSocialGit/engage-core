<?php

namespace App\Data;

use App\Models\Contact;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Models\WebinarWaitlistSignup;
use App\Support\Webinars\WebinarJoinLinkGenerator;
use Carbon\CarbonInterface;

readonly class WebinarMessageData
{
    public function __construct(
        public Contact $contact,
        public Webinar $webinar,
        public ?WebinarRegistration $registration = null,
        public ?WebinarWaitlistSignup $waitlistSignup = null,
        public ?string $webinarJoinUrl = null,
        public ?string $requestIp = null,
    ) {}

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
            webinarJoinUrl: $webinar->join_url,
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

        return [
            'contact' => $this->contact->toArray(),
            'webinar_registration' => $this->registration?->toArray() ?? [],
            'webinar_waitlist_signup' => $this->waitlistSignup?->toArray() ?? [],
            'webinar' => $this->webinar->toArray(),
            'webinar_series' => $webinarSeries?->toArray() ?? [],

            'registration_id' => $this->registration?->getKey(),
            'waitlist_signup_id' => $this->waitlistSignup?->getKey(),

            'contact_id' => $this->contact->getKey(),
            'contact_first_name' => $this->contact->first_name ?? 'there',
            'contact_last_name' => $this->contact->last_name,
            'contact_full_name' => $this->contact->name,
            'contact_email' => $this->contact->email,
            'contact_phone' => $this->contact->phone,

            'first_name' => $this->contact->first_name ?? 'there',
            'last_name' => $this->contact->last_name,
            'full_name' => $this->contact->name,
            'email' => $this->contact->email,
            'phone' => $this->contact->phone,

            'webinar_id' => $this->webinar->getKey(),
            'webinar_slug' => $this->webinar->slug,
            'webinar_title' => $this->webinar->title,
            'webinar_description' => $this->webinar->description,
            'webinar_status' => $this->webinar->status,
            'webinar_timezone' => $timezone,
            'webinar_platform' => $this->webinar->platform,
            'webinar_join_url' => $this->webinarJoinUrl,
            'webinar_registration_url' => $this->webinar->registration_url,

            'webinar_starts_at' => $startsAt?->toIso8601String(),
            'webinar_ends_at' => $endsAt?->toIso8601String(),
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

            'event_id' => $this->webinar->getKey(),
            'event_slug' => $this->webinar->slug,
            'event_title' => $this->webinar->title,
            'event_starts_at' => $startsAt?->toIso8601String(),
            'event_ends_at' => $endsAt?->toIso8601String(),
            'event_start_date' => $this->formatDate($startsAt, $timezone),
            'event_start_time' => $this->formatTime($startsAt, $timezone),
            'event_start_datetime' => $this->formatDateTime($startsAt, $timezone),
            'event_join_url' => $this->webinarJoinUrl,
            'event_registration_url' => $this->webinar->registration_url,

            'join_url' => $this->webinarJoinUrl,
            'registration_url' => $this->webinar->registration_url,
            'request_ip' => $this->requestIp,
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
            webinarJoinUrl: $data['webinar_join_url'] ?? $data['join_url'] ?? null,
            requestIp: $data['request_ip'] ?? null,
        );
    }

    public function formattedStart(string $format = 'M j g:i A'): string
    {
        return $this->webinar->starts_at
            ? $this->webinar->starts_at->copy()->setTimezone($this->webinar->timezone ?: config('app.timezone'))->format($format)
            : '';
    }

    public function contact(): Contact
    {
        return $this->contact;
    }

    private function formatDate(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('F j, Y');
    }

    private function formatTime(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('g:i A T');
    }

    private function formatDateTime(?CarbonInterface $date, string $timezone): ?string
    {
        return $date?->copy()->setTimezone($timezone)->format('F j, Y \a\t g:i A T');
    }
}
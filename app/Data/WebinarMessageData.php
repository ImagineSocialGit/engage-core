<?php

namespace App\Data;

use App\Models\WebinarRegistration;
use Carbon\Carbon;
use Carbon\CarbonInterface;

readonly class WebinarMessageData
{
    public function __construct(
        public int $registrationId,
        public int $leadId,
        public string $leadFirstName,
        public ?string $leadEmail,
        public ?string $leadPhone,
        public int $webinarId,
        public string $webinarSlug,
        public string $webinarTitle,
        public CarbonInterface $webinarStartsAt,
        public string $webinarTimezone,
        public string $webinarJoinUrl,
        public string $webinarPlatform,
    ) {}

    public static function fromRegistration(WebinarRegistration $registration): self
    {
        $registration->loadMissing(['lead', 'webinar']);

        $lead = $registration->lead;
        $webinar = $registration->webinar;

        return new self(
            registrationId: $registration->id,
            leadId: $lead?->id ?? 0,
            leadFirstName: $registration->first_name
                ?? $lead?->first_name
                ?? 'there',
            leadEmail: $registration->email
                ?? $lead?->email,
            leadPhone: $registration->phone
                ?? $lead?->phone,
            webinarId: $webinar->id,
            webinarSlug: $webinar->slug,
            webinarTitle: $webinar->title,
            webinarStartsAt: $webinar->starts_at,
            webinarTimezone: $webinar->timezone ?: config('app.timezone', 'America/Chicago'),
            webinarJoinUrl: $webinar->join_url,
            webinarPlatform: $webinar->platform,
        );
    }

    public function toArray(): array
    {
        return [
            'registration_id' => $this->registrationId,
            'lead_id' => $this->leadId,
            'lead_first_name' => $this->leadFirstName,
            'lead_email' => $this->leadEmail,
            'lead_phone' => $this->leadPhone,
            'webinar_id' => $this->webinarId,
            'webinar_slug' => $this->webinarSlug,
            'webinar_title' => $this->webinarTitle,
            'webinar_starts_at' => $this->webinarStartsAt->toIso8601String(),
            'webinar_timezone' => $this->webinarTimezone,
            'webinar_join_url' => $this->webinarJoinUrl,
            'webinar_platform' => $this->webinarPlatform,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            registrationId: $data['registration_id'],
            leadId: $data['lead_id'],
            leadFirstName: $data['lead_first_name'],
            leadEmail: $data['lead_email'],
            leadPhone: $data['lead_phone'],
            webinarId: $data['webinar_id'],
            webinarSlug: $data['webinar_slug'],
            webinarTitle: $data['webinar_title'],
            webinarStartsAt: Carbon::parse($data['webinar_starts_at']),
            webinarTimezone: $data['webinar_timezone'],
            webinarJoinUrl: $data['webinar_join_url'],
            webinarPlatform: $data['webinar_platform'],
        );
    }

    public function formattedStart(string $format = 'M j g:i A'): string
    {
        return $this->webinarStartsAt
            ->copy()
            ->setTimezone($this->webinarTimezone)
            ->format($format);
    }
}
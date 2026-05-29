<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\EmailMessagePayload;
use App\Mail\Webinars\WebinarWaitlistScheduledMail;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;

class WebinarWaitlistScheduledEmailPayload implements EmailMessagePayload
{
    public function __construct(
        public string $email,
        public string $webinarTitle,
        public string $registrationUrl,
        public ?int $signupId = null,
        public ?int $webinarId = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            email: $payload['email'],
            webinarTitle: $payload['webinar_title'],
            registrationUrl: $payload['registration_url'],
            signupId: $payload['signup_id'] ?? null,
            webinarId: $payload['webinar_id'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->email;
    }

    public function mailable(): Mailable
    {
        return new WebinarWaitlistScheduledMail(
            webinarTitle: $this->webinarTitle,
            registrationUrl: $this->registrationUrl,
        );
    }

    public function devPayload(): array
    {
        return [
            'kind' => 'webinar_waitlist_scheduled',
            'email' => $this->email,
            'signup_id' => $this->signupId,
            'webinar_id' => $this->webinarId,
            'webinar_title' => $this->webinarTitle,
            'registration_url' => $this->registrationUrl,
            'subject' => 'New webinar scheduled: '.$this->webinarTitle,
        ];
    }
}
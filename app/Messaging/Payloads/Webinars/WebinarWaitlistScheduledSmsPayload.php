<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\SmsMessagePayload;

class WebinarWaitlistScheduledSmsPayload implements SmsMessagePayload
{
    public function __construct(
        public string $phone,
        public string $webinarTitle,
        public string $registrationUrl,
        public ?int $signupId = null,
        public ?int $webinarId = null,
        public ?string $sourceIp = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            phone: $payload['phone'],
            webinarTitle: $payload['webinar_title'],
            registrationUrl: $payload['registration_url'],
            signupId: $payload['signup_id'] ?? null,
            webinarId: $payload['webinar_id'] ?? null,
            sourceIp: $payload['source_ip'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->phone;
    }

    public function message(): string
    {
        return sprintf(
            'A new webinar has been scheduled for %s. Register here: %s',
            $this->webinarTitle,
            $this->registrationUrl
        );
    }

    public function kind(): string
    {
        return 'webinar_waitlist_scheduled';
    }

    public function devPayload(): array
    {
        return [
            'kind' => $this->kind(),
            'phone' => $this->phone,
            'signup_id' => $this->signupId,
            'webinar_id' => $this->webinarId,
            'source_ip' => $this->sourceIp,
            'webinar_title' => $this->webinarTitle,
            'registration_url' => $this->registrationUrl,
            'message' => $this->message(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }
}
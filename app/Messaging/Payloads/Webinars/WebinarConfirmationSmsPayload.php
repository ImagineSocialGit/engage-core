<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\SmsMessagePayload;
use App\Data\WebinarMessageData;

class WebinarConfirmationSmsPayload implements SmsMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
        );
    }

    public function to(): string
    {
        return $this->data->leadPhone;
    }

    public function message(): string
    {
        return sprintf(
            "You're registered for %s on %s. Join here: %s",
            $this->data->webinarTitle,
            $this->data->formattedStart('M j g:i A'),
            $this->data->webinarJoinUrl
        );
    }

    public function kind(): string
    {
        return 'registration_confirmation';
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => $this->kind(),
            'message' => $this->message(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->data->requestIp;
    }
}
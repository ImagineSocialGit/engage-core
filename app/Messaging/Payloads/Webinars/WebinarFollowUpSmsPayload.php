<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\SmsMessagePayload;
use App\Data\WebinarMessageData;
use InvalidArgumentException;

class WebinarFollowUpSmsPayload implements SmsMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
        public string $followUpType,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
            followUpType: $payload['follow_up_type'],
        );
    }

    public function to(): string
    {
        return $this->data->leadPhone;
    }

    public function message(): string
    {
        return match ($this->followUpType) {
            'missed' => sprintf(
                "Sorry we missed you for %s. We'll follow up with next steps soon.",
                $this->data->webinarTitle
            ),
            'replay' => sprintf(
                "Thanks for joining %s. We'll send your replay and next steps soon.",
                $this->data->webinarTitle
            ),
            default => throw new InvalidArgumentException("Unsupported webinar follow-up type [{$this->followUpType}]."),
        };
    }

    public function kind(): string
    {
        return 'post_webinar_follow_up';
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => $this->kind(),
            'follow_up_type' => $this->followUpType,
            'message' => $this->message(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->data->requestIp;
    }
}
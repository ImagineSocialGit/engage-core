<?php

namespace App\Messaging\Payloads\Webinars;

use App\Contracts\Messaging\SmsMessagePayload;
use App\Data\WebinarMessageData;
use InvalidArgumentException;

class WebinarReminderSmsPayload implements SmsMessagePayload
{
    public function __construct(
        public WebinarMessageData $data,
        public string $messageType,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            data: WebinarMessageData::fromArray($payload),
            messageType: $payload['message_type'],
        );
    }

    public function to(): string
    {
        return $this->data->leadPhone;
    }

    public function message(): string
    {
        return match ($this->messageType) {
            'reminder_10d' => sprintf(
                '%s is 10 days away on %s. Join here: %s',
                $this->data->webinarTitle,
                $this->data->webinarTitle,
                $this->data->formattedStart('M j g:i A'),
                $this->data->webinarJoinUrl
            ),
            'reminder_7d' => sprintf(
                '%s is 1 week away. It starts %s. Join here: %s',
                $this->data->webinarTitle,
                $this->data->formattedStart('M j g:i A'),
                $this->data->webinarJoinUrl
            ),
            'reminder_24h' => sprintf(
                'Reminder: %s is tomorrow at %s. Join here: %s',
                $this->data->webinarTitle,
                $this->data->formattedStart('M j g:i A'),
                $this->data->webinarJoinUrl
            ),
            'reminder_30m' => sprintf(
                '%s starts in 30 minutes at %s. Join here: %s',
                $this->data->webinarTitle,
                $this->data->formattedStart('g:i A'),
                $this->data->webinarJoinUrl
            ),
            'reminder_10m' => sprintf(
                '%s starts in 10 minutes. Join here: %s',
                $this->data->webinarTitle,
                $this->data->webinarJoinUrl
            ),
            'late_joiner_5m' => sprintf(
                '%s is live now. Join here: %s',
                $this->data->webinarTitle,
                $this->data->webinarJoinUrl
            ),
            default => throw new InvalidArgumentException("Unsupported webinar reminder type [{$this->messageType}]."),
        };
    }

    public function kind(): string
    {
        return 'reminder';
    }

    public function devPayload(): array
    {
        return [
            ...$this->data->toArray(),
            'kind' => $this->kind(),
            'message_type' => $this->messageType,
            'message' => $this->message(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->data->requestIp;
    }
}
<?php

namespace App\Messaging\Payloads\Webinars\Sms;

use App\Contracts\Messaging\Sms\SmsMessage;
use App\Data\WebinarMessageData;
use InvalidArgumentException;

class WebinarTransactionalSmsPayload implements SmsMessage
{
    public function __construct(
        public WebinarMessageData $data,
        public string $messageType,
    ) {}

    public static function fromArray(array $payload): self
    {
        $messageType = $payload['message_type'] ?? null;

        if (! is_string($messageType) || trim($messageType) === '') {
            throw new InvalidArgumentException('Webinar transactional SMS payload requires a message_type.');
        }

        return new self(
            data: WebinarMessageData::fromArray($payload),
            messageType: $messageType,
        );
    }

    public function to(): string
    {
        return (string) $this->data->contactPhone;
    }

    public function message(): string
    {
        $body = match ($this->messageType) {
            'webinar_transactional_opt_in' => config('messaging.sms.opt-in.webinar_transactional.message'),
            default => null,
        };

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException("SMS message body is not configured for [{$this->messageType}].");
        }

        return trim(config('brand.name', config('app.name')).': '.trim($body));
    }

    public function kind(): string
    {
        return $this->messageType;
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
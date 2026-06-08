<?php

namespace App\Messaging\Payloads\Marketing\Sms;

use App\Contracts\Messaging\Sms\SmsMessage;
use InvalidArgumentException;

class MarketingSmsPayload implements SmsMessage
{
    public function __construct(
        public string $to,
        public string $messageType,
        public ?string $sourceIp = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        $to = $payload['to'] ?? $payload['phone'] ?? $payload['contact_phone'] ?? null;
        $messageType = $payload['message_type'] ?? null;

        if (! is_string($to) || trim($to) === '') {
            throw new InvalidArgumentException('Marketing SMS payload requires a destination phone number.');
        }

        if (! is_string($messageType) || trim($messageType) === '') {
            throw new InvalidArgumentException('Marketing SMS payload requires a message_type.');
        }

        return new self(
            to: $to,
            messageType: $messageType,
            sourceIp: $payload['source_ip'] ?? $payload['request_ip'] ?? null,
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function message(): string
    {
        $body = config("messaging.sms.general_drip.opt_in.payload.message");

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
            'to' => $this->to(),
            'kind' => $this->kind(),
            'message_type' => $this->messageType,
            'message' => $this->message(),
            'source_ip' => $this->sourceIp(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }
}
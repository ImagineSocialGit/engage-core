<?php

namespace App\Modules\Messaging\Payloads\Internal;

use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use InvalidArgumentException;

class InternalSmsNotificationPayload implements SmsMessage
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $to,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly string $message,
        public readonly ?string $notificationType = null,
        public readonly ?string $sourceIp = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            to: self::requiredString(
                $payload['to']
                    ?? $payload['phone']
                    ?? null,
                'to',
            ),
            channel: self::nullableString($payload['channel'] ?? null) ?? 'sms',
            purpose: self::nullableString($payload['purpose'] ?? null) ?? 'internal',
            scope: self::requiredString($payload['scope'] ?? null, 'scope'),
            messageType: self::requiredString($payload['message_type'] ?? null, 'message_type'),
            message: self::requiredString(
                $payload['sms_message']
                    ?? $payload['message']
                    ?? null,
                'message',
            ),
            notificationType: self::nullableString($payload['notification_type'] ?? null),
            sourceIp: self::nullableString(
                $payload['source_ip']
                    ?? $payload['request_ip']
                    ?? null,
            ),
            meta: self::arrayValue($payload['meta'] ?? []),
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function kind(): string
    {
        return $this->messageType;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'message_type' => $this->messageType,
            'notification_type' => $this->notificationType,
            'message' => $this->message,
            'meta' => $this->meta,
            'source_ip' => $this->sourceIp(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }

    private static function requiredString(mixed $value, string $key): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Internal SMS notification payload requires [{$key}].");
        }

        return trim($value);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
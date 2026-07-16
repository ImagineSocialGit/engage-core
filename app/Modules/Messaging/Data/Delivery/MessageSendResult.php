<?php

namespace App\Modules\Messaging\Data\Delivery;

final readonly class MessageSendResult
{
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $status,
        public ?string $reasonCode = null,
        public ?string $reason = null,
        public ?string $provider = null,
        public ?string $providerMessageId = null,
        public bool $retryable = false,
        public array $meta = [],
    ) {}

    /** @param array<string, mixed> $meta */
    public static function sent(
        ?string $provider = null,
        ?string $providerMessageId = null,
        array $meta = [],
    ): self {
        return new self(
            status: self::STATUS_SENT,
            provider: $provider,
            providerMessageId: $providerMessageId,
            meta: $meta,
        );
    }

    /** @param array<string, mixed> $meta */
    public static function skipped(
        string $reasonCode,
        string $reason,
        ?string $provider = null,
        array $meta = [],
    ): self {
        return new self(
            status: self::STATUS_SKIPPED,
            reasonCode: $reasonCode,
            reason: $reason,
            provider: $provider,
            meta: $meta,
        );
    }

    /** @param array<string, mixed> $meta */
    public static function failed(
        string $reasonCode,
        string $reason,
        bool $retryable = false,
        ?string $provider = null,
        array $meta = [],
    ): self {
        return new self(
            status: self::STATUS_FAILED,
            reasonCode: $reasonCode,
            reason: $reason,
            provider: $provider,
            retryable: $retryable,
            meta: $meta,
        );
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /** @return array<string, mixed> */
    public function toMeta(): array
    {
        return array_filter([
            'status' => $this->status,
            'reason_code' => $this->reasonCode,
            'reason' => $this->reason,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'retryable' => $this->retryable,
            'meta' => $this->meta !== [] ? $this->meta : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

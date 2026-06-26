<?php

namespace App\Modules\FlowRoutes\Data;

class PointExecutionResult
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_WAITING,
        self::STATUS_BLOCKED,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
    ];

    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $meta
     */
    public static function completed(?string $reason = null, array $meta = []): self
    {
        return new self(
            status: self::STATUS_COMPLETED,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function waiting(?string $reason = null, array $meta = []): self
    {
        return new self(
            status: self::STATUS_WAITING,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function blocked(?string $reason = null, array $meta = []): self
    {
        return new self(
            status: self::STATUS_BLOCKED,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function skipped(?string $reason = null, array $meta = []): self
    {
        return new self(
            status: self::STATUS_SKIPPED,
            reason: $reason,
            meta: $meta,
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failed(?string $reason = null, array $meta = []): self
    {
        return new self(
            status: self::STATUS_FAILED,
            reason: $reason,
            meta: $meta,
        );
    }

    public function shouldAdvance(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_SKIPPED,
        ], true);
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'meta' => $this->meta,
        ];
    }
}
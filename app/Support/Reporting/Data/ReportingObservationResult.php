<?php

namespace App\Support\Reporting\Data;

use InvalidArgumentException;

final readonly class ReportingObservationResult
{
    public const STATUS_RECORDED = 'recorded';
    public const STATUS_DEDUPLICATED = 'deduplicated';
    public const STATUS_DISABLED = 'disabled';

    public const STATUSES = [
        self::STATUS_RECORDED,
        self::STATUS_DEDUPLICATED,
        self::STATUS_DISABLED,
    ];

    public function __construct(
        public string $status,
        public string $eventId,
        public ?int $observationId = null,
        public ?int $sessionId = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported Reporting observation result status [{$status}].");
        }
    }

    public static function recorded(string $eventId, int $observationId, ?int $sessionId): self
    {
        return new self(
            status: self::STATUS_RECORDED,
            eventId: $eventId,
            observationId: $observationId,
            sessionId: $sessionId,
        );
    }

    public static function deduplicated(string $eventId, int $observationId, ?int $sessionId): self
    {
        return new self(
            status: self::STATUS_DEDUPLICATED,
            eventId: $eventId,
            observationId: $observationId,
            sessionId: $sessionId,
        );
    }

    public static function disabled(string $eventId): self
    {
        return new self(
            status: self::STATUS_DISABLED,
            eventId: $eventId,
        );
    }
}
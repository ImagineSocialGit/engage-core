<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarFollowUpDispatchResult
{
    public const STATUS_NOT_APPLICABLE = 'not_applicable';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_ALREADY_SCHEDULED = 'already_scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAILED = 'failed';

    /** @param array<int, int> $scheduledMessageIds */
    public function __construct(
        public string $status,
        public int $registrationId,
        public string $outcome,
        public array $scheduledMessageIds = [],
        public ?string $reason = null,
    ) {}

    public function complete(): bool
    {
        return in_array($this->status, [
            self::STATUS_NOT_APPLICABLE,
            self::STATUS_SCHEDULED,
            self::STATUS_ALREADY_SCHEDULED,
        ], true);
    }

    public function shouldRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_IN_PROGRESS,
            self::STATUS_FAILED,
        ], true);
    }
}
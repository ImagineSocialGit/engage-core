<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarRegistrationFinalizationResult
{
    public const META_KEY = 'registration_finalization';

    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_ALREADY_COMPLETED = 'already_completed';
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public function __construct(
        public string $status,
        public int $registrationId,
        public ?string $reason = null,
    ) {}

    public function complete(): bool
    {
        return in_array($this->status, [
            self::STATUS_NOT_REQUIRED,
            self::STATUS_COMPLETED,
            self::STATUS_ALREADY_COMPLETED,
        ], true);
    }

    public function shouldRetry(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function inProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function requiresReconciliation(): bool
    {
        return $this->status === self::STATUS_RECONCILIATION_REQUIRED;
    }
}
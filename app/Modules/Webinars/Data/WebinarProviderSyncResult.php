<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarProviderSyncResult
{
    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_ALREADY_SUCCEEDED = 'already_succeeded';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_RETRYABLE_FAILURE = 'retryable_failure';
    public const STATUS_PERMANENT_FAILURE = 'permanent_failure';
    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public function __construct(
        public string $status,
        public ?string $provider = null,
        public ?string $reason = null,
    ) {}

    public function readyForRegistrationMessages(): bool
    {
        return in_array($this->status, [
            self::STATUS_NOT_REQUIRED,
            self::STATUS_SUCCEEDED,
            self::STATUS_ALREADY_SUCCEEDED,
        ], true);
    }

    public function shouldRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_RETRYABLE_FAILURE,
            self::STATUS_IN_PROGRESS,
        ], true);
    }

    public function permanentlyFailed(): bool
    {
        return $this->status === self::STATUS_PERMANENT_FAILURE;
    }

    public function requiresReconciliation(): bool
    {
        return $this->status === self::STATUS_RECONCILIATION_REQUIRED;
    }
}
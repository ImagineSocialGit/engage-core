<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarProviderSyncResult
{
    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_ALREADY_SUCCEEDED = 'already_succeeded';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?string $provider = null,
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
            self::STATUS_FAILED,
            self::STATUS_IN_PROGRESS,
        ], true);
    }
}

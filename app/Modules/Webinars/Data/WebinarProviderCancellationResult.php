<?php

namespace App\Modules\Webinars\Data;

final readonly class WebinarProviderCancellationResult
{
    public const STATUS_NOT_REQUIRED = 'not_required';
    public const STATUS_NOT_CANCELLED = 'not_cancelled';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_ALREADY_SUCCEEDED = 'already_succeeded';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $status,
        public ?string $provider = null,
    ) {}

    public function shouldRetry(): bool
    {
        return in_array($this->status, [
            self::STATUS_FAILED,
            self::STATUS_IN_PROGRESS,
        ], true);
    }
}

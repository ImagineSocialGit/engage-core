<?php

namespace App\Modules\Reporting\Data;

final class ScheduledReportRecipientOption
{
    public function __construct(
        public readonly string $key,
        public readonly string $recipientType,
        public readonly int $recipientId,
        public readonly string $label,
        public readonly ?string $email = null,
    ) {}
}
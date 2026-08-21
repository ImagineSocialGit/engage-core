<?php

namespace App\Modules\Forms\Data;

final readonly class FormSubmissionConsentIntent
{
    public function __construct(
        public string $field,
        public string $channel,
        public string $purpose,
    ) {}
}
<?php

namespace App\Modules\Forms\Data;

final readonly class NormalizedFormSubmission
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, NormalizedFormSubmissionValue>  $values
     */
    public function __construct(
        public array $payload,
        public array $values,
    ) {}
}
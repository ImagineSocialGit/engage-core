<?php

namespace App\Modules\Forms\Data;

final readonly class FormSubmissionResult
{
    public function __construct(
        public int $submissionId,
        public int $definitionId,
        public int $versionId,
        public int $versionNumber,
        public string $formKey,
        public ?int $contactId,
        public string $status,
        public ?string $submittedAt,
        public bool $replayed,
    ) {}
}
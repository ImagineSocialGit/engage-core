<?php

namespace App\Modules\Core\Data\Contacts;

class ContactImportStatusMappingResult
{
    public function __construct(
        public ?string $originalStatus,
        public string $state,
        public ?int $contactStatusId = null,
        public ?string $contactStatusName = null,
    ) {}

    public static function missing(): self
    {
        return new self(
            originalStatus: null,
            state: 'missing',
        );
    }

    public static function unmapped(string $originalStatus): self
    {
        return new self(
            originalStatus: $originalStatus,
            state: 'unmapped',
        );
    }

    public static function mapped(
        string $originalStatus,
        int $contactStatusId,
        string $contactStatusName,
    ): self {
        return new self(
            originalStatus: $originalStatus,
            state: 'mapped',
            contactStatusId: $contactStatusId,
            contactStatusName: $contactStatusName,
        );
    }

    public function toMeta(): array
    {
        return array_filter([
            'state' => $this->state,
            'original_status' => $this->originalStatus,
            'contact_status_id' => $this->contactStatusId,
            'contact_status_name' => $this->contactStatusName,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function isMapped(): bool
    {
        return $this->state === 'mapped' && $this->contactStatusId !== null;
    }

    public function requiresReview(): bool
    {
        return $this->state === 'unmapped';
    }
}
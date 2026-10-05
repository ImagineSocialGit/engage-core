<?php

namespace App\Support\ModuleIntegrations\Documents\Data;

use InvalidArgumentException;

final readonly class DocumentRequirementDefinitionContribution
{
    /**
     * @param array<int, string>|null $acceptedMimeTypes
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public string $key,
        public string $name,
        public ?string $description = null,
        public ?string $instructions = null,
        public ?string $category = null,
        public bool $isRequiredByDefault = false,
        public bool $allowsMultipleUploads = false,
        public bool $requiresReview = true,
        public ?array $acceptedMimeTypes = null,
        public ?int $maxFileSizeKb = null,
        public ?int $expiresAfterDays = null,
        public int $sortOrder = 0,
        public array $settings = [],
        public array $meta = [],
    ) {
        if (trim($this->key) === ''
            || mb_strlen($this->key) > 255
            || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $this->key) !== 1
        ) {
            throw new InvalidArgumentException(
                'Document requirement contribution keys must be lowercase letters, numbers, dashes, or underscores and may not exceed 255 characters.',
            );
        }

        if (trim($this->name) === '' || mb_strlen($this->name) > 255) {
            throw new InvalidArgumentException(
                'Document requirement contribution names must be 1-255 characters.',
            );
        }

        if ($this->maxFileSizeKb !== null && $this->maxFileSizeKb < 1) {
            throw new InvalidArgumentException(
                'Document requirement max file size must be positive when provided.',
            );
        }

        if ($this->expiresAfterDays !== null && $this->expiresAfterDays < 1) {
            throw new InvalidArgumentException(
                'Document requirement expiration days must be positive when provided.',
            );
        }

        if ($this->sortOrder < 0) {
            throw new InvalidArgumentException(
                'Document requirement sort order may not be negative.',
            );
        }

        if ($this->acceptedMimeTypes !== null) {
            foreach ($this->acceptedMimeTypes as $mimeType) {
                if (! is_string($mimeType) || trim($mimeType) === '') {
                    throw new InvalidArgumentException(
                        'Document requirement accepted MIME types must be non-empty strings.',
                    );
                }
            }
        }
    }
}
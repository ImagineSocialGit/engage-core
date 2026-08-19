<?php

namespace App\Modules\Core\Data\Contacts;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;

final class ContactImportContext
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $mapping
     * @param array<string, string> $defaults
     */
    public function __construct(
        public readonly Contact $contact,
        public readonly ContactImportBatch $batch,
        public readonly ContactImportOccurrence $occurrence,
        public readonly array $row,
        public readonly array $mapping,
        public readonly array $defaults = [],
    ) {}

    public function mappedValue(string $field): ?string
    {
        $header = $this->mapping[$field] ?? null;

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $value = $this->row[$header] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }


    public function value(string $field): ?string
    {
        return $this->mappedValue($field)
            ?? $this->defaultValue($field);
    }

    public function defaultValue(string $field): ?string
    {
        $value = $this->defaults[$field] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int, string> $fields
     */
    public function hasAnyMappedValue(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->mappedValue($field) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $fields
     */
    public function hasAnyValue(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->value($field) !== null) {
                return true;
            }
        }

        return false;
    }
}
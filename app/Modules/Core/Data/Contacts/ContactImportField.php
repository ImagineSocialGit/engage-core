<?php

namespace App\Modules\Core\Data\Contacts;

class ContactImportField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly string $section = 'Contact Fields',
        public readonly ?string $contactAttribute = null,
        public readonly ?string $description = null,
        public readonly int $sort = 100,
    ) {}

    public static function make(
        string $key,
        string $label,
        bool $required = false,
        string $section = 'Contact Fields',
        ?string $contactAttribute = null,
        ?string $description = null,
        int $sort = 100,
    ): self {
        return new self(
            key: $key,
            label: $label,
            required: $required,
            section: $section,
            contactAttribute: $contactAttribute,
            description: $description,
            sort: $sort,
        );
    }
}
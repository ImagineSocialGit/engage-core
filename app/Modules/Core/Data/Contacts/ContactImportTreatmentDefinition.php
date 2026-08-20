<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactImportTreatmentDefinition
{
    /**
     * @param array<int, array{value: string, label: string}> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $section,
        public ?string $description = null,
        public bool $multiple = false,
        public bool $allowCustom = false,
        public array $options = [],
        public int $sort = 0,
    ) {}
}
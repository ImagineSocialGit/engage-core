<?php

namespace App\Modules\Core\Data\Contacts;

final readonly class ContactImportTreatmentSelection
{
    public const MODE_FIXED = 'fixed';
    public const MODE_COLUMN = 'column';

    /**
     * @param array<int, string> $fixedValues
     * @param array<string, array<int, string>> $valueMap
     */
    public function __construct(
        public string $targetKey,
        public string $mode,
        public ?string $sourceColumn,
        public array $fixedValues,
        public array $valueMap,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return [
            'mode' => $this->mode,
            'source_column' => $this->sourceColumn,
            'fixed_values' => $this->fixedValues,
            'value_map' => $this->valueMap,
        ];
    }
}
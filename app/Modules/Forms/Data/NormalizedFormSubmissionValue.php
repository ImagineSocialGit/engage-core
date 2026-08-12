<?php

namespace App\Modules\Forms\Data;

final readonly class NormalizedFormSubmissionValue
{
    public function __construct(
        public string $fieldKey,
        public string $fieldLabel,
        public string $fieldType,
        public mixed $value,
        public int $sortOrder,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function persistenceAttributes(): array
    {
        return [
            'field_key' => $this->fieldKey,
            'field_label' => $this->fieldLabel,
            'field_type' => $this->fieldType,
            'value' => $this->value,
            'value_text' => $this->textValue(),
            'value_number' => $this->fieldType === 'number' ? $this->value : null,
            'value_boolean' => in_array($this->fieldType, ['checkbox', 'boolean'], true)
                ? $this->value
                : null,
            'value_date' => $this->fieldType === 'date' ? $this->value : null,
            'value_datetime' => $this->fieldType === 'datetime' ? $this->value : null,
            'sort_order' => $this->sortOrder,
            'meta' => null,
        ];
    }

    private function textValue(): ?string
    {
        if ($this->value === null) {
            return null;
        }

        if (is_bool($this->value)) {
            return $this->value ? 'true' : 'false';
        }

        if (is_array($this->value)) {
            if ($this->value === []) {
                return null;
            }

            return implode(', ', array_map(
                static fn (mixed $value): string => (string) $value,
                $this->value,
            ));
        }

        return (string) $this->value;
    }
}
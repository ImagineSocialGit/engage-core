<?php

namespace App\Modules\Forms\Data;

final readonly class PublishedForm
{
    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $settings
     * @param array<int, array<string, mixed>> $fields
     */
    public function __construct(
        public int $definitionId,
        public int $versionId,
        public int $versionNumber,
        public string $key,
        public string $name,
        public ?string $description,
        public string $category,
        public bool $isPublic,
        public array $schema,
        public array $rules,
        public array $layout,
        public array $settings,
        public array $fields,
    ) {}

    /**
     * @return array<int, string>
     */
    public function fieldKeys(): array
    {
        return array_values(array_map(
            static fn (array $field): string => (string) $field['key'],
            $this->fields,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function field(string $key): ?array
    {
        $key = trim($key);

        foreach ($this->fields as $field) {
            if (($field['key'] ?? null) === $key) {
                return $field;
            }
        }

        return null;
    }
}
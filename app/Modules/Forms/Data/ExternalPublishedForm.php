<?php

namespace App\Modules\Forms\Data;

final readonly class ExternalPublishedForm
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

    public static function fromPublishedForm(PublishedForm $form): self
    {
        $publicSettings = $form->settings['public'] ?? [];

        return new self(
            definitionId: $form->definitionId,
            versionId: $form->versionId,
            versionNumber: $form->versionNumber,
            key: $form->key,
            name: $form->name,
            description: $form->description,
            category: $form->category,
            isPublic: $form->isPublic,
            schema: $form->schema,
            rules: $form->rules,
            layout: $form->layout,
            settings: is_array($publicSettings) ? $publicSettings : [],
            fields: $form->fields,
        );
    }

    /**
     * @return array{
     *     definition_id: int,
     *     version_id: int,
     *     version_number: int,
     *     key: string,
     *     name: string,
     *     description: string|null,
     *     category: string,
     *     is_public: bool,
     *     schema: array<string, mixed>,
     *     rules: array<string, mixed>,
     *     layout: array<string, mixed>,
     *     settings: array<string, mixed>,
     *     fields: array<int, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'version_id' => $this->versionId,
            'version_number' => $this->versionNumber,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'is_public' => $this->isPublic,
            'schema' => $this->schema,
            'rules' => $this->rules,
            'layout' => $this->layout,
            'settings' => $this->settings,
            'fields' => $this->fields,
        ];
    }
}
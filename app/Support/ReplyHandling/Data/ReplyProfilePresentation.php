<?php

namespace App\Support\ReplyHandling\Data;

final readonly class ReplyProfilePresentation
{
    /**
     * @param array<int, array<string, mixed>> $intents
     * @param array<int, array<string, mixed>> $dependencies
     */
    public function __construct(
        public string $key,
        public string $label,
        public ?string $description,
        public bool $active,
        public array $intents,
        public array $dependencies,
        public ?string $updateUrl,
        public ?string $detailsUrl,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'active' => $this->active,
            'intents' => $this->intents,
            'dependencies' => $this->dependencies,
            'update_action' => $this->updateUrl,
            'details_url' => $this->detailsUrl,
        ];
    }
}
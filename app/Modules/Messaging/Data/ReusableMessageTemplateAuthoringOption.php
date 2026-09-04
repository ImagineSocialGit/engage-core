<?php

namespace App\Modules\Messaging\Data;

final readonly class ReusableMessageTemplateAuthoringOption
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $channel,
        public ReusableMessageTemplateAuthoringContext $context,
        public ?string $namePlaceholder = null,
        public int $order = 1000,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'channel' => $this->channel,
            'purpose' => $this->context->purpose,
            'scope' => $this->context->scope,
            'module_key' => $this->context->moduleKey,
            'module_label' => $this->context->moduleLabel,
            'group_label' => $this->context->groupLabel,
            'name_placeholder' => $this->namePlaceholder,
        ];
    }
}
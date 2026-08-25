<?php

namespace App\Support\ReplyHandling\Data;

final class ReplyProfileDependency
{
    public function __construct(
        public readonly string $key,
        public readonly string $profileKey,
        public readonly ?string $intentKey,
        public readonly string $moduleKey,
        public readonly string $type,
        public readonly string $label,
        public readonly string $detail,
        public readonly bool $active,
        public readonly bool $blocksChanges = true,
        public readonly ?string $url = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'profile_key' => $this->profileKey,
            'intent_key' => $this->intentKey,
            'module_key' => $this->moduleKey,
            'type' => $this->type,
            'label' => $this->label,
            'detail' => $this->detail,
            'active' => $this->active,
            'blocks_changes' => $this->blocksChanges,
            'url' => $this->url,
        ];
    }
}
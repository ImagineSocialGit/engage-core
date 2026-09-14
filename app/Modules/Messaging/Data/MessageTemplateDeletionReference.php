<?php

namespace App\Modules\Messaging\Data;

final readonly class MessageTemplateDeletionReference
{
    public function __construct(
        public string $moduleKey,
        public string $moduleLabel,
        public string $contextLabel,
        public string $detail,
        public ?string $url = null,
    ) {}

    public function summary(): string
    {
        return trim($this->moduleLabel.' — '.$this->contextLabel.': '.$this->detail);
    }

    public function fingerprint(): string
    {
        return implode('|', [
            $this->moduleKey,
            $this->contextLabel,
            $this->detail,
            $this->url ?? '',
        ]);
    }
}
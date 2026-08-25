<?php

namespace App\Modules\Messaging\Data;

final readonly class ReusableMessageTemplateAuthoringContext
{
    /**
     * @param array<int, string> $selectionContexts
     * @param array<string, mixed> $presetMeta
     * @param array<string, mixed> $catalogMeta
     */
    public function __construct(
        public string $contextKey,
        public string $purpose,
        public string $scope,
        public string $dispatchKey,
        public ?string $messageType,
        public string $payloadClass,
        public ?string $queue,
        public string $moduleKey,
        public string $moduleLabel,
        public string $surface,
        public string $groupKey,
        public string $groupLabel,
        public string $usageType,
        public array $selectionContexts = [],
        public ?string $description = null,
        public int $itemOrder = 1000,
        public ?string $contextType = null,
        public ?int $contextId = null,
        public array $presetMeta = [],
        public array $catalogMeta = [],
    ) {}
}
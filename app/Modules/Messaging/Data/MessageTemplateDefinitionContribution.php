<?php

namespace App\Modules\Messaging\Data;

final readonly class MessageTemplateDefinitionContribution
{
    /**
     * @param array<string, mixed> $definitions
     */
    public function __construct(
        public string $channel,
        public string $purpose,
        public string $scope,
        public array $definitions,
        public string $sourceConfigPath,
        public ?string $surface = null,
    ) {}
}